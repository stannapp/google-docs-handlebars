<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Ast;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class BlockNode
{
    public const KIND_IF = 'if';
    public const KIND_UNLESS = 'unless';
    public const KIND_EACH = 'each';

    public function __construct(
        public string $kind,
        public string $path,
        public string $openRaw,
        public int $openStart,
        public int $openEnd,
        public string $closeRaw,
        public int $closeStart,
        public int $closeEnd,
    ) {}

    public function contains(int $offset): bool
    {
        return $offset >= $this->openEnd && $offset < $this->closeStart;
    }
}
