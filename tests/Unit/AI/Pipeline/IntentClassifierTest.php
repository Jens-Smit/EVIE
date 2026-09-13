<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\Intent\IntentClassifier;
use App\AI\Pipeline\PipelineContext;
use App\Tests\Stub\StubDeferredResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests fuer den IntentClassifier (Phase 2).
 *
 * Nutzt denselben StubDeferredResult-Mechanismus wie die bestehenden
 * OrchestratorAgentLlmTests, um PlatformInterface::invoke()->asText()
 * deterministisch zu stubben. Verifiziert die vier Intent-Klassen und
 * den konservativen Conversation-Fallback bei LLM-Ausfall.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
final class IntentClassifierTest extends TestCase
{
    public function testClassifiesConversation(): void
    {
        $classifier = $this->buildClassifier('CONVERSATION');

        $intent = $classifier->classify(PipelineContext::create('moin wer bist du', 'u'));

        self::assertSame(Intent::Conversation, $intent);
        self::assertTrue($intent->isDialog());
    }

    public function testClassifiesInformation(): void
    {
        $classifier = $this->buildClassifier('INFORMATION');

        $intent = $classifier->classify(PipelineContext::create('was kannst du', 'u'));

        self::assertSame(Intent::Information, $intent);
        self::assertTrue($intent->isDialog());
    }

    public function testClassifiesUnclear(): void
    {
        $classifier = $this->buildClassifier('UNCLEAR');

        $intent = $classifier->classify(PipelineContext::create('irgendwas', 'u'));

        self::assertSame(Intent::Unclear, $intent);
        self::assertFalse($intent->isDialog());
    }

    public function testClassifiesTask(): void
    {
        $classifier = $this->buildClassifier('TASK');

        $intent = $classifier->classify(PipelineContext::create('rufe API ab', 'u'));

        self::assertSame(Intent::Task, $intent);
        self::assertFalse($intent->isDialog());
    }

    public function testFallsBackToConversationOnUnknownToken(): void
    {
        $classifier = $this->buildClassifier('UNBEKANNT');

        $intent = $classifier->classify(PipelineContext::create('???', 'u'));

        self::assertSame(Intent::Conversation, $intent);
    }

    public function testFallsBackToConversationOnLlmFailure(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('invoke')->willThrowException(new \RuntimeException('Mistral timeout'));

        $classifier = new IntentClassifier($platform, new NullLogger());

        $intent = $classifier->classify(PipelineContext::create('egal', 'u'));

        self::assertSame(Intent::Conversation, $intent);
    }

    private function buildClassifier(string $llmText): IntentClassifier
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('invoke')->willReturn(StubDeferredResult::withText($llmText));

        return new IntentClassifier($platform, new NullLogger());
    }
}
