<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Testing;

use Stann\GoogleDocsTemplate\Document\DocumentText;

/**
 * Builds document resources shaped like GET docs/v1/documents/{id} responses,
 * with the same index arithmetic as the real API (UTF-16 units; tables, rows
 * and cells each consume one index before their content; headers, footers and
 * footnotes each live in their own index space).
 */
final class GoogleDocBuilder
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $content = [];

    /**
     * @var array<string, array<string, array<string, mixed>>> segment kind => id => segment
     */
    private array $auxiliaries = ['headers' => [], 'footers' => [], 'footnotes' => []];

    private int $cursor = 1;

    public static function create(): self
    {
        return new self();
    }

    public function paragraph(string $text): self
    {
        $this->content[] = $this->paragraphElement($text . "\n", [], []);

        return $this;
    }

    public function header(string $text): self
    {
        return $this->auxiliary('headers', $text);
    }

    public function footer(string $text): self
    {
        return $this->auxiliary('footers', $text);
    }

    public function footnote(string $text): self
    {
        return $this->auxiliary('footnotes', $text);
    }

    /**
     * @param list<list<string|array{text: string, textStyle?: array<string, mixed>, alignment?: string}>> $rows
     */
    public function table(array $rows): self
    {
        $tableStart = $this->cursor;
        ++$this->cursor;
        $tableRows = [];

        foreach ($rows as $row) {
            $rowStart = $this->cursor;
            ++$this->cursor;
            $cells = [];

            foreach ($row as $cellDefinition) {
                $definition = is_string($cellDefinition) ? ['text' => $cellDefinition] : $cellDefinition;
                $cellStart = $this->cursor;
                ++$this->cursor;

                $paragraph = $this->paragraphElement(
                    $definition['text'] . "\n",
                    $definition['textStyle'] ?? [],
                    isset($definition['alignment']) ? ['alignment' => $definition['alignment']] : [],
                );

                $cells[] = [
                    'startIndex' => $cellStart,
                    'endIndex' => $this->cursor,
                    'content' => [$paragraph],
                ];
            }

            $tableRows[] = [
                'startIndex' => $rowStart,
                'endIndex' => $this->cursor,
                'tableCells' => $cells,
            ];
        }

        ++$this->cursor;
        $this->content[] = [
            'startIndex' => $tableStart,
            'endIndex' => $this->cursor,
            'table' => ['tableRows' => $tableRows],
        ];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $document = ['body' => ['content' => $this->content]];

        foreach ($this->auxiliaries as $kind => $segments) {
            if ($segments !== []) {
                $document[$kind] = $segments;
            }
        }

        return $document;
    }

    private function auxiliary(string $kind, string $text): self
    {
        $id = sprintf('%s.%d', rtrim($kind, 's'), count($this->auxiliaries[$kind]));
        $content = $text . "\n";
        $length = DocumentText::u16len($content);

        // Each auxiliary segment starts its own index space at 0.
        $this->auxiliaries[$kind][$id] = [
            $kind === 'footnotes' ? 'footnoteId' : sprintf('%sId', rtrim($kind, 's')) => $id,
            'content' => [[
                'startIndex' => 0,
                'endIndex' => $length,
                'paragraph' => [
                    'elements' => [[
                        'startIndex' => 0,
                        'endIndex' => $length,
                        'textRun' => ['content' => $content, 'textStyle' => []],
                    ]],
                    'paragraphStyle' => [],
                ],
            ]],
        ];

        return $this;
    }

    /**
     * @param array<string, mixed> $textStyle
     * @param array<string, mixed> $paragraphStyle
     *
     * @return array<string, mixed>
     */
    private function paragraphElement(string $content, array $textStyle, array $paragraphStyle): array
    {
        $length = DocumentText::u16len($content);
        $element = [
            'startIndex' => $this->cursor,
            'endIndex' => $this->cursor + $length,
            'paragraph' => [
                'elements' => [[
                    'startIndex' => $this->cursor,
                    'endIndex' => $this->cursor + $length,
                    'textRun' => ['content' => $content, 'textStyle' => $textStyle],
                ]],
                'paragraphStyle' => $paragraphStyle,
            ],
        ];
        $this->cursor += $length;

        return $element;
    }
}
