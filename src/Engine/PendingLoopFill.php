<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Engine;

use Stann\GoogleDocsTemplate\Document\DocumentText;
use Stann\GoogleDocsTemplate\Exception\TemplateStructureError;

/**
 * Deferred work of an expanded {{#each}}: the copy rows are inserted empty in
 * the structural phase, so their cell texts can only be written after a fresh
 * read of the document (indexes have shifted).
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class PendingLoopFill
{
    /**
     * @param list<list<string>>              $copyCellTexts  one entry per copy row (items 1..N-1), each a per-cell rendered text
     * @param list<array<string, mixed>|null> $cellTextStyles per cell, the writable text style copied from the template row
     * @param list<string|null>               $cellAlignments per cell, the paragraph alignment copied from the template row
     */
    public function __construct(
        public int $tableOrdinal,
        public int $templateRowIndex,
        public array $copyCellTexts,
        public array $cellTextStyles,
        public array $cellAlignments,
    ) {}

    /**
     * Builds the copy-row cell texts once the empty rows exist (fresh read).
     *
     * @return list<array<string, mixed>>
     */
    public function requestsFor(DocumentText $freshDocument): array
    {
        $rows = $freshDocument->rowsOfTable($this->tableOrdinal);
        $requests = [];

        // Bottom-up: fill the deepest copy first so cell indexes stay valid.
        for ($copy = count($this->copyCellTexts); $copy >= 1; --$copy) {
            $row = $rows[$this->templateRowIndex + $copy] ?? null;

            if ($row === null) {
                throw new TemplateStructureError('The inserted loop rows are missing from the refreshed document.');
            }

            $cellTexts = $this->copyCellTexts[$copy - 1];

            for ($cellIndex = count($row->cells) - 1; $cellIndex >= 0; --$cellIndex) {
                $text = $cellTexts[$cellIndex] ?? '';

                if ($text === '') {
                    continue;
                }

                $insertAt = $row->cells[$cellIndex]->firstContentIndex();
                $length = DocumentText::u16len($text);

                $requests[] = ['insertText' => ['location' => ['index' => $insertAt], 'text' => $text]];

                $style = $this->cellTextStyles[$cellIndex] ?? null;

                if ($style !== null && $style !== []) {
                    $requests[] = ['updateTextStyle' => [
                        'range' => ['startIndex' => $insertAt, 'endIndex' => $insertAt + $length],
                        'textStyle' => $style,
                        'fields' => implode(',', array_keys($style)),
                    ]];
                }

                $alignment = $this->cellAlignments[$cellIndex] ?? null;

                if ($alignment !== null) {
                    $requests[] = ['updateParagraphStyle' => [
                        'range' => ['startIndex' => $insertAt, 'endIndex' => $insertAt + $length],
                        'paragraphStyle' => ['alignment' => $alignment],
                        'fields' => 'alignment',
                    ]];
                }
            }
        }

        return $requests;
    }
}
