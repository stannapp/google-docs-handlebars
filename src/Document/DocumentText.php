<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Document;

use Stann\GoogleDocsTemplate\Exception\InvalidDocumentError;

/**
 * Linear view over a Google Docs document resource: the concatenated text of
 * every text run in the body, a byte-offset → document-index converter, the
 * geometry of every table row, and the plain text of the auxiliary surfaces
 * (headers, footers, footnotes).
 *
 * Google Docs indexes count UTF-16 code units while PHP strings are UTF-8:
 * all conversions go through u16len().
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final class DocumentText
{
    private string $text = '';

    /**
     * @var list<string>
     */
    private array $auxiliaryTexts = [];

    /**
     * @var list<array{byteStart: int, byteLength: int, docStart: int, docLength: int}>
     */
    private array $segments = [];

    /**
     * @var TableRowGeometry[]
     */
    private array $rows = [];

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(array $document)
    {
        $content = $document['body']['content'] ?? [];

        if (!is_array($content)) {
            throw new InvalidDocumentError('The document has no readable body.');
        }

        $tableOrdinal = 0;
        $this->walkContent($content, $tableOrdinal, recordRows: true);

        foreach (['headers', 'footers', 'footnotes'] as $kind) {
            $segments = $document[$kind] ?? [];

            foreach (is_array($segments) ? $segments : [] as $segment) {
                $segmentContent = is_array($segment) && is_array($segment['content'] ?? null) ? $segment['content'] : [];
                $text = $this->plainText($segmentContent);

                if (trim($text) !== '') {
                    $this->auxiliaryTexts[] = $text;
                }
            }
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Plain text of each header, footer and footnote. These surfaces are
     * replace-only: replaceAllText reaches them but structural edits cannot,
     * so only their text matters — no geometry is recorded.
     *
     * @return list<string>
     */
    public function auxiliaryTexts(): array
    {
        return $this->auxiliaryTexts;
    }

    /**
     * @return TableRowGeometry[]
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function rowContainingByte(int $byteOffset): ?TableRowGeometry
    {
        foreach ($this->rows as $row) {
            if ($row->containsByte($byteOffset)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return TableRowGeometry[]
     */
    public function rowsOfTable(int $tableOrdinal): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(TableRowGeometry $row) => $row->tableOrdinal === $tableOrdinal,
        ));
    }

    /**
     * Translates a byte offset in text() into a Google Docs index.
     */
    public function docIndexAtByte(int $byteOffset): int
    {
        // Segment ends touch the next segment's start (the text is gapless even
        // when doc indexes jump over structural markers): an offset sitting on
        // a boundary belongs to the segment that STARTS there.
        foreach ($this->segments as $segment) {
            if ($byteOffset >= $segment['byteStart'] && $byteOffset < $segment['byteStart'] + $segment['byteLength']) {
                return $segment['docStart'] + self::u16len(substr($this->text, $segment['byteStart'], $byteOffset - $segment['byteStart']));
            }
        }

        $last = $this->segments[count($this->segments) - 1] ?? null;

        if ($last !== null && $byteOffset === $last['byteStart'] + $last['byteLength']) {
            return $last['docStart'] + $last['docLength'];
        }

        throw new InvalidDocumentError(sprintf('Byte offset %d is outside every text segment.', $byteOffset));
    }

    /**
     * Length of a string in UTF-16 code units — the unit of Docs indexes.
     */
    public static function u16len(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @param array<mixed> $content
     */
    private function walkContent(array $content, int &$tableOrdinal, bool $recordRows): void
    {
        foreach ($content as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (isset($element['paragraph'])) {
                $this->appendParagraph($element['paragraph']);
                continue;
            }

            if (isset($element['table'])) {
                $this->appendTable($element, $tableOrdinal, $recordRows);

                if ($recordRows) {
                    ++$tableOrdinal;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $paragraph
     */
    private function appendParagraph(array $paragraph): void
    {
        foreach ($paragraph['elements'] ?? [] as $element) {
            $content = $element['textRun']['content'] ?? null;

            if (!is_string($content) || $content === '') {
                continue;
            }

            $this->segments[] = [
                'byteStart' => strlen($this->text),
                'byteLength' => strlen($content),
                'docStart' => (int) ($element['startIndex'] ?? 0),
                'docLength' => self::u16len($content),
            ];
            $this->text .= $content;
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function appendTable(array $element, int $tableOrdinal, bool $recordRows): void
    {
        $tableStartIndex = (int) ($element['startIndex'] ?? 0);

        foreach ($element['table']['tableRows'] ?? [] as $rowIndex => $tableRow) {
            $rowByteStart = strlen($this->text);
            $cells = [];

            foreach ($tableRow['tableCells'] ?? [] as $tableCell) {
                $cellByteStart = strlen($this->text);
                $cellContent = is_array($tableCell['content'] ?? null) ? $tableCell['content'] : [];

                // Nested tables inside cells keep contributing text so their
                // variables fill, but they are not loopable rows in v1.
                $nestedOrdinal = 0;
                $this->walkContent($cellContent, $nestedOrdinal, recordRows: false);

                $cells[] = new CellGeometry(
                    startIndex: (int) ($tableCell['startIndex'] ?? 0),
                    endIndex: (int) ($tableCell['endIndex'] ?? 0),
                    byteStart: $cellByteStart,
                    byteEnd: strlen($this->text),
                    text: substr($this->text, $cellByteStart),
                    firstTextStyle: $this->firstTextStyle($cellContent),
                    firstParagraphStyle: $this->firstParagraphStyle($cellContent),
                );
            }

            if ($recordRows) {
                $this->rows[] = new TableRowGeometry(
                    tableOrdinal: $tableOrdinal,
                    tableStartIndex: $tableStartIndex,
                    rowIndex: (int) $rowIndex,
                    rowStartIndex: (int) ($tableRow['startIndex'] ?? 0),
                    rowEndIndex: (int) ($tableRow['endIndex'] ?? 0),
                    byteStart: $rowByteStart,
                    byteEnd: strlen($this->text),
                    cells: $cells,
                );
            }
        }
    }

    /**
     * @param array<mixed> $content
     *
     * @return array<string, mixed>|null
     */
    private function firstTextStyle(array $content): ?array
    {
        foreach ($content as $element) {
            if (!is_array($element)) {
                continue;
            }

            foreach ($element['paragraph']['elements'] ?? [] as $paragraphElement) {
                if (isset($paragraphElement['textRun'])) {
                    $style = $paragraphElement['textRun']['textStyle'] ?? null;

                    return is_array($style) ? $style : null;
                }
            }
        }

        return null;
    }

    /**
     * Text-only walk for the auxiliary surfaces: no segments, no geometry.
     *
     * @param array<mixed> $content
     */
    private function plainText(array $content): string
    {
        $text = '';

        foreach ($content as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (isset($element['paragraph'])) {
                foreach ($element['paragraph']['elements'] ?? [] as $paragraphElement) {
                    $run = $paragraphElement['textRun']['content'] ?? null;

                    if (is_string($run)) {
                        $text .= $run;
                    }
                }

                continue;
            }

            foreach ($element['table']['tableRows'] ?? [] as $tableRow) {
                foreach ($tableRow['tableCells'] ?? [] as $tableCell) {
                    $text .= $this->plainText(is_array($tableCell['content'] ?? null) ? $tableCell['content'] : []);
                }
            }
        }

        return $text;
    }

    /**
     * @param array<mixed> $content
     *
     * @return array<string, mixed>|null
     */
    private function firstParagraphStyle(array $content): ?array
    {
        foreach ($content as $element) {
            if (is_array($element) && isset($element['paragraph'])) {
                $style = $element['paragraph']['paragraphStyle'] ?? null;

                return is_array($style) ? $style : null;
            }
        }

        return null;
    }
}
