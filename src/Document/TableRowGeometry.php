<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Document;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class TableRowGeometry
{
    /**
     * @param CellGeometry[] $cells
     */
    public function __construct(
        // Ordinal of the table in reading order: stays valid across edits that
        // neither add nor remove tables, unlike raw indexes.
        public int $tableOrdinal,
        public int $tableStartIndex,
        public int $rowIndex,
        public int $rowStartIndex,
        public int $rowEndIndex,
        public int $byteStart,
        public int $byteEnd,
        public array $cells,
    ) {}

    public function containsByte(int $offset): bool
    {
        return $offset >= $this->byteStart && $offset < $this->byteEnd;
    }

    public function cellContainingByte(int $offset): ?CellGeometry
    {
        foreach ($this->cells as $cell) {
            if ($offset >= $cell->byteStart && $offset < $cell->byteEnd) {
                return $cell;
            }
        }

        return null;
    }

    public function isSameRow(self $other): bool
    {
        return $this->tableOrdinal === $other->tableOrdinal && $this->rowIndex === $other->rowIndex;
    }
}
