<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Exception;

/**
 * The template text contains a construct the grammar cannot parse (malformed
 * tag, mismatched block markers, invalid path...).
 */
class TemplateSyntaxError extends TemplateError
{
    public function __construct(
        string $message,
        // Raw source of the offending tag (with braces), when identifiable.
        public readonly string $tag = '',
    ) {
        parent::__construct($tag === '' ? $message : sprintf('%s (near "%s")', $message, $tag));
    }
}
