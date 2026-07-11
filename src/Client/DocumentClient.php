<?php

declare(strict_types=1);

namespace Stann\GoogleDocsTemplate\Client;

interface DocumentClient
{
    /**
     * The document resource as returned by GET docs/v1/documents/{id},
     * decoded as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getDocument(string $documentId): array;

    /**
     * @param list<array<string, mixed>> $requests batchUpdate request objects
     */
    public function batchUpdate(string $documentId, array $requests): void;
}
