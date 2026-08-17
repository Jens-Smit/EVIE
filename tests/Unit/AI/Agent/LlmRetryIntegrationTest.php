<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\LlmRetryExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * P1-1 Integrationstest: verifiziert, dass LlmRetryExecutor tatsächlich
 * in den OrchestratorDialogService-Aufrufpfad eingebaut ist und Retry
 * bei transienten Fehlern durchführt.
 *
 * Test-Szenario: AgentInterface-Double wirft beim ersten Aufruf eine
 * transiente Exception (TransportException), beim zweiten Aufruf
 * erfolgreiche Antwort. LlmRetryExecutor muss den zweiten Versuch
 * unternehmen und das Ergebnis zurückliefern.
 */
final class LlmRetryIntegrationTest extends TestCase
{
    public function testRetryOnTransientFailureThenSuccess(): void
    {
        $callCount = 0;
        $agent = $this->createMock(AgentInterface::class);

        $agent->method('call')->willReturnCallback(function (MessageBag $messages) use (&$callCount): TextResult {
            $callCount++;
            if ($callCount === 1) {
                // Erster Aufruf: transienter Fehler (TransportException = Netzwerk/Timeout)
                throw new class('Connection timed out') extends \Exception implements TransportExceptionInterface {
                    public function getResponse(): ?ResponseInterface
                    {
                        return null;
                    }
                };
            }
            // Zweiter Aufruf: Erfolg
            return new TextResult('Erfolgreiche Antwort nach Retry', 'test-model');
        });

        $executor = new LlmRetryExecutor(
            $this->createMock(LoggerInterface::class),
            maxRetries: 3,
            initialDelayMs: 1, // 1ms für Test-Geschwindigkeit
        );

        $messages = new MessageBag(Message::ofUser('Test-Anfrage'));
        $result = $executor->callAgentWithRetry($agent, $messages);

        self::assertSame(2, $callCount, 'LlmRetryExecutor muss nach transientem Fehler erneut aufrufen.');
        self::assertStringContainsString("Erfolgreiche Antwort", $result->getContent() ?? "", "Retry muss erfolgreiche Antwort liefern.");
    }

    public function testNoRetryOnNonTransientFailure(): void
    {
        $callCount = 0;
        $agent = $this->createMock(AgentInterface::class);

        $agent->method('call')->willReturnCallback(function (MessageBag $messages) use (&$callCount): TextResult {
            $callCount++;
            // Nicht-transienter Fehler: 400 Bad Request (Validierung)
            throw new \RuntimeException('HTTP 400 Bad Request: invalid input');
        });

        $executor = new LlmRetryExecutor(
            $this->createMock(LoggerInterface::class),
            maxRetries: 3,
            initialDelayMs: 1,
        );

        $messages = new MessageBag(Message::ofUser('Test-Anfrage'));

        $this->expectException(\RuntimeException::class);
        try {
            $executor->callAgentWithRetry($agent, $messages);
        } finally {
            // Bei nicht-transientem Fehler darf kein Retry erfolgen
            self::assertSame(1, $callCount, 'Bei nicht-transientem Fehler darf kein Retry erfolgen.');
        }
    }

    public function testRetryOnHttp429RateLimit(): void
    {
        $callCount = 0;
        $agent = $this->createMock(AgentInterface::class);

        $agent->method('call')->willReturnCallback(function (MessageBag $messages) use (&$callCount): TextResult {
            $callCount++;
            if ($callCount === 1) {
                // HTTP 429 Too Many Requests — transiente Rate-Limit
                throw new \RuntimeException('HTTP 429: Too Many Requests');
            }
            return new TextResult('OK nach Rate-Limit', 'test-model');
        });

        $executor = new LlmRetryExecutor(
            $this->createMock(LoggerInterface::class),
            maxRetries: 3,
            initialDelayMs: 1,
        );

        $messages = new MessageBag(Message::ofUser('Test-Anfrage'));
        $result = $executor->callAgentWithRetry($agent, $messages);

        self::assertSame(2, $callCount, 'Bei HTTP 429 muss ein Retry erfolgen.');
    }

    public function testRetryOnHttp503ServerError(): void
    {
        $callCount = 0;
        $agent = $this->createMock(AgentInterface::class);

        $agent->method('call')->willReturnCallback(function (MessageBag $messages) use (&$callCount): TextResult {
            $callCount++;
            if ($callCount === 1) {
                // HTTP 503 Service Unavailable — transienter Server-Fehler
                throw new \RuntimeException('HTTP 503: Service Unavailable');
            }
            return new TextResult('OK nach 503', 'test-model');
        });

        $executor = new LlmRetryExecutor(
            $this->createMock(LoggerInterface::class),
            maxRetries: 3,
            initialDelayMs: 1,
        );

        $messages = new MessageBag(Message::ofUser('Test-Anfrage'));
        $result = $executor->callAgentWithRetry($agent, $messages);

        self::assertSame(2, $callCount, 'Bei HTTP 503 muss ein Retry erfolgen.');
    }
}
