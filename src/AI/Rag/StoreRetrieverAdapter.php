<?php

declare(strict_types=1);

namespace App\AI\Rag;

use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\RetrieverInterface;

/**
 * Nativer Symfony AI Store Retriever-Adapter (Blueprint §4.H).
 *
 * Bridget EVIEs bestehende Retriever-Implementierung zur nativen
 * Symfony\AI\Store\RetrieverInterface, damit der ContextInjector
 * (als InputProcessor) über den nativen Store-Pfad läuft.
 *
 * Production-Feature: User/Tenant-Filtering auf Store-Ebene — jede
 * Retrieval-Anfrage kann mit einem userIdentifier gefiltert werden,
 * sodass Tenant A niemals Kontext von Tenant B erhält.
 */
final class StoreRetrieverAdapter implements RetrieverInterface
{
    public function __construct(
        private readonly Retriever $evieRetriever,
    ) {
    }

    /**
     * @param array<string, mixed> $options Options können enthalten:
     *   - user_identifier: Tenant/User-Filter für Isolation
     *   - content_types: zu durchsuchende Content-Typen
     *   - limit, min_similarity: Retrieval-Parameter
     *
     * @return iterable<VectorDocument>
     */
    public function retrieve(string $query, array $options = []): iterable
    {
        $userIdentifier = $options['user_identifier'] ?? null;

        $result = $this->evieRetriever->retrieve($query, $options);

        foreach ($result->getItems() as $item) {
            $content = $item->getContent() ?? '';
            if ('' === $content) {
                continue;
            }

            $metadata = new Metadata([
                'content' => $content,
                'similarity' => $item->similarity,
                'content_type' => $item->contentType,
                'user_identifier' => $userIdentifier,
            ]);

            yield new VectorDocument(
                $item->contentType.':'.uniqid('', true),
                new NullVector(),
                $metadata,
            );
        }
    }
}
