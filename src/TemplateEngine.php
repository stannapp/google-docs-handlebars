<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate;

use Stann\GoogleDocsTemplate\Client\DocumentClient;
use Stann\GoogleDocsTemplate\Context\Context;
use Stann\GoogleDocsTemplate\Document\DocumentText;
use Stann\GoogleDocsTemplate\Engine\Planner;
use Stann\GoogleDocsTemplate\Exception\TemplateError;
use Stann\GoogleDocsTemplate\Parser\Parser;

/**
 * Renders a Handlebars-subset template living in a Google Doc, in place.
 *
 * Grammar v1: {{path}}, {{! comment }}, {{#if path}} / {{#unless path}} on a
 * contiguous block, {{#each path}} on a single table row (with {{item.*}} and
 * {{@index}} inside), everything else rejected with an explicit error.
 *
 * Headers, footers and footnotes are replace-only surfaces: {{path}} and
 * comments work there, blocks and loop variables are rejected.
 *
 * Helpers are registered at construction and called Handlebars-style with
 * their arguments in template order — {{date "DD/MM/YYYY" quotation.dueAt}}
 * runs $helpers['date']('DD/MM/YYYY', <resolved value>). The engine ships
 * none: formatting is the integrator's concern.
 */
final readonly class TemplateEngine
{
    /**
     * @param array<string, callable(string...): string> $helpers by name
     */
    public function __construct(
        private array $helpers = [],
        private Parser $parser = new Parser(),
        private Planner $planner = new Planner(),
    ) {}

    /**
     * @param array<string, mixed> $context nested display-ready values; lists of
     *                                      associative rows become loopable collections
     *
     * @throws TemplateError
     */
    public function render(DocumentClient $client, string $documentId, array $context): void
    {
        $document = new DocumentText($client->getDocument($documentId));
        $template = $this->parser->parse($document->text());
        $auxiliaryTemplates = array_map($this->parser->parse(...), $document->auxiliaryTexts());
        $plan = $this->planner->plan($document, $template, new Context($context), $auxiliaryTemplates, $this->helpers);

        if ($plan->structuralRequests !== []) {
            $client->batchUpdate($documentId, $plan->structuralRequests);
        }

        if ($plan->loopFill !== null) {
            $fillRequests = $plan->loopFill->requestsFor(
                new DocumentText($client->getDocument($documentId)),
            );

            if ($fillRequests !== []) {
                $client->batchUpdate($documentId, $fillRequests);
            }
        }

        if ($plan->finalReplacements !== []) {
            $client->batchUpdate($documentId, $plan->finalReplacements);
        }
    }
}
