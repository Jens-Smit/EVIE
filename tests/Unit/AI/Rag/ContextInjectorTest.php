<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Rag\ContextInjector;
use App\AI\Rag\RetrievalResult;
use App\AI\Rag\Retriever;
use App\AI\Rag\RetrievedItem;
use App\Entity\Embedding;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Unit-Tests für den nativen ContextInjector InputProcessor (Blueprint §4.H).
 *
 * Verifiziert, dass processInput() relevante Kontext-Informationen über den
 * Retriever abruft und als SystemMessage in den MessageBag einfügt.
 */
final class ContextInjectorTest extends TestCase
{
    public function testProcessInputInjectsContextAsSystemMessage(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')
            ->willReturn(new RetrievalResult('query', [
                $this->createItem('Relevant context about CSV parsing', 0.95, 'knowledge'),
            ]));

        $injector = new ContextInjector($retriever);
        $messageBag = new MessageBag(Message::ofUser('Analysiere diese CSV-Datei'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        $messages = $input->getMessageBag()->getMessages();
        self::assertGreaterThanOrEqual(2, count($messages));
    }

    public function testProcessInputDoesNothingWithoutResults(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')
            ->willReturn(new RetrievalResult('query', []));

        $injector = new ContextInjector($retriever);
        $messageBag = new MessageBag(Message::ofUser('Frage ohne Kontext'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        self::assertCount(1, $input->getMessageBag()->getMessages());
    }

    public function testProcessInputDoesNothingWithEmptyQuery(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $injector = new ContextInjector($retriever);

        $messageBag = new MessageBag();
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        self::assertCount(0, $input->getMessageBag()->getMessages());
    }

    public function testLegacyInjectMethodReplacesContextPlaceholder(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')
            ->willReturn(new RetrievalResult('query', [
                $this->createItem('Wichtige Infos', 0.9, 'knowledge'),
            ]));

        $injector = new ContextInjector($retriever);
        $result = $injector->inject('Prompt mit {context}', 'query');

        self::assertStringContainsString('Wichtige Infos', $result);
        self::assertStringNotContainsString('{context}', $result);
    }

    private function createItem(string $content, float $similarity, string $contentType): RetrievedItem
    {
        $embedding = new Embedding();
        $embedding->setContent($content);

        return new RetrievedItem($embedding, $similarity, $contentType);
    }
}
