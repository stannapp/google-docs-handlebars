<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Tests;

use PHPUnit\Framework\TestCase;
use Stann\GoogleDocsTemplate\Exception\TemplateStructureError;
use Stann\GoogleDocsTemplate\Exception\UnsupportedFeatureError;
use Stann\GoogleDocsTemplate\TemplateEngine;
use Stann\GoogleDocsTemplate\Testing\FakeDocumentClient;
use Stann\GoogleDocsTemplate\Testing\GoogleDocBuilder;

class TemplateEngineTest extends TestCase
{
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TemplateEngine();
    }

    public function test_variables_only_need_a_single_replacement_batch(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()->paragraph('Bonjour {{customer.name}}, le {{ date }}')->build(),
        );

        $this->engine->render($client, 'doc', ['customer' => ['name' => 'Syndic'], 'date' => '09/07/2026']);

        self::assertCount(1, $client->batches);
        $replacements = $this->replacementMap($client->batches[0]);
        self::assertSame('Syndic', $replacements['{{customer.name}}']);
        // The tag is matched as written, whatever the inner spacing.
        self::assertSame('09/07/2026', $replacements['{{ date }}']);
    }

    public function test_header_footer_and_footnote_variables_are_replaced_as_written(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Corps')
                ->header('{{  brand.name  }} — {{customer.name}}')
                ->footer('Édité par {{user.name}} {{! note interne }}')
                ->footnote('Réf. {{quotation.reference}}')
                ->build(),
        );

        $this->engine->render($client, 'doc', [
            'brand' => ['name' => 'Stann Hygiène'],
            'customer' => ['name' => 'Syndic'],
            'user' => ['name' => 'Camille'],
            'quotation' => ['reference' => '20260701'],
        ]);

        // replaceAllText reaches headers, footers and footnotes: one batch.
        self::assertCount(1, $client->batches);
        $replacements = $this->replacementMap($client->batches[0]);
        self::assertSame('Stann Hygiène', $replacements['{{  brand.name  }}']);
        self::assertSame('Syndic', $replacements['{{customer.name}}']);
        self::assertSame('Camille', $replacements['{{user.name}}']);
        self::assertSame('20260701', $replacements['{{quotation.reference}}']);
        self::assertSame('', $replacements['{{! note interne }}']);
    }

    public function test_blocks_in_a_header_are_rejected(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Corps')
                ->header('{{#if quotation}}Devis{{/if}}')
                ->build(),
        );

        $this->expectException(TemplateStructureError::class);

        $this->engine->render($client, 'doc', []);
    }

    public function test_loop_variables_in_a_footer_are_rejected(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Corps')
                ->footer('{{item.name}}')
                ->build(),
        );

        $this->expectException(TemplateStructureError::class);

        $this->engine->render($client, 'doc', ['items' => [['name' => 'A']]]);
    }

    public function test_unknown_variables_are_left_untouched(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()->paragraph('{{mystere}} le {{date}}')->build(),
        );

        $this->engine->render($client, 'doc', ['date' => 'x']);

        $replacements = $this->replacementMap($client->batches[0]);
        self::assertSame('x', $replacements['{{date}}']);
        self::assertArrayNotHasKey('{{mystere}}', $replacements);
    }

    public function test_comments_are_removed(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()->paragraph('Visible {{! interne }}')->build(),
        );

        $this->engine->render($client, 'doc', []);

        self::assertSame('', $this->replacementMap($client->batches[0])['{{! interne }}']);
    }

    public function test_a_false_condition_removes_the_whole_block_and_its_lines(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Avant')            // doc [1, 7)
                ->paragraph('{{#if quotation}}') // doc [7, 25)
                ->paragraph('Contenu')           // doc [25, 33)
                ->paragraph('{{/if}}')           // doc [33, 41)
                ->paragraph('Après')
                ->build(),
        );

        $this->engine->render($client, 'doc', []);

        self::assertSame(
            ['deleteContentRange' => ['range' => ['startIndex' => 7, 'endIndex' => 41]]],
            $client->batches[0][0],
        );
    }

    public function test_a_true_condition_only_removes_the_markers_with_their_lines(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('{{#if quotation}}') // doc [1, 19)
                ->paragraph('Contenu')           // doc [19, 27)
                ->paragraph('{{/if}}')           // doc [27, 35)
                ->build(),
        );

        $this->engine->render($client, 'doc', ['quotation' => ['reference' => 'X']]);

        // Bottom-up: the closer goes first.
        self::assertSame(
            [
                ['deleteContentRange' => ['range' => ['startIndex' => 27, 'endIndex' => 35]]],
                ['deleteContentRange' => ['range' => ['startIndex' => 1, 'endIndex' => 19]]],
            ],
            $client->batches[0],
        );
    }

    public function test_unless_inverts_the_condition(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('{{#unless quotation}}Mention fiche client{{/unless}}')
                ->build(),
        );

        // No quotation: the block is kept, only the inline markers disappear.
        $this->engine->render($client, 'doc', []);

        $first = $client->batches[0];
        self::assertCount(2, $first);
        self::assertArrayHasKey('deleteContentRange', $first[0]);
        self::assertArrayHasKey('deleteContentRange', $first[1]);
    }

    public function test_each_expands_the_template_row_per_item(): void
    {
        $templateDocument = GoogleDocBuilder::create()
            ->paragraph('Devis {{quotation.reference}}')
            ->table([
                ['Désignation', 'Qté'],
                [
                    ['text' => '{{#each items}}{{item.name}}', 'textStyle' => ['bold' => true]],
                    ['text' => '{{item.quantity}}{{/each}}', 'alignment' => 'END'],
                ],
            ])
            ->build();

        // Snapshot after phase A: two empty copy rows below the template row.
        $expandedDocument = GoogleDocBuilder::create()
            ->paragraph('Devis {{quotation.reference}}')
            ->table([
                ['Désignation', 'Qté'],
                ['{{#each items}}{{item.name}}', '{{item.quantity}}{{/each}}'],
                ['', ''],
                ['', ''],
            ])
            ->build();

        $client = new FakeDocumentClient($templateDocument, $expandedDocument);

        $this->engine->render($client, 'doc', [
            'quotation' => ['reference' => '20260701'],
            'items' => [
                ['name' => 'Dératisation', 'quantity' => '1'],
                ['name' => 'Désinsectisation', 'quantity' => '2'],
                ['name' => 'Fumigation', 'quantity' => '3'],
            ],
        ]);

        self::assertCount(3, $client->batches);

        // Phase A: two empty rows inserted below the template row (row 1).
        [$structural, $fill, $final] = $client->batches;
        self::assertCount(2, $structural);
        self::assertSame(['insertTableRow' => [
            'tableCellLocation' => ['tableStartLocation' => ['index' => 31], 'rowIndex' => 1, 'columnIndex' => 0],
            'insertBelow' => true,
        ]], $structural[0]);

        // Phase B: bottom-up fill of the copies with rendered texts and copied styles.
        $insertedTexts = array_values(array_map(
            static fn(array $request) => $request['insertText']['text'],
            array_filter($fill, static fn(array $request) => isset($request['insertText'])),
        ));
        self::assertSame(['3', 'Fumigation', '2', 'Désinsectisation'], $insertedTexts);

        $styleRequests = array_values(array_filter($fill, static fn(array $request) => isset($request['updateTextStyle'])));
        self::assertCount(2, $styleRequests);
        self::assertSame(['bold' => true], $styleRequests[0]['updateTextStyle']['textStyle']);

        $alignmentRequests = array_values(array_filter($fill, static fn(array $request) => isset($request['updateParagraphStyle'])));
        self::assertCount(2, $alignmentRequests);
        self::assertSame('END', $alignmentRequests[0]['updateParagraphStyle']['paragraphStyle']['alignment']);

        // Phase C: the template row becomes copy #0 and the markers vanish.
        $replacements = $this->replacementMap($final);
        self::assertSame('', $replacements['{{#each items}}']);
        self::assertSame('', $replacements['{{/each}}']);
        self::assertSame('Dératisation', $replacements['{{item.name}}']);
        self::assertSame('1', $replacements['{{item.quantity}}']);
        self::assertSame('20260701', $replacements['{{quotation.reference}}']);
    }

    public function test_each_inserts_the_copies_at_the_fresh_cell_positions(): void
    {
        $templateDocument = GoogleDocBuilder::create()
            ->table([['{{#each items}}{{item.name}}{{/each}}']])
            ->build();
        $expandedDocument = GoogleDocBuilder::create()
            ->table([['{{#each items}}{{item.name}}{{/each}}'], ['']])
            ->build();

        $client = new FakeDocumentClient($templateDocument, $expandedDocument);

        $this->engine->render($client, 'doc', ['items' => [['name' => 'A'], ['name' => 'B']]]);

        $fill = $client->batches[1];
        self::assertArrayHasKey('insertText', $fill[0]);

        // The copy row cell of the refreshed snapshot: table(1) + header row…
        // here row 0 is the template ("{{#each items}}{{item.name}}{{/each}}\n"
        // = 38 units after cell marker at 3, so row 1 starts at 42, its cell at
        // 43 and the insertion lands one index inside the cell.
        self::assertSame(44, $fill[0]['insertText']['location']['index']);
        self::assertSame('B', $fill[0]['insertText']['text']);
    }

    public function test_each_with_no_item_deletes_the_template_row(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->table([['En-tête'], ['{{#each items}}{{item.name}}{{/each}}']])
                ->build(),
        );

        $this->engine->render($client, 'doc', ['items' => []]);

        // The whole loop lives in the deleted row: nothing remains to replace.
        self::assertCount(1, $client->batches);
        self::assertArrayHasKey('deleteTableRow', $client->batches[0][0]);
        self::assertSame(1, $client->batches[0][0]['deleteTableRow']['tableCellLocation']['rowIndex']);
    }

    public function test_each_with_one_item_keeps_the_template_row_only(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->table([['{{#each items}}{{item.name}}{{/each}}']])
                ->build(),
        );

        $this->engine->render($client, 'doc', ['items' => [['name' => 'Unique']]]);

        // No structural work, no refetch: a single replacement batch.
        self::assertCount(1, $client->batches);
        self::assertSame('Unique', $this->replacementMap($client->batches[0])['{{item.name}}']);
    }

    public function test_item_variables_outside_the_loop_row_are_rejected(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()->paragraph('{{item.name}}')->build(),
        );

        $this->expectException(TemplateStructureError::class);

        $this->engine->render($client, 'doc', ['items' => [['name' => 'A']]]);
    }

    public function test_each_markers_must_share_a_table_row(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->table([['{{#each items}}'], ['{{/each}}']])
                ->build(),
        );

        $this->expectException(TemplateStructureError::class);

        $this->engine->render($client, 'doc', ['items' => []]);
    }

    public function test_helpers_format_values_with_their_template_order_arguments(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Le {{date "DD/MM/YYYY" quotation.dueAt}} — {{money quotation.totalAmount}}')
                ->build(),
        );

        $engine = new TemplateEngine(helpers: [
            'date' => static fn(string $format, string $value): string => 'DATE(' . $format . ',' . $value . ')',
            'money' => static fn(string $amount): string => $amount . ' EUR',
        ]);

        $engine->render($client, 'doc', [
            'quotation' => ['dueAt' => '2026-07-31T00:00:00+02:00', 'totalAmount' => 1234.5],
        ]);

        $replacements = $this->replacementMap($client->batches[0]);
        self::assertSame('DATE(DD/MM/YYYY,2026-07-31T00:00:00+02:00)', $replacements['{{date "DD/MM/YYYY" quotation.dueAt}}']);
        self::assertSame('1234.5 EUR', $replacements['{{money quotation.totalAmount}}']);
    }

    public function test_helpers_work_in_headers_too(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('Corps')
                ->header('{{date "YYYY" quotation.dueAt}}')
                ->build(),
        );

        $engine = new TemplateEngine(helpers: [
            'date' => static fn(string $format, string $value): string => 'OK',
        ]);

        $engine->render($client, 'doc', ['quotation' => ['dueAt' => 'x']]);

        self::assertSame('OK', $this->replacementMap($client->batches[0])['{{date "YYYY" quotation.dueAt}}']);
    }

    public function test_an_unregistered_helper_is_rejected(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()->paragraph('{{shout customer.name}}')->build(),
        );

        $this->expectException(UnsupportedFeatureError::class);

        $this->engine->render($client, 'doc', ['customer' => ['name' => 'x']]);
    }

    public function test_helpers_on_loop_variables_are_rejected(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->table([['{{#each items}}{{date "YYYY" item.at}}{{/each}}']])
                ->build(),
        );

        $engine = new TemplateEngine(helpers: [
            'date' => static fn(string $format, string $value): string => 'OK',
        ]);

        $this->expectException(UnsupportedFeatureError::class);

        $engine->render($client, 'doc', ['items' => [['at' => 'x']]]);
    }

    public function test_only_one_loop_is_supported(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->table([['{{#each items}}{{/each}}']])
                ->table([['{{#each items}}{{/each}}']])
                ->build(),
        );

        // A second loop is valid Handlebars: "not supported", not "malformed".
        $this->expectException(UnsupportedFeatureError::class);

        $this->engine->render($client, 'doc', ['items' => []]);
    }

    public function test_a_condition_cannot_contain_a_table(): void
    {
        $client = new FakeDocumentClient(
            GoogleDocBuilder::create()
                ->paragraph('{{#if quotation}}')
                ->table([['Cellule']])
                ->paragraph('{{/if}}')
                ->build(),
        );

        $this->expectException(TemplateStructureError::class);

        $this->engine->render($client, 'doc', []);
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, string>
     */
    private function replacementMap(array $requests): array
    {
        $map = [];

        foreach ($requests as $request) {
            if (isset($request['replaceAllText'])) {
                $map[$request['replaceAllText']['containsText']['text']] = $request['replaceAllText']['replaceText'];
            }
        }

        return $map;
    }
}
