<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Embedding;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer Embedding-Entity.
 */
final class EmbeddingTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $embedding = new Embedding();
        $date = new DateTimeImmutable('2025-01-01');
        $embedding
            ->setContentHash('abc123')
            ->setContent('Hello world')
            ->setContentType('text')
            ->setSource('doc.pdf')
            ->setMetadata(['key' => 'value'])
            ->setVector([0.1, 0.2, 0.3])
            ->setCreatedAt($date);

        self::assertSame(hash('sha256', 'Hello world'), $embedding->getContentHash());
        self::assertSame('Hello world', $embedding->getContent());
        self::assertSame('text', $embedding->getContentType());
        self::assertSame('doc.pdf', $embedding->getSource());
        self::assertSame(['key' => 'value'], $embedding->getMetadata());
        self::assertSame([0.1, 0.2, 0.3], $embedding->getVector());
        self::assertSame($date, $embedding->getCreatedAt());
    }

    public function testDefaults(): void
    {
        $embedding = new Embedding();
        self::assertNull($embedding->getId());
        self::assertNull($embedding->getContentHash());
        self::assertNull($embedding->getContent());
        self::assertNull($embedding->getContentType());
        self::assertNull($embedding->getSource());
        self::assertSame([], $embedding->getMetadata());
        self::assertSame([], $embedding->getVector());
        self::assertInstanceOf(DateTimeImmutable::class, $embedding->getCreatedAt());
    }

    public function testSourceNullable(): void
    {
        $embedding = new Embedding();
        $embedding->setSource(null);
        self::assertNull($embedding->getSource());
    }

    public function testCosineSimilarityIdenticalVectors(): void
    {
        $a = new Embedding();
        $a->setVector([1.0, 0.0, 0.0]);
        $b = new Embedding();
        $b->setVector([1.0, 0.0, 0.0]);

        self::assertSame(1.0, $a->cosineSimilarity($b));
    }

    public function testCosineSimilarityOrthogonalVectors(): void
    {
        $a = new Embedding();
        $a->setVector([1.0, 0.0]);
        $b = new Embedding();
        $b->setVector([0.0, 1.0]);

        self::assertSame(0.0, $a->cosineSimilarity($b));
    }

    public function testCosineSimilarityZeroVectors(): void
    {
        $a = new Embedding();
        $a->setVector([0.0, 0.0]);
        $b = new Embedding();
        $b->setVector([1.0, 1.0]);

        self::assertSame(0.0, $a->cosineSimilarity($b));
    }

    public function testCosineSimilarityDifferentLengths(): void
    {
        $a = new Embedding();
        $a->setVector([1.0, 1.0, 1.0]);
        $b = new Embedding();
        $b->setVector([1.0, 1.0]);

        $sim = $a->cosineSimilarity($b);
        self::assertGreaterThan(0.0, $sim);
        self::assertLessThan(1.0, $sim);
    }
}
