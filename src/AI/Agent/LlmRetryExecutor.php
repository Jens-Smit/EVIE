<?php

declare(strict_types=1);

namespace App\AI\Agent;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * LLM-Retry/Backoff-Wrapper fuer Mistral/Gemini-LLM-Calls (P2-E / P1-1).
 *
 * Umschliesst LLM-Aufrufe mit einer konfigurierbaren Retry-Strategie
 * (exponentielles Backoff), um transiente Fehler (Rate-Limits 429,
 * Netzwerk-Timeouts, 5xx der Provider) abzufangen. NICHT retried werden
 * 4xx-Validierungsfehler oder Content-Policy-Ablehnungen, die durch
 * Wiederholung nicht behoben werden.
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
     * @param callable(MessageBag): ResultInterface $llmCall
     */
    public function executeWithRetry(MessageBag $messages, callable $llmCall): ResultInterface
    {
        $attempt = 0;
        $delay = $this->initialDelayMs;

        while (true) {
            $attempt++;
            try {
                return $llmCall($messages);
            } catch (Throwable $e) {
                if ($attempt >= $this->maxRetries || !$this->isTransient($e)) {
                    if (!$this->isTransient($e)) {
                        $this->logger->debug('LLM-Call fehlgeschlagen (nicht-transient), kein Retry', [
                            'error' => $e->getMessage(),
                            'exception' => $e::class,
                        ]);
                    } else {
                        $this->logger->error('LLM-Call endgueltig fehlgeschlagen nach {attempts} Versuchen', [
                            'attempts' => $attempt,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    throw $e;
                }

                $this->logger->warning('LLM-Call fehlgeschlagen, Retry in {delay}ms (Versuch {attempt}/{max})', [
                    'delay' => $delay,
                    'attempt' => $attempt,
                    'max' => $this->maxRetries,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
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
    public function callAgentWithRetry(AgentInterface $agent, MessageBag $messages): ResultInterface
    {
        return $this->executeWithRetry($messages, static fn (MessageBag $m) => $agent->call($m));
    }

    /**
     * Prueft, ob eine Exception einen transienten Fehler darstellt, der
     * durch Wiederholung behoben werden kann.
     *
     * Transiente Fehler:
     * - TransportExceptionInterface (Netzwerk, Timeout, Connection-Reset)
     * - HTTP 429 (Too Many Requests / Rate-Limit)
     * - HTTP 5xx (Server-Fehler des Providers)
     *
     * Nicht-transiente Fehler (kein Retry):
     * - HTTP 4xx ausser 429 (Validierung, Auth, Content-Policy)
     * - Logik-Exceptions, TypeError, etc.
     */
    private function isTransient(Throwable $e): bool
    {
        // Symfony HttpClient Transport-Exceptions (Netzwerk/Timeout/Reset)
        if ($e instanceof TransportExceptionInterface) {
            return true;
        }

        // P2-3: Strukturierte HTTP-Status-Code-Extraktion vor String-Matching.
        // HttpExceptionInterface hat eine Response mit getStatusCode().
        if ($e instanceof HttpExceptionInterface) {
            try {
                $statusCode = $e->getResponse()->getStatusCode();
                // 429 (Rate-Limit) und 5xx (Server-Fehler) sind transient
                if ($statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599)) {
                    return true;
                }
                // 4xx ausser 429 sind nicht-transient (Validierung, Auth, Content-Policy)
                if ($statusCode >= 400 && $statusCode < 500) {
                    return false;
                }
            } catch (\Throwable) {
                // Fallback auf String-Matching, wenn Response nicht verfuegbar
            }
        }

        // Fallback: String-Matching fuer Faelle ohne strukturierten Status-Code
        $message = $e->getMessage();
        // HTTP 429 (Rate-Limit) — transiente Ueberlastung
        if (str_contains($message, '429') || str_contains($message, 'Too Many Requests')) {
            return true;
        }
        // HTTP 5xx (Server-Fehler des LLM-Providers)
        if (preg_match('/\b5\d{2}\b/', $message, $matches)) {
            $code = (int) $matches[0];
            if ($code >= 500 && $code <= 599) {
                return true;
            }
        }

        // Generelle Netzwerk-Indikatoren im Fehler-Text
        $transientPatterns = [
            'timed out',
            'timeout',
            'connection reset',
            'connection refused',
            'connection aborted',
            'temporary failure',
            'service unavailable',
            'gateway',
        ];
        $lowerMessage = strtolower($message);
        foreach ($transientPatterns as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
