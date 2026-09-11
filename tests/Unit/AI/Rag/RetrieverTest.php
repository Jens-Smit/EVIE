<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Rag\Retriever;
use App\AI\Rag\RetrievedItem;
use App\AI\Rag\VectorStore;
use App\Entity\Embedding;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RetrieverTest extends TestCase
{
    private VectorStore&MockObject $vectorStore;
    private Retriever $retriever;

    protected function setUp(): void
    {
        $this->vectorStore = $this->createMock(VectorStore::class);
        $this->retriever = new Retriever($this->vectorStore);
    }

    private function makeEmbedding(string $content, string $source = 'docs'): Embedding
    {
        $embedding = new Embedding();
        $embedding->setContent($content);
        $embedding->setSource($source);
        $embedding->setMetadata([]);

        return $embedding;
    }

    public function testRetrieveReturnsEmptyResultWhenNoMatches(): void
    {
        $this->vectorStore->method('search')->willReturn([]);

        $result = $this->retriever->retrieve('query');

        self::assertSame('query', $result->getQuery());
        self::assertSame([], $result->getItems());
        self::assertFalse($result->hasResults());
        self::assertNull($result->getBestMatch());
        self::assertSame(0, $result->getCount());
    }

    public function testRetrieveAcrossMultipleContentTypesAndSortsBySimilarity(): void
    {
        $e1 = $this->makeEmbedding('low');
        $e2 = $this->makeEmbedding('high');

        $this->vectorStore->method('search')->willReturnOnConsecutiveCalls(
            [['embedding' => $e1, 'similarity' => 0.6]],
            [['embedding' => $e2, 'similarity' => 0.9]]
        );

        $result = $this->retriever->retrieve('q', ['content_types' => ['user_profile', 'knowledge'], 'limit' => 5]);

        self::assertTrue($result->hasResults());
        self::assertCount(2, $result->getItems());
        $items = $result->getItems();
        self::assertInstanceOf(RetrievedItem::class, $items[0]);
        self::assertSame(0.9, $items[0]->similarity);
        self::assertSame('knowledge', $items[0]->contentType);
        self::assertSame('high', $items[0]->getContent());
        self::assertSame(0.6, $items[1]->similarity);
        self::assertSame('user_profile', $items[1]->contentType);
        self::assertSame($items[0], $result->getBestMatch());
    }

    public function testRetrieveRespectsLimitAfterSorting(): void
    {
        $e1 = $this->makeEmbedding('a');
        $e2 = $this->makeEmbedding('b');

        $this->vectorStore->method('search')->willReturn([
            ['embedding' => $e1, 'similarity' => 0.5],
            ['embedding' => $e2, 'similarity' => 0.8],
        ]);

        $result = $this->retriever->retrieve('q', ['limit' => 1]);

        self::assertCount(1, $result->getItems());
        self::assertSame(0.8, $result->getItems()[0]->similarity);
    }

    public function testRetrievePassesUserIdentifierToVectorStore(): void
    {
        $this->vectorStore->expects(self::once())->method('search')
            ->with('q', 'knowledge', 5, 0.5, 'tenant-a')
            ->willReturn([]);

        $result = $this->retriever->retrieve('q', ['user_identifier' => 'tenant-a', 'content_types' => ['knowledge']]);

        self::assertSame(0, $result->getCount());
    }

    public function testRetrieveUsesDefaultContentTypesWhenOmitted(): void
    {
        $this->vectorStore->expects(self::exactly(4))->method('search')->willReturn([]);

        $this->retriever->retrieve('q');
    }

    public function testRetrieveForTypeDelegatesToRetrieveWithSingleContentType(): void
    {
        $this->vectorStore->expects(self::once())->method('search')
            ->with('q', 'conversation', 3, 0.7)
            ->willReturn([]);

        $result = $this->retriever->retrieveForType('q', 'conversation', 3, 0.7);

        self::assertSame('q', $result->getQuery());
        self::assertFalse($result->hasResults());
    }

    public function testRetrieveForTypeWithUserIdentifierPropagates(): void
    {
        $this->vectorStore->expects(self::once())->method('search')
            ->with('q', 'conversation', 3, 0.7, null)
            ->willReturn([]);

        $result = $this->retriever->retrieveForType('q', 'conversation', 3, 0.7);

        self::assertSame(0, $result->getCount());
    }
}
