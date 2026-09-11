<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Rag\ContextInjector;
use App\AI\Rag\RetrievalResult;
use App\AI\Rag\Retriever;
use App\AI\Rag\RetrievedItem;
use App\Entity\Embedding;
use App\Security\UserContext;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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

        $injector = new ContextInjector($retriever, $this->createUserContext());
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

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $messageBag = new MessageBag(Message::ofUser('Frage ohne Kontext'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        self::assertCount(1, $input->getMessageBag()->getMessages());
    }

    public function testProcessInputDoesNothingWithEmptyQuery(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $injector = new ContextInjector($retriever, $this->createUserContext());

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

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $result = $injector->inject('Prompt mit {context}', 'query');

        self::assertStringContainsString('Wichtige Infos', $result);
        self::assertStringNotContainsString('{context}', $result);
    }

    public function testLegacyInjectAppendsContextWithoutPlaceholder(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')
            ->willReturn(new RetrievalResult('query', [
                $this->createItem('Append info', 0.9, 'knowledge'),
            ]));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $result = $injector->inject('Plain prompt', 'query');

        self::assertStringContainsString('Plain prompt', $result);
        self::assertStringContainsString('Append info', $result);
    }

    public function testLegacyInjectReturnsPromptWhenNoResults(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieve')->willReturn(new RetrievalResult('query', []));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $result = $injector->inject('Prompt ohne Context', 'query');

        self::assertSame('Prompt ohne Context', $result);
    }

    public function testProcessInputUsesTrustedTrustLevelWhenAllItemsTrusted(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $item = $this->createItem('Trusted content', 0.9, 'knowledge');
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_TRUSTED);
        $retriever->method('retrieve')->willReturn(new RetrievalResult('query', [$item]));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $messageBag = new MessageBag(Message::ofUser('Frage'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        $system = $input->getMessageBag()->getMessages()[1]->getContent();
        self::assertStringContainsString('TRUSTED - Vertrauenswuerdig', $system);
        self::assertStringContainsString('vertrauenswuerdige Information', $system);
    }

    public function testProcessInputUsesSystemTrustLevelWhenAllItemsSystem(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $item = $this->createItem('System content', 0.9, 'knowledge');
        $item->setTrustLevel(RetrievedItem::TRUST_LEVEL_SYSTEM);
        $retriever->method('retrieve')->willReturn(new RetrievalResult('query', [$item]));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $messageBag = new MessageBag(Message::ofUser('Frage'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        $system = $input->getMessageBag()->getMessages()[1]->getContent();
        self::assertStringContainsString('SYSTEM - System-Content', $system);
        self::assertStringContainsString('System-Quellen', $system);
    }

    public function testProcessInputFallsBackToUntrustedForMixedTrustLevels(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $item1 = $this->createItem('A', 0.9, 'knowledge');
        $item1->setTrustLevel(RetrievedItem::TRUST_LEVEL_TRUSTED);
        $item2 = $this->createItem('B', 0.8, 'knowledge');
        $item2->setTrustLevel(RetrievedItem::TRUST_LEVEL_UNTRUSTED);
        $retriever->method('retrieve')->willReturn(new RetrievalResult('query', [$item1, $item2]));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $messageBag = new MessageBag(Message::ofUser('Frage'));
        $input = new Input('mistral-small-latest', $messageBag);

        $injector->processInput($input);

        $system = $input->getMessageBag()->getMessages()[1]->getContent();
        self::assertStringContainsString('UNTRUSTED', $system);
        self::assertStringContainsString('Prompt-Injection-Schutz', $system);
    }

    public function testSetContextTemplateIsUsed(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $item = $this->createItem('Content X', 0.9, 'knowledge');
        $retriever->method('retrieve')->willReturn(new RetrievalResult('query', [$item]));

        $injector = new ContextInjector($retriever, $this->createUserContext());
        $injector->setContextTemplate('CTX:{context}:END');

        $result = $injector->inject('Plain prompt', 'query');
        self::assertStringContainsString('CTX:', $result);
        self::assertStringContainsString('Content X', $result);
        self::assertStringContainsString(':END', $result);
    }


    private function createUserContext(?string $identifier = null): UserContext
    {
        // UserContext is final and cannot be mocked. We build a real instance
        // with a RequestStack carrying the tenant identifier (the fallback
        // path UserContext uses when no Security-Token is present).
        $request = new Request();
        if (null !== $identifier) {
            $request->attributes->set('_evie_user_identifier', $identifier);
        }
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        return new UserContext($requestStack, $tokenStorage);
    }

    private function createItem(string $content, float $similarity, string $contentType): RetrievedItem
    {
        $embedding = new Embedding();
        $embedding->setContent($content);

        return new RetrievedItem($embedding, $similarity, $contentType);
    }
}
