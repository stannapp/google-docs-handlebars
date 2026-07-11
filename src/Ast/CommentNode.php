<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Ast;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class CommentNode
{
    public function __construct(
        public string $raw,
        public int $start,
        public int $end,
    ) {}
}
