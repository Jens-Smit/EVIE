<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\AI\Rag\EmbeddingServiceInterface;

/**
 * Deterministischer Embedding-Service fuer Tests (P0-1).
 *
 * Erzeugt keine echten API-Calls (kein Mistral). Statt zufaelliger oder
 * HTTP-basierter Vektoren liefert er ein deterministisches, von den
 * Woertern des Texts abgeleitetes Vektorprofil. So sind die
 * Aehnlichkeitsberechnungen in Integrationstests reproduzierbar und
 * kontrollierbar.
 *
 * Strategie: jeder Text wird in Tokens zerlegt; pro Token wird ein
 * Hash-basierter Index und Wert berechnet. So haben Texte mit vielen
 * gemeinsamen Tokens eine hohe Cosine-Similarity.
 */
final class DeterministicEmbeddingService implements EmbeddingServiceInterface
{
    private const DIMENSION = 128;

    public function embedText(string $text): array
    {
        $vector = array_fill(0, self::DIMENSION, 0.0);

        foreach ($this->tokens($text) as $token) {
            $hash = crc32($token);
            $index = abs($hash) % self::DIMENSION;
            $value = (($hash >> 7) % 100) / 100.0 + 0.5;
            $vector[$index] += $value;
        }

        return $vector;
    }

    public function embedTextBatch(array $texts): array
    {
        return array_map(fn (string $text): array => $this->embedText($text), $texts);
    }

    public function getDimension(): int
    {
        return self::DIMENSION;
    }

    public function getModelName(): string
    {
        return 'deterministic-test-embedding';
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = strtolower(preg_replace('/[^[:alnum:]\s]/u', ' ', $text) ?? '');
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $tokens = array_filter($tokens, static fn (string $t): bool => '' !== $t);

        return array_values($tokens);
    }
}
