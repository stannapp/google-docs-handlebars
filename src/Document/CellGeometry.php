<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Document;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class CellGeometry
{
    /**
     * @param array<string, mixed>|null $firstTextStyle      textStyle of the first text run of the cell
     * @param array<string, mixed>|null $firstParagraphStyle paragraphStyle of the first paragraph of the cell
     */
    public function __construct(
        // Google Docs indexes (UTF-16 code units) of the cell element.
        public int $startIndex,
        public int $endIndex,
        // Byte range of the cell's text inside DocumentText::text().
        public int $byteStart,
        public int $byteEnd,
        // Extracted cell text, trailing paragraph newline included.
        public string $text,
        public ?array $firstTextStyle,
        public ?array $firstParagraphStyle,
    ) {}

    /**
     * Docs index where text can be inserted: the cell element consumes one
     * index of its own before its first paragraph starts.
     */
    public function firstContentIndex(): int
    {
        return $this->startIndex + 1;
    }
}
