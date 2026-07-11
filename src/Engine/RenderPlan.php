<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Engine;

/**
 * The compiled form of one rendering: an ordered list of structural edits
 * (block removals, row insertions), an optional deferred loop fill that needs
 * a re-read of the document, and the final global text replacements.
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class RenderPlan
{
    /**
     * @param list<array<string, mixed>> $structuralRequests batchUpdate requests, already ordered bottom-up
     * @param list<array<string, mixed>> $finalReplacements  replaceAllText requests (order-insensitive)
     */
    public function __construct(
        public array $structuralRequests,
        public ?PendingLoopFill $loopFill,
        public array $finalReplacements,
    ) {}
}
