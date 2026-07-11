<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Exception;

/**
 * The document resource itself is unusable (missing body, offsets outside any
 * text segment...). Points at the fetched payload, not at the template text.
 */
final class InvalidDocumentError extends TemplateError {}
