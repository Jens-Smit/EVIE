<?php

declare(strict_types=1);

namespace App\AI\Agent;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBagInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Throwable;

/**
 * LLM-Retry/Backoff-Wrapper fuer Mistral/Gemini-LLM-Calls (P2-E).
 *
 * Umschliesst LLM-Aufrufe mit einer konfigurierbaren Retry-Strategie
 * (exponentielles Backoff), um transiente Fehler (Rate-Limits, Netzwerk-
 * Timeouts, 5xx der Provider) abzufangen. Die eigentliche LLM-Logik
 * bleibt unangetastet; dieser Wrapper wird nur fuer Aufrufe genutzt, die
 * ausfallsicher sein muessen.
 *
 * Blueprint-konform: kein Eigenbau-Decorator fuer Tools, keine
 * Konstruktor-Injection fuer Tools — dieser Service wrappt nur den
 * Agent-Aufruf.
 */
final class LlmRetryExecutor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3,
        private readonly int $initialDelayMs = 500,
    ) {
    }

    /**
     * Fuehrt einen LLM-Call mit Retry/Backoff aus.
     *
     * @param callable(MessageBagInterface): ResultInterface $llmCall
     */
    public function executeWithRetry(MessageBagInterface $messages, callable $llmCall): ResultInterface
    {
        $attempt = 0;
        $delay = $this->initialDelayMs;

        while (true) {
            $attempt++;
            try {
                return $llmCall($messages);
            } catch (Throwable $e) {
                if ($attempt >= $this->maxRetries) {
                    $this->logger->error('LLM-Call endgueltig fehlgeschlagen nach {attempts} Versuchen', [
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $this->logger->warning('LLM-Call fehlgeschlagen, Retry in {delay}ms (Versuch {attempt}/{max})', [
                    'delay' => $delay,
                    'attempt' => $attempt,
                    'max' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                usleep($delay * 1000);
                // Exponentielles Backoff mit Jitter-Vermeidung (2x pro Versuch)
                $delay *= 2;
            }
        }
    }

    /**
     * Convenience: Agent-Call mit Retry.
     */
    public function callAgentWithRetry(AgentInterface $agent, MessageBagInterface $messages): ResultInterface
    {
        return $this->executeWithRetry($messages, static fn (MessageBagInterface $m) => $agent->call($m));
    }
}
