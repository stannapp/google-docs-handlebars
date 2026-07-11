<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Tests\Document;

use PHPUnit\Framework\TestCase;
use Stann\GoogleDocsTemplate\Document\DocumentText;
use Stann\GoogleDocsTemplate\Exception\InvalidDocumentError;
use Stann\GoogleDocsTemplate\Testing\GoogleDocBuilder;

class DocumentTextTest extends TestCase
{
    public function test_it_concatenates_text_in_reading_order(): void
    {
        $document = new DocumentText(GoogleDocBuilder::create()
            ->paragraph('Avant')
            ->table([['Nom', 'Qté'], ['{{item.name}}', '{{item.quantity}}']])
            ->paragraph('Après')
            ->build());

        self::assertSame("Avant\nNom\nQté\n{{item.name}}\n{{item.quantity}}\nAprès\n", $document->text());
    }

    public function test_doc_indexes_count_utf16_units_not_bytes(): void
    {
        // "é" is two UTF-8 bytes but a single UTF-16 unit — Docs indexes count the latter.
        $document = new DocumentText(GoogleDocBuilder::create()->paragraph('Café {{x}}')->build());

        $byteOffset = strpos($document->text(), '{{x}}');
        self::assertSame(6, $byteOffset);
        self::assertSame(6, $document->docIndexAtByte($byteOffset));

        self::assertSame(1 + DocumentText::u16len("Café {{x}}\n"), $document->docIndexAtByte(strlen($document->text())));
    }

    public function test_rows_carry_geometry_and_first_styles(): void
    {
        $document = new DocumentText(GoogleDocBuilder::create()
            ->paragraph('Titre')
            ->table([
                ['En-tête'],
                [['text' => '{{item.name}}', 'textStyle' => ['bold' => true], 'alignment' => 'END']],
            ])
            ->build());

        $rows = $document->rows();
        self::assertCount(2, $rows);
        self::assertSame(0, $rows[0]->tableOrdinal);
        self::assertSame(1, $rows[1]->rowIndex);

        $templateRow = $document->rowContainingByte((int) strpos($document->text(), '{{item.name}}'));
        self::assertNotNull($templateRow);
        self::assertSame(1, $templateRow->rowIndex);
        self::assertSame("{{item.name}}\n", $templateRow->cells[0]->text);
        self::assertSame(['bold' => true], $templateRow->cells[0]->firstTextStyle);
        self::assertSame(['alignment' => 'END'], $templateRow->cells[0]->firstParagraphStyle);

        // The first paragraph of a cell starts one index after the cell itself.
        $tagByte = (int) strpos($document->text(), '{{item.name}}');
        self::assertSame($templateRow->cells[0]->startIndex + 1, $document->docIndexAtByte($tagByte));
    }

    public function test_paragraphs_outside_tables_have_no_row(): void
    {
        $document = new DocumentText(GoogleDocBuilder::create()->paragraph('Libre')->build());

        self::assertNull($document->rowContainingByte(0));
        self::assertSame([], $document->rows());
    }

    public function test_headers_footers_and_footnotes_are_exposed_as_auxiliary_texts(): void
    {
        $document = new DocumentText(GoogleDocBuilder::create()
            ->paragraph('Corps')
            ->header('{{brand.name}}')
            ->footer('Page de {{user.name}}')
            ->footnote('Réf. {{quotation.reference}}')
            ->build());

        // The body stays untouched; each auxiliary surface is its own text.
        self::assertSame("Corps\n", $document->text());
        self::assertSame(
            ["{{brand.name}}\n", "Page de {{user.name}}\n", "Réf. {{quotation.reference}}\n"],
            $document->auxiliaryTexts(),
        );
    }

    public function test_a_document_without_body_is_rejected(): void
    {
        $this->expectException(InvalidDocumentError::class);

        new DocumentText(['body' => ['content' => 'not-a-list']]);
    }

    public function test_an_offset_outside_every_segment_is_rejected(): void
    {
        $document = new DocumentText(GoogleDocBuilder::create()->paragraph('Court')->build());

        $this->expectException(InvalidDocumentError::class);

        $document->docIndexAtByte(999);
    }
}
