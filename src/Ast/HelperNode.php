<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Ast;

/**
 * A helper call: {{name arg arg...}} where each argument is either a "quoted
 * literal" or a property path, in template order. Mirrors the Handlebars
 * helper syntax so templates stay portable.
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class HelperNode
{
    /**
     * @param list<array{literal: bool, value: string}> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
        // Exact source text, braces included, as it appears in the document.
        public string $raw,
        // Byte offsets in the extracted document text.
        public int $start,
        public int $end,
    ) {}
}
