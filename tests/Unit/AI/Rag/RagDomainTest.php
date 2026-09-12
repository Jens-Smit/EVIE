<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Rag\RetrievalResult;
use App\AI\Rag\RetrievedItem;
use App\Entity\Embedding;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer RetrievedItem und RetrievalResult.
 */
final class RagDomainTest extends TestCase
{
    private function createEmbedding(string $content = 'content', ?string $source = 'src', array $metadata = []): Embedding
    {
        $emb = new Embedding();
        $emb->setContent($content);
        $emb->setSource($source);
        $emb->setMetadata($metadata);
        return $emb;
    }

    private function createItem(float $similarity = 0.9, array $metadata = []): RetrievedItem
    {
        return new RetrievedItem($this->createEmbedding('content', 'src', $metadata), $similarity, 'user_profile');
    }

    // ---- RetrievedItem ----

    public function testRetrievedItemGetContentAndSource(): void
    {
        $emb = $this->createEmbedding('my content', 'source-x');
        $item = new RetrievedItem($emb, 0.8, 'knowledge');
        self::assertSame('my content', $item->getContent());
        self::assertSame('source-x', $item->getSource());
        self::assertSame([], $item->getMetadata());
        self::assertSame(0.8, $item->similarity);
        self::assertSame('knowledge', $item->contentType);
    }

    public function testRetrievedItemGetSourceNull(): void
    {
        $emb = $this->createEmbedding('c', null, []);
        $item = new RetrievedItem($emb, 1.0, 't');
        self::assertNull($item->getSource());
    }

    public function testGetTrustLevelDefaultsToUntrusted(): void
    {
        $item = $this->createItem();
        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
    }

    public function testGetTrustLevelFromMetadataTrusted(): void
    {
        $item = $this->createItem(0.9, ['trust_level' => RetrievedItem::TRUST_LEVEL_TRUSTED]);
        self::assertSame(RetrievedItem::TRUST_LEVEL_TRUSTED, $item->getTrustLevel());
    }

    public function testGetTrustLevelFromMetadataSystem(): void
    {
        $item = $this->createItem(0.9, ['trust_level' => RetrievedItem::TRUST_LEVEL_SYSTEM]);
        self::assertSame(RetrievedItem::TRUST_LEVEL_SYSTEM, $item->getTrustLevel());
    }

    public function testGetTrustLevelFromMetadataInvalidFallsBackToUntrusted(): void
    {
        $item = $this->createItem(0.9, ['trust_level' => 'invalid_value']);
        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
    }

    public function testSetTrustLevelValidValue(): void
    {
        $item = $this->createItem();
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_TRUSTED);
        self::assertSame(RetrievedItem::TRUST_LEVEL_TRUSTED, $item->getTrustLevel());
        self::assertTrue($item->isTrusted());
    }

    public function testSetTrustLevelSystem(): void
    {
        $item = $this->createItem();
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_SYSTEM);
        self::assertSame(RetrievedItem::TRUST_LEVEL_SYSTEM, $item->getTrustLevel());
        self::assertTrue($item->isSystem());
    }

    public function testSetTrustLevelInvalidValueIgnored(): void
    {
        $item = $this->createItem();
        $item->setTrustLevel('invalid');
        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
    }

    public function testIsTrustedFalseForUntrusted(): void
    {
        $item = $this->createItem();
        self::assertFalse($item->isTrusted());
    }

    public function testIsSystemFalseForUntrusted(): void
    {
        $item = $this->createItem();
        self::assertFalse($item->isSystem());
    }

    public function testIsTrustedFalseForSystem(): void
    {
        $item = $this->createItem();
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_SYSTEM);
        self::assertFalse($item->isTrusted());
    }

    public function testIsSystemFalseForTrusted(): void
    {
        $item = $this->createItem();
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_TRUSTED);
        self::assertFalse($item->isSystem());
    }

    // ---- RetrievalResult ----

    public function testRetrievalResultGetItemsAndQuery(): void
    {
        $items = [$this->createItem(), $this->createItem()];
        $result = new RetrievalResult('search query', $items);
        self::assertSame('search query', $result->getQuery());
        self::assertSame($items, $result->getItems());
        self::assertCount(2, $result->getItems());
    }

    public function testRetrievalResultEmpty(): void
    {
        $result = new RetrievalResult('q');
        self::assertSame([], $result->getItems());
        self::assertNull($result->getBestMatch());
        self::assertSame(0, $result->getCount());
    }

    public function testGetBestMatchReturnsFirst(): void
    {
        $item1 = $this->createItem(0.95);
        $item2 = $this->createItem(0.5);
        $result = new RetrievalResult('q', [$item1, $item2]);
        self::assertSame($item1, $result->getBestMatch());
    }

    public function testGetCount(): void
    {
        $result = new RetrievalResult('q', [$this->createItem(), $this->createItem(), $this->createItem()]);
        self::assertSame(3, $result->getCount());
    }

    public function testGetContextAsStringWithItems(): void
    {
        $item1 = new RetrievedItem($this->createEmbedding('content-1', 'source-1'), 0.9, 'type-1');
        $item2 = new RetrievedItem($this->createEmbedding('content-2', 'source-2'), 0.8, 'type-2');
        $result = new RetrievalResult('q', [$item1, $item2]);
        $context = $result->getContextAsString();
        self::assertStringContainsString('[Source: source-1, Type: type-1]', $context);
        self::assertStringContainsString('content-1', $context);
        self::assertStringContainsString('[Source: source-2, Type: type-2]', $context);
        self::assertStringContainsString('content-2', $context);
        self::assertStringContainsString('---', $context);
    }

    public function testGetContextAsStringWithNullSource(): void
    {
        $item = new RetrievedItem($this->createEmbedding('c', null), 0.9, 't');
        $result = new RetrievalResult('q', [$item]);
        $context = $result->getContextAsString();
        self::assertStringContainsString('[Source: unknown', $context);
    }

    public function testGetContextAsStringEmpty(): void
    {
        $result = new RetrievalResult('q', []);
        self::assertSame('', $result->getContextAsString());
    }

    public function testHasResultsTrueWithItems(): void
    {
        $result = new RetrievalResult('q', [$this->createItem()]);
        self::assertTrue($result->hasResults());
    }

    public function testHasResultsFalseWhenEmpty(): void
    {
        $result = new RetrievalResult('q', []);
        self::assertFalse($result->hasResults());
    }
}
