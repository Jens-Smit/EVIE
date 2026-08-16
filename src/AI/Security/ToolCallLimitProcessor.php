<?php

declare(strict_types=1);

namespace App\AI\Security;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;

/**
 * Hard-Limit fuer Tool-Calls pro Agent-Request (P1-5).
 *
 * Symfony AI erzwingt bereits `max_tool_calls` pro Agent-Call (Default 50),
 * was aber fuer produktive Multi-Tenant-Systeme zu hoch ist (unkontrollierte
 * Mistral-API-Kosten, Endlosschleifen). Dieser native InputProcessor zaehlt
 * die Tool-Calls innerhalb eines Request-Lebenszyklus und wirft eine
 * RuntimeException, sobald der konfigurierte Hard-Limit (Default 20) erreicht
 * ist.
 *
 * Der Zaehler ist pro Input-Instanz (Request-scope), sodass parallele
 * Requests sich nicht beeinflussen. Die Grenze ist bewusst tiefer als der
 * Symfony-AI-Default, um Kosten und Endlosschleifen-Frankensteins zu
 * begrenzen, ohne legitime Multi-Step-Workflows zu blockieren.
 *
 * Blueprint-konform: nativer Symfony AI InputProcessor, kein Eigenbau-
 * Decorator, keine Konstruktor-Injection fuer Tools.
 */
#[AsInputProcessor]
final class ToolCallLimitProcessor implements InputProcessorInterface
{
    /**
     * Default-Hardlimit: 20 Tool-Calls pro Request. Reicht fuer typische
     * Multi-Step-Workflows (Subagent-Delegation, Tool-Generierung), blockiert
     * aber Endlosschleifen (Agent ruft Tool -> Ergebnis -> neuer Tool-Call).
     */
    public const DEFAULT_LIMIT = 20;

    private int $toolCallCount = 0;

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {
    }

    public function processInput(Input $input): void
    {
        // Zaehle Tool-Calls, die im aktuellen MessageBag bereits vorhanden
        // sind (jeder Tool-Call im Agent-Loop durchlaeuft processInput neu).
        $toolCalls = $this->countToolCalls($input);
        if ($toolCalls > 0) {
            $this->toolCallCount += $toolCalls;
        }

        if ($this->toolCallCount > $this->limit) {
            throw new RuntimeException(sprintf(
                'Tool-Call-Limit erreicht: %d von maximal %d erlaubten Tool-Calls pro Request. '
                .'Der Agent-Loop wurde abgebrochen, um Endlosschleifen und unkontrollierte Kosten zu verhindern.',
                $this->toolCallCount,
                $this->limit,
            ));
        }
    }

    private function countToolCalls(Input $input): int
    {
        $count = 0;
        foreach ($input->getMessageBag()->getMessages() as $message) {
            // ToolCall-Nachrichten enthalten ToolCall-Objekte in ihrem
            // Result/Content. Wir zaehlen Konservativ: jede Nachricht, deren
            // Rolle ToolCall entspricht, erhoht den Zaehler.
            if (method_exists($message, 'getRole')
                && 'tool' === $message->getRole()->value) {
                ++$count;
            }
        }

        return $count;
    }
}
