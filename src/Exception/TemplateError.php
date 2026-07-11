<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Exception;

use RuntimeException;

/**
 * Base class of every error raised by the template engine, so integrators can
 * catch the whole family with a single type.
 */
abstract class TemplateError extends RuntimeException {}
