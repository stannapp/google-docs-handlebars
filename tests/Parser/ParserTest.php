<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Stann\GoogleDocsTemplate\Ast\BlockNode;
use Stann\GoogleDocsTemplate\Exception\TemplateSyntaxError;
use Stann\GoogleDocsTemplate\Exception\UnsupportedFeatureError;
use Stann\GoogleDocsTemplate\Parser\Parser;

class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function test_it_parses_variables_comments_and_blocks(): void
    {
        $template = $this->parser->parse(
            "Bonjour {{customer.name}} {{! interne }}\n"
            . "{{#if quotation}}Devis {{quotation.reference}}{{/if}}\n"
            . "{{#each items}}{{item.name}} {{@index}}{{/each}}\n"
            . '{{!-- long
comment --}}',
        );

        self::assertCount(4, $template->variables);
        self::assertSame(['customer.name', 'quotation.reference', 'item.name', '@index'], array_column($template->variables, 'path'));
        self::assertCount(2, $template->comments);
        self::assertCount(2, $template->blocks);
        self::assertSame(BlockNode::KIND_IF, $template->blocks[0]->kind);
        self::assertSame('quotation', $template->blocks[0]->path);
        self::assertSame(BlockNode::KIND_EACH, $template->blocks[1]->kind);
        self::assertSame('items', $template->blocks[1]->path);
    }

    public function test_offsets_point_at_the_source_tags(): void
    {
        $text = 'Café {{customer.name}} !';
        $template = $this->parser->parse($text);

        $variable = $template->variables[0];
        self::assertSame('{{customer.name}}', substr($text, $variable->start, $variable->end - $variable->start));
    }

    public function test_variables_inside_blocks_are_locatable(): void
    {
        $template = $this->parser->parse('{{#each items}}{{item.name}}{{/each}} {{date}}');

        $each = $template->blocks[0];
        self::assertCount(1, $template->variablesWithin($each));
        self::assertSame('item.name', $template->variablesWithin($each)[0]->path);
    }

    public function test_a_tag_cannot_span_a_line_break(): void
    {
        $template = $this->parser->parse("{{cus\ntomer}}");

        self::assertSame([], $template->variables);
    }

    /**
     * @dataProvider unsupportedTemplates
     */
    public function test_unsupported_constructs_are_rejected_explicitly(string $source): void
    {
        $this->expectException(UnsupportedFeatureError::class);

        $this->parser->parse($source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedTemplates(): iterable
    {
        yield 'triple stache' => ['{{{raw}}}'];
        yield 'else' => ['{{#if a}}x{{else}}y{{/if}}'];
        yield 'partial' => ['{{> header}}'];
        yield 'block helper' => ['{{#with customer}}{{name}}{{/with}}'];
        yield 'block params' => ['{{#each items as |line|}}{{/each}}'];
        yield 'nested blocks' => ['{{#if a}}{{#each items}}{{/each}}{{/if}}'];
        yield 'parent context' => ['{{../name}}'];
        yield 'unsupported metadata' => ['{{#each items}}{{@first}}{{/each}}'];
    }

    /**
     * @dataProvider malformedTemplates
     */
    public function test_malformed_templates_are_syntax_errors(string $source): void
    {
        $this->expectException(TemplateSyntaxError::class);

        $this->parser->parse($source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTemplates(): iterable
    {
        yield 'unclosed block' => ['{{#if a}}jamais fermé'];
        yield 'closer without opener' => ['{{/if}}'];
        yield 'mismatched closer' => ['{{#if a}}{{/each}}'];
        yield 'invalid path' => ['{{foo..bar}}'];
    }

    public function test_a_helper_call_parses_its_name_and_ordered_arguments(): void
    {
        $template = (new Parser())->parse('Le {{date "DD/MM/YYYY" quotation.dueAt}}');

        self::assertCount(1, $template->helpers);
        self::assertCount(0, $template->variables);

        $call = $template->helpers[0];
        self::assertSame('date', $call->name);
        self::assertSame('{{date "DD/MM/YYYY" quotation.dueAt}}', $call->raw);
        self::assertSame([
            ['literal' => true, 'value' => 'DD/MM/YYYY'],
            ['literal' => false, 'value' => 'quotation.dueAt'],
        ], $call->arguments);
    }

    public function test_a_helper_argument_must_be_a_literal_or_a_valid_path(): void
    {
        $this->expectException(TemplateSyntaxError::class);

        (new Parser())->parse('{{date "DD/MM/YYYY" not..a..path}}');
    }
}
