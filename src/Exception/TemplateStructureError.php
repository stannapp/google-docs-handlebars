<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Exception;

/**
 * The tags are individually valid but placed incompatibly with the document
 * structure (e.g. an {{#each}} whose markers do not live in a single table row).
 */
final class TemplateStructureError extends TemplateError
{
    public function __construct(
        string $message,
        // Raw source of the offending tag (with braces), when identifiable.
        public readonly string $tag = '',
    ) {
        parent::__construct($tag === '' ? $message : sprintf('%s (near "%s")', $message, $tag));
    }
}
