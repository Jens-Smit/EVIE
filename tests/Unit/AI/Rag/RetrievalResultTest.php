<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Rag\RetrievedItem;
use App\AI\Rag\RetrievalResult;
use App\Entity\Embedding;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer RetrievalResult und RetrievedItem.
 */
final class RetrievalResultTest extends TestCase
{
    public function testGetters(): void
    {
        $items = [$this->createItem('content A', 'doc'), $this->createItem('content B', 'doc')];
        $result = new RetrievalResult('query', $items);

        self::assertSame('query', $result->getQuery());
        self::assertSame($items, $result->getItems());
        self::assertSame(2, $result->getCount());
        self::assertTrue($result->hasResults());
        self::assertSame($items[0], $result->getBestMatch());
    }

    public function testEmptyResult(): void
    {
        $result = new RetrievalResult('q');

        self::assertSame(0, $result->getCount());
        self::assertFalse($result->hasResults());
        self::assertNull($result->getBestMatch());
        self::assertSame('', $result->getContextAsString());
    }

    public function testContextAsString(): void
    {
        $item = $this->createItem('Hello world', 'text');
        $result = new RetrievalResult('q', [$item]);

        $ctx = $result->getContextAsString();
        self::assertStringContainsString('Hello world', $ctx);
        self::assertStringContainsString('Type: text', $ctx);
    }

    public function testContextAsStringWithUnknownSource(): void
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn('C');
        $embedding->method('getSource')->willReturn(null);
        $embedding->method('getMetadata')->willReturn([]);
        $item = new RetrievedItem($embedding, 0.9, 'text');
        $result = new RetrievalResult('q', [$item]);

        self::assertStringContainsString('unknown', $result->getContextAsString());
    }

    public function testRetrievedItemTrustLevelDefaultUntrusted(): void
    {
        $item = $this->createItem('c', 't');

        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
        self::assertFalse($item->isTrusted());
        self::assertFalse($item->isSystem());
    }

    public function testRetrievedItemTrustLevelFromMetadata(): void
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn('c');
        $embedding->method('getMetadata')->willReturn(['trust_level' => 'trusted']);

        $item = new RetrievedItem($embedding, 0.5, 'doc');

        self::assertSame(RetrievedItem::TRUST_LEVEL_TRUSTED, $item->getTrustLevel());
        self::assertTrue($item->isTrusted());
    }

    public function testRetrievedItemSystemTrustLevelFromMetadata(): void
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn('c');
        $embedding->method('getMetadata')->willReturn(['trust_level' => 'system']);

        $item = new RetrievedItem($embedding, 0.5, 'doc');

        self::assertSame(RetrievedItem::TRUST_LEVEL_SYSTEM, $item->getTrustLevel());
        self::assertTrue($item->isSystem());
    }

    public function testRetrievedItemInvalidMetadataTrustLevelIgnored(): void
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn('c');
        $embedding->method('getMetadata')->willReturn(['trust_level' => 'bogus']);

        $item = new RetrievedItem($embedding, 0.5, 'doc');

        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
    }

    public function testRetrievedItemSetTrustLevel(): void
    {
        $item = $this->createItem('c', 't');
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_TRUSTED);

        self::assertSame(RetrievedItem::TRUST_LEVEL_TRUSTED, $item->getTrustLevel());
        self::assertTrue($item->isTrusted());
    }

    public function testRetrievedItemSetInvalidTrustLevelIgnored(): void
    {
        $item = $this->createItem('c', 't');
        $item->setTrustLevel('invalid');

        self::assertSame(RetrievedItem::TRUST_LEVEL_UNTRUSTED, $item->getTrustLevel());
    }

    public function testRetrievedItemGetContentAndSource(): void
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn('the content');
        $embedding->method('getSource')->willReturn('file.pdf');
        $embedding->method('getMetadata')->willReturn(['a' => 1]);

        $item = new RetrievedItem($embedding, 0.8, 'pdf');

        self::assertSame('the content', $item->getContent());
        self::assertSame('file.pdf', $item->getSource());
        self::assertSame(['a' => 1], $item->getMetadata());
    }

    private function createItem(string $content, string $contentType, ?string $source = 'src'): RetrievedItem
    {
        $embedding = $this->createMock(Embedding::class);
        $embedding->method('getContent')->willReturn($content);
        $embedding->method('getSource')->willReturn($source);
        $embedding->method('getMetadata')->willReturn([]);

        return new RetrievedItem($embedding, 0.9, $contentType);
    }
}
