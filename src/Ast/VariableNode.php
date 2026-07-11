<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Ast;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class VariableNode
{
    public function __construct(
        // Dotted path ("customer.name") or loop metadata ("@index").
        public string $path,
        // Exact source text, braces included, as it appears in the document.
        public string $raw,
        // Byte offsets in the extracted document text.
        public int $start,
        public int $end,
    ) {}
}
