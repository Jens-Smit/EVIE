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
 * (als InputProcessor) ueber den nativen Store-Pfad laeuft.
 *
 * Production-Feature: User/Tenant-Filtering auf Store-Ebene — jede
 * Retrieval-Anfrage wird mit einem userIdentifier gefiltert, sodass
 * Tenant A niemals Kontext von Tenant B erhaelt. Der Identifier wird
 * an Retriever::retrieve() durchgereicht und filtert die zugrunde
 * liegenden Embedding-Ergebnisse serverseitig (P0-5).
 */
final class StoreRetrieverAdapter implements RetrieverInterface
{
    public function __construct(
        private readonly Retriever $evieRetriever,
    ) {
    }

    /**
     * @param array<string, mixed> $options Options koennen enthalten:
     *   - user_identifier: Tenant/User-Filter fuer Isolation (wird an
     *     Retriever durchgereicht und filtert serverseitig)
     *   - content_types: zu durchsuchende Content-Typen
     *   - limit, min_similarity: Retrieval-Parameter
     *
     * @return iterable<VectorDocument>
     */
    public function retrieve(string $query, array $options = []): iterable
    {
        $userIdentifier = $options['user_identifier'] ?? null;

        // P0-5: der Identifier filtert die zugrunde liegenden Ergebnisse,
        // nicht nur die Ausgabe-Metadaten. Retriever::retrieve() reicht ihn
        // an VectorStore::search() weiter, sodass Tenant-Isolation
        // server-/repository-seitig erzwungen wird.
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
