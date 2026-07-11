<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Tests\Context;

use PHPUnit\Framework\TestCase;
use Stann\GoogleDocsTemplate\Context\Context;

class ContextTest extends TestCase
{
    public function test_it_resolves_dotted_paths_to_strings(): void
    {
        $context = new Context(['customer' => ['name' => 'Syndic des Lilas'], 'date' => '09/07/2026']);

        self::assertSame('Syndic des Lilas', $context->string('customer.name'));
        self::assertSame('09/07/2026', $context->string('date'));
        self::assertNull($context->string('customer.unknown'));
        self::assertNull($context->string('customer'));
    }

    public function test_truthiness_follows_handlebars_semantics(): void
    {
        $context = new Context([
            'quotation' => ['reference' => '2026'],
            'emptyList' => [],
            'emptyString' => '',
            'zero' => 0,
            'no' => false,
        ]);

        self::assertTrue($context->truthy('quotation'));
        self::assertTrue($context->truthy('quotation.reference'));
        self::assertFalse($context->truthy('emptyList'));
        self::assertFalse($context->truthy('emptyString'));
        self::assertFalse($context->truthy('zero'));
        self::assertFalse($context->truthy('no'));
        self::assertFalse($context->truthy('absent'));
    }

    public function test_lists_are_only_lists_of_rows(): void
    {
        $context = new Context([
            'items' => [['name' => 'A'], ['name' => 'B']],
            'scalar' => 'x',
            'assoc' => ['a' => 1],
        ]);

        self::assertCount(2, $context->list('items') ?? []);
        self::assertNull($context->list('scalar'));
        self::assertNull($context->list('assoc'));
        self::assertNull($context->list('absent'));
    }
}
