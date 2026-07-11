<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Testing;

use RuntimeException;
use Stann\GoogleDocsTemplate\Client\DocumentClient;

/**
 * In-memory DocumentClient: serves queued document payloads and records every
 * batchUpdate, so integrations can assert on the exact requests sent.
 */
final class FakeDocumentClient implements DocumentClient
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $documents;

    /**
     * @var list<list<array<string, mixed>>>
     */
    public array $batches = [];

    /**
     * @param array<string, mixed> ...$documents successive getDocument() payloads
     */
    public function __construct(array ...$documents)
    {
        $this->documents = array_values($documents);
    }

    public function getDocument(string $documentId): array
    {
        return array_shift($this->documents) ?? throw new RuntimeException('No more fake documents queued.');
    }

    public function batchUpdate(string $documentId, array $requests): void
    {
        $this->batches[] = $requests;
    }
}
