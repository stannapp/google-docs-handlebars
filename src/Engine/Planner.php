<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Engine;

use Stann\GoogleDocsTemplate\Ast\BlockNode;
use Stann\GoogleDocsTemplate\Ast\Template;
use Stann\GoogleDocsTemplate\Ast\VariableNode;
use Stann\GoogleDocsTemplate\Context\Context;
use Stann\GoogleDocsTemplate\Document\DocumentText;
use Stann\GoogleDocsTemplate\Document\TableRowGeometry;
use Stann\GoogleDocsTemplate\Exception\TemplateStructureError;
use Stann\GoogleDocsTemplate\Exception\UnsupportedFeatureError;

/**
 * Compiles a parsed template + document geometry + context into edit phases:
 *
 *  1. structural requests — delete discarded {{#if}} blocks and their markers,
 *     insert the empty copy rows of the {{#each}} (bottom-up so earlier
 *     indexes stay valid);
 *  2. a deferred loop fill — the copies' cell texts, written after re-reading
 *     the document (row insertion shifted every index);
 *  3. final replaceAllText requests — variables, comments and loop markers,
 *     for the body and the auxiliary templates (headers, footers, footnotes)
 *     alike: replaceAllText applies to every surface of the document.
 *     The template row itself becomes copy #0: its {{item.*}} tags are the only
 *     ones left once the copies were inserted pre-rendered, so a global
 *     replacement targets it safely.
 *
 * @internal the public API of the library is {@see \Stann\GoogleDocsTemplate\TemplateEngine}
 */
final class Planner
{
    private const WRITABLE_TEXT_STYLE_FIELDS = [
        'bold', 'italic', 'underline', 'strikethrough', 'smallCaps', 'fontSize',
        'weightedFontFamily', 'foregroundColor', 'backgroundColor', 'baselineOffset', 'link',
    ];

    /**
     * @param Template[] $auxiliaryTemplates parsed headers, footers and footnotes — replace-only surfaces
     */
    public function plan(DocumentText $document, Template $template, Context $context, array $auxiliaryTemplates = []): RenderPlan
    {
        $loops = array_values(array_filter($template->blocks, static fn(BlockNode $b) => $b->kind === BlockNode::KIND_EACH));
        $conditions = array_values(array_filter($template->blocks, static fn(BlockNode $b) => $b->kind !== BlockNode::KIND_EACH));

        if (count($loops) > 1) {
            throw new UnsupportedFeatureError('Only one {{#each}} loop per template is supported.', $loops[1]->openRaw);
        }

        $loop = $loops[0] ?? null;
        $loopRow = $loop !== null ? $this->loopRow($document, $loop) : null;
        $items = $loop !== null ? ($context->list($loop->path) ?? []) : [];

        $this->assertVariableScopes($template, $loop, $loopRow);
        $this->assertConditionPlacements($document, $conditions, $loopRow);
        $this->assertAuxiliaryTemplates($auxiliaryTemplates);

        /** @var list<array{anchor: int, request: array<string, mixed>}> $structural */
        $structural = [];

        foreach ($conditions as $condition) {
            foreach ($this->conditionDeletions($document, $condition, $context) as $deletion) {
                $structural[] = $deletion;
            }
        }

        $loopFill = null;

        if ($loop !== null && $loopRow !== null) {
            if ($items === []) {
                $structural[] = [
                    'anchor' => $loopRow->rowStartIndex,
                    'request' => ['deleteTableRow' => ['tableCellLocation' => $this->cellLocation($loopRow)]],
                ];
            } else {
                for ($i = 1, $copies = count($items); $i < $copies; ++$i) {
                    $structural[] = [
                        'anchor' => $loopRow->rowEndIndex,
                        'request' => ['insertTableRow' => ['tableCellLocation' => $this->cellLocation($loopRow), 'insertBelow' => true]],
                    ];
                }

                // A single item needs no copy: the template row is copy #0.
                $loopFill = count($items) > 1 ? $this->pendingLoopFill($template, $loop, $loopRow, $items) : null;
            }
        }

        usort($structural, static fn(array $a, array $b) => $b['anchor'] <=> $a['anchor']);

        return new RenderPlan(
            structuralRequests: array_column($structural, 'request'),
            loopFill: $loopFill,
            finalReplacements: $this->finalReplacements($template, $auxiliaryTemplates, $context, $loop, $items),
        );
    }

    private function loopRow(DocumentText $document, BlockNode $loop): TableRowGeometry
    {
        $openRow = $document->rowContainingByte($loop->openStart);
        $closeRow = $document->rowContainingByte($loop->closeStart);

        if ($openRow === null || $closeRow === null || !$openRow->isSameRow($closeRow)) {
            throw new TemplateStructureError(sprintf('The {{#each %s}} markers must open and close inside the same table row.', $loop->path), $loop->openRaw);
        }

        return $openRow;
    }

    private function assertVariableScopes(Template $template, ?BlockNode $loop, ?TableRowGeometry $loopRow): void
    {
        foreach ($template->variables as $variable) {
            if (!$this->isLoopScoped($variable->path)) {
                continue;
            }

            if ($loop === null || $loopRow === null || !$loopRow->containsByte($variable->start)) {
                throw new TemplateStructureError('Loop variables can only be used inside the {{#each}} table row.', $variable->raw);
            }
        }
    }

    /**
     * @param BlockNode[] $conditions
     */
    private function assertConditionPlacements(DocumentText $document, array $conditions, ?TableRowGeometry $loopRow): void
    {
        foreach ($conditions as $condition) {
            if ($loopRow !== null && $condition->openStart >= $loopRow->byteStart && $condition->openStart < $loopRow->byteEnd) {
                throw new TemplateStructureError(sprintf('A {{#%s}} block cannot live inside the {{#each}} row.', $condition->kind), $condition->openRaw);
            }

            $openRow = $document->rowContainingByte($condition->openStart);
            $closeRow = $document->rowContainingByte($condition->closeStart);

            if (($openRow === null) !== ($closeRow === null)
                || ($openRow !== null && $closeRow !== null && !$openRow->isSameRow($closeRow))) {
                throw new TemplateStructureError(sprintf('The {{#%s %s}} markers must live in the same table cell, or both outside any table.', $condition->kind, $condition->path), $condition->openRaw);
            }

            if ($openRow !== null && $openRow->cellContainingByte($condition->openStart) !== $openRow->cellContainingByte($condition->closeStart)) {
                throw new TemplateStructureError(sprintf('The {{#%s %s}} markers must live in the same table cell.', $condition->kind, $condition->path), $condition->openRaw);
            }

            if ($openRow === null) {
                // A discarded block outside a table must not swallow table rows:
                // partial table deletions are illegal in the Docs API.
                foreach ($document->rows() as $row) {
                    if ($row->byteStart >= $condition->openEnd && $row->byteEnd <= $condition->closeStart) {
                        throw new TemplateStructureError(sprintf('A {{#%s}} block cannot contain a table.', $condition->kind), $condition->openRaw);
                    }
                }
            }
        }
    }

    /**
     * Headers, footers and footnotes only get the final replaceAllText pass:
     * anything structural in them cannot be honored and must be rejected.
     *
     * @param Template[] $auxiliaryTemplates
     */
    private function assertAuxiliaryTemplates(array $auxiliaryTemplates): void
    {
        foreach ($auxiliaryTemplates as $auxiliary) {
            foreach ($auxiliary->blocks as $block) {
                throw new TemplateStructureError(sprintf('A {{#%s}} block cannot live in a header, footer or footnote.', $block->kind), $block->openRaw);
            }

            foreach ($auxiliary->variables as $variable) {
                if ($this->isLoopScoped($variable->path)) {
                    throw new TemplateStructureError('Loop variables can only be used inside the {{#each}} table row.', $variable->raw);
                }
            }
        }
    }

    /**
     * @return list<array{anchor: int, request: array<string, mixed>}>
     */
    private function conditionDeletions(DocumentText $document, BlockNode $condition, Context $context): array
    {
        $truthy = $context->truthy($condition->path);
        $keep = $condition->kind === BlockNode::KIND_UNLESS ? !$truthy : $truthy;
        $inTable = $document->rowContainingByte($condition->openStart) !== null;

        if ($keep) {
            // Keep the content, drop the two markers.
            return [
                $this->deletionOf($document, $condition->closeStart, $condition->closeEnd, $inTable),
                $this->deletionOf($document, $condition->openStart, $condition->openEnd, $inTable),
            ];
        }

        return [$this->deletionOf($document, $condition->openStart, $condition->closeEnd, $inTable)];
    }

    /**
     * @return array{anchor: int, request: array<string, mixed>}
     */
    private function deletionOf(DocumentText $document, int $byteStart, int $byteEnd, bool $inTable): array
    {
        if (!$inTable) {
            [$byteStart, $byteEnd] = $this->expandToWholeLines($document->text(), $byteStart, $byteEnd);
        }

        $start = $document->docIndexAtByte($byteStart);
        $end = $document->docIndexAtByte($byteEnd);

        return [
            'anchor' => $start,
            'request' => ['deleteContentRange' => ['range' => ['startIndex' => $start, 'endIndex' => $end]]],
        ];
    }

    /**
     * A marker alone on its line should take the whole line with it, so the
     * rendered document doesn't keep stray empty paragraphs. Only applied
     * outside tables: inside a cell the trailing newline is the cell's last
     * paragraph mark, which must survive.
     *
     * @return array{0: int, 1: int}
     */
    private function expandToWholeLines(string $text, int $byteStart, int $byteEnd): array
    {
        $lineStart = (int) (strrpos(substr($text, 0, $byteStart), "\n") ?: -1) + 1;
        $nextBreak = strpos($text, "\n", $byteEnd);
        $lineEnd = $nextBreak === false ? strlen($text) : $nextBreak;

        $before = substr($text, $lineStart, $byteStart - $lineStart);
        $after = substr($text, $byteEnd, $lineEnd - $byteEnd);

        if (trim($before) !== '' || trim($after) !== '') {
            return [$byteStart, $byteEnd];
        }

        return [$lineStart, $nextBreak === false ? $lineEnd : $lineEnd + 1];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function pendingLoopFill(Template $template, BlockNode $loop, TableRowGeometry $loopRow, array $items): PendingLoopFill
    {
        $copyCellTexts = [];

        for ($itemIndex = 1, $count = count($items); $itemIndex < $count; ++$itemIndex) {
            $cellTexts = [];

            foreach ($loopRow->cells as $cell) {
                $text = $this->stripTrailingNewline($cell->text);
                $text = str_replace([$loop->openRaw, $loop->closeRaw], '', $text);

                foreach ($template->comments as $comment) {
                    if ($comment->start >= $cell->byteStart && $comment->start < $cell->byteEnd) {
                        $text = str_replace($comment->raw, '', $text);
                    }
                }

                foreach ($template->variables as $variable) {
                    if ($variable->start < $cell->byteStart || $variable->start >= $cell->byteEnd) {
                        continue;
                    }

                    $value = $this->loopValue($variable, $items, $itemIndex);

                    // Globals ({{customer.name}}...) stay as tags: the final
                    // global replacement fills them in every copy at once.
                    if ($value !== null) {
                        $text = str_replace($variable->raw, $value, $text);
                    }
                }

                $cellTexts[] = $text;
            }

            $copyCellTexts[] = $cellTexts;
        }

        $styles = [];
        $alignments = [];

        foreach ($loopRow->cells as $cell) {
            $style = array_intersect_key($cell->firstTextStyle ?? [], array_flip(self::WRITABLE_TEXT_STYLE_FIELDS));
            $styles[] = $style === [] ? null : $style;
            $alignment = $cell->firstParagraphStyle['alignment'] ?? null;
            $alignments[] = is_string($alignment) ? $alignment : null;
        }

        return new PendingLoopFill(
            tableOrdinal: $loopRow->tableOrdinal,
            templateRowIndex: $loopRow->rowIndex,
            copyCellTexts: $copyCellTexts,
            cellTextStyles: $styles,
            cellAlignments: $alignments,
        );
    }

    private function isLoopScoped(string $path): bool
    {
        return $path === '@index' || $path === 'item' || str_starts_with($path, 'item.');
    }

    /**
     * Value of a loop-scoped variable for a given item, or null when the
     * variable is not loop-scoped (left for the global fill).
     *
     * @param list<array<string, mixed>> $items
     */
    private function loopValue(VariableNode $variable, array $items, int $itemIndex): ?string
    {
        if ($variable->path === '@index') {
            return (string) $itemIndex;
        }

        if ($variable->path === 'item') {
            return '';
        }

        if (!str_starts_with($variable->path, 'item.')) {
            return null;
        }

        $value = $items[$itemIndex];

        foreach (explode('.', substr($variable->path, 5)) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }

            $value = $value[$segment];
        }

        if ($value === null || is_array($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param Template[]                 $auxiliaryTemplates
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function finalReplacements(Template $template, array $auxiliaryTemplates, Context $context, ?BlockNode $loop, array $items): array
    {
        /** @var array<string, string> $replacements raw tag => replacement */
        $replacements = [];

        // replaceAllText reaches every surface at once, so the auxiliary tags
        // (headers, footers, footnotes) join the body's in a single pass.
        foreach ([$template, ...$auxiliaryTemplates] as $parsed) {
            foreach ($parsed->comments as $comment) {
                $replacements[$comment->raw] = '';
            }
        }

        if ($loop !== null && $items !== []) {
            // The template row becomes copy #0.
            $replacements[$loop->openRaw] = '';
            $replacements[$loop->closeRaw] = '';
        }

        foreach ([$template, ...$auxiliaryTemplates] as $parsed) {
            foreach ($parsed->variables as $variable) {
                if (array_key_exists($variable->raw, $replacements)) {
                    continue;
                }

                if ($this->isLoopScoped($variable->path)) {
                    if ($items !== []) {
                        $replacements[$variable->raw] = $this->loopValue($variable, $items, 0) ?? '';
                    }

                    continue;
                }

                $value = $context->string($variable->path);

                if ($value !== null) {
                    $replacements[$variable->raw] = $value;
                }
            }
        }

        $requests = [];

        foreach ($replacements as $raw => $value) {
            $requests[] = ['replaceAllText' => [
                'containsText' => ['text' => (string) $raw, 'matchCase' => true],
                'replaceText' => $value,
            ]];
        }

        return $requests;
    }

    /**
     * @return array<string, mixed>
     */
    private function cellLocation(TableRowGeometry $row): array
    {
        return [
            'tableStartLocation' => ['index' => $row->tableStartIndex],
            'rowIndex' => $row->rowIndex,
            'columnIndex' => 0,
        ];
    }

    private function stripTrailingNewline(string $text): string
    {
        return str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;
    }
}
