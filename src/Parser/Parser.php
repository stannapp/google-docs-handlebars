<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Parser;

use Stann\GoogleDocsTemplate\Ast\BlockNode;
use Stann\GoogleDocsTemplate\Ast\CommentNode;
use Stann\GoogleDocsTemplate\Ast\Template;
use Stann\GoogleDocsTemplate\Ast\VariableNode;
use Stann\GoogleDocsTemplate\Exception\TemplateSyntaxError;
use Stann\GoogleDocsTemplate\Exception\UnsupportedFeatureError;

/**
 * Parses the Handlebars-compatible SUBSET supported by the engine:
 * {{path}}, {{! comment }}, {{!-- comment --}}, {{#if path}}, {{#unless path}},
 * {{#each path}} and their closers. Everything else that is valid Handlebars
 * (else, partials, helpers, #with, block params, nesting...) is rejected with
 * an explicit UnsupportedFeatureError so template authors get a clear message
 * instead of a silent misbehavior.
 *
 * Offsets are byte offsets into the text handed to parse(); mapping them back
 * to Google Docs indexes is the caller's concern.
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final class Parser
{
    private const PATH_PATTERN = '/^@?[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/';

    public function parse(string $text): Template
    {
        $variables = [];
        $comments = [];
        $blocks = [];
        /** @var array{kind: string, path: string, raw: string, start: int, end: int}|null $openBlock */
        $openBlock = null;

        // Variables and block markers may not span a line break: the extracted
        // document text separates paragraphs and table cells with \n, and a tag
        // crossing such a boundary could never be edited atomically anyway.
        // Long-form comments ({{!-- --}}) are the one multi-line construct.
        preg_match_all('/\{\{\{|\{\{!--.*?--\}\}|\{\{[^{}\n]*\}\}/s', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$raw, $start]) {
            $end = $start + strlen($raw);

            if ($raw === '{{{') {
                throw new UnsupportedFeatureError('Triple-stache tags are not supported.', '{{{');
            }

            $inner = trim($this->innerContent($raw));

            if ($inner === '' || str_starts_with($inner, '!')) {
                $comments[] = new CommentNode($raw, $start, $end);
                continue;
            }

            if (str_starts_with($inner, '#')) {
                $openBlock = $this->openBlock($inner, $raw, $start, $end, $openBlock);
                continue;
            }

            if (str_starts_with($inner, '/')) {
                $blocks[] = $this->closeBlock($inner, $raw, $start, $end, $openBlock);
                $openBlock = null;
                continue;
            }

            $variables[] = $this->variable($inner, $raw, $start, $end);
        }

        if ($openBlock !== null) {
            throw new TemplateSyntaxError(sprintf('The block "%s" is never closed with {{/%s}}.', $openBlock['raw'], $openBlock['kind']), $openBlock['raw']);
        }

        return new Template($variables, $comments, $blocks);
    }

    private function innerContent(string $raw): string
    {
        if (str_starts_with($raw, '{{!--')) {
            return '!' . substr($raw, 5, -4);
        }

        return substr($raw, 2, -2);
    }

    /**
     * @param array{kind: string, path: string, raw: string, start: int, end: int}|null $openBlock
     *
     * @return array{kind: string, path: string, raw: string, start: int, end: int}
     */
    private function openBlock(string $inner, string $raw, int $start, int $end, ?array $openBlock): array
    {
        if ($openBlock !== null) {
            throw new UnsupportedFeatureError('Nested blocks are not supported.', $raw);
        }

        if (str_starts_with($inner, '#>')) {
            throw new UnsupportedFeatureError('Partial blocks are not supported.', $raw);
        }

        if (preg_match('/^#(if|unless|each)\s+(.+)$/s', $inner, $match) !== 1) {
            preg_match('/^#([A-Za-z]*)/', $inner, $keyword);
            throw new UnsupportedFeatureError(sprintf('The block helper "#%s" is not supported. Supported blocks: #if, #unless, #each.', $keyword[1] ?? ''), $raw);
        }

        $argument = trim($match[2]);

        if (preg_match('/\sas\s*\|/', $argument) === 1) {
            throw new UnsupportedFeatureError('Block parameters (as |...|) are not supported.', $raw);
        }

        if (preg_match('/\s/', $argument) === 1 || str_contains($argument, '(')) {
            throw new UnsupportedFeatureError('Helpers and arguments are not supported: blocks take a single property path.', $raw);
        }

        $this->assertPath($argument, $raw, allowMetadata: false);

        return ['kind' => $match[1], 'path' => $argument, 'raw' => $raw, 'start' => $start, 'end' => $end];
    }

    /**
     * @param array{kind: string, path: string, raw: string, start: int, end: int}|null $openBlock
     */
    private function closeBlock(string $inner, string $raw, int $start, int $end, ?array $openBlock): BlockNode
    {
        $kind = trim(substr($inner, 1));

        if (!in_array($kind, [BlockNode::KIND_IF, BlockNode::KIND_UNLESS, BlockNode::KIND_EACH], true)) {
            throw new UnsupportedFeatureError(sprintf('The block closer "{{/%s}}" is not supported.', $kind), $raw);
        }

        if ($openBlock === null) {
            throw new TemplateSyntaxError(sprintf('"%s" has no matching opener.', $raw), $raw);
        }

        if ($openBlock['kind'] !== $kind) {
            throw new TemplateSyntaxError(sprintf('"%s" closes a {{#%s}} block.', $raw, $openBlock['kind']), $raw);
        }

        return new BlockNode(
            kind: $openBlock['kind'],
            path: $openBlock['path'],
            openRaw: $openBlock['raw'],
            openStart: $openBlock['start'],
            openEnd: $openBlock['end'],
            closeRaw: $raw,
            closeStart: $start,
            closeEnd: $end,
        );
    }

    private function variable(string $inner, string $raw, int $start, int $end): VariableNode
    {
        if (str_starts_with($inner, '>')) {
            throw new UnsupportedFeatureError('Partials are not supported.', $raw);
        }

        if ($inner === 'else' || str_starts_with($inner, 'else ')) {
            throw new UnsupportedFeatureError('{{else}} is not supported yet.', $raw);
        }

        if (str_starts_with($inner, '../') || str_starts_with($inner, '@root')) {
            throw new UnsupportedFeatureError('Parent and root context references are not supported.', $raw);
        }

        if (preg_match('/\s/', $inner) === 1 || str_contains($inner, '(')) {
            throw new UnsupportedFeatureError('Helpers and arguments are not supported: use a single property path.', $raw);
        }

        $this->assertPath($inner, $raw, allowMetadata: true);

        return new VariableNode($inner, $raw, $start, $end);
    }

    private function assertPath(string $path, string $raw, bool $allowMetadata): void
    {
        if (str_starts_with($path, '@')) {
            if (!$allowMetadata) {
                throw new TemplateSyntaxError('Loop metadata cannot be used as a block argument.', $raw);
            }

            if ($path !== '@index') {
                throw new UnsupportedFeatureError(sprintf('"%s" is not supported. Available loop metadata: @index.', $path), $raw);
            }

            return;
        }

        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new TemplateSyntaxError(sprintf('"%s" is not a valid property path.', $path), $raw);
        }
    }
}
