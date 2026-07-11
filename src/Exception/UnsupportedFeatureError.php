<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Exception;

/**
 * The construct is valid Handlebars but outside the supported subset. Kept
 * distinct from plain syntax errors so callers can phrase the message as
 * "not supported (yet)" rather than "invalid".
 */
final class UnsupportedFeatureError extends TemplateSyntaxError {}
