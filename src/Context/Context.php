<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Context;

/**
 * Read-only view over the nested data the template renders against. Values
 * are expected to be display-ready strings (formatting is the integrator's
 * concern): the engine never formats numbers or dates itself.
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final readonly class Context
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * Display string for a path, or null when the path does not exist —
     * callers use null to leave the raw {{tag}} untouched in the document.
     */
    public function string(string $path): ?string
    {
        $value = $this->resolve($path);

        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Handlebars-like truthiness: absent, null, false, '', 0 and empty lists
     * are falsy.
     */
    public function truthy(string $path): bool
    {
        $value = $this->resolve($path);

        return !($value === null || $value === false || $value === '' || $value === [] || $value === 0 || $value === '0');
    }

    /**
     * The list behind an {{#each}} path: a list of associative rows, or null
     * when the path is absent or not a list.
     *
     * @return list<array<string, mixed>>|null
     */
    public function list(string $path): ?array
    {
        $value = $this->resolve($path);

        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function resolve(string $path): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
