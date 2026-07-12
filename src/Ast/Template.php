<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Ast;

/**
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class Template
{
    /**
     * @param VariableNode[] $variables
     * @param CommentNode[]  $comments
     * @param BlockNode[]    $blocks
     * @param HelperNode[]   $helpers
     */
    public function __construct(
        public array $variables,
        public array $comments,
        public array $blocks,
        public array $helpers = [],
    ) {}

    public function blockContaining(int $offset): ?BlockNode
    {
        foreach ($this->blocks as $block) {
            if ($block->contains($offset)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @return VariableNode[]
     */
    public function variablesWithin(BlockNode $block): array
    {
        return array_values(array_filter(
            $this->variables,
            static fn(VariableNode $variable) => $block->contains($variable->start),
        ));
    }
}
