<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * StubAgent - deterministisches Test-Double fuer Symfony AI AgentInterface (v0.12).
 *
 * Ermoeglicht Unit-/Functional-Tests der LLM-abhaengigen EVIE-Komponenten
 * (Orchestrator, Onboarding, ToolDefinitionGenerator), OHNE echte Mistral-
 * Aufrufe zu taetigen. Der LLM-Abruf-Pfad wird somit vollstaendig durchlaufen,
 * die Antwort ist aber deterministisch und kostenlos.
 *
 * Der Agent zaehlt mit, wie oft call() aufgerufen wurde, sodass Tests
 * verifizieren koennen, dass die LLM-Aufrufe minimiert sind (z.B. genau
 * 1 Aufruf pro Flow) - die Anforderung "LLM-Aufrufe beim Testen begrenzt".
 *
 * Die echten Mistral-Aufrufe werden durch einen separaten CI-Job
 * (e2e-llm) mit echten MISTRAL_API_KEY/TAVILY_API_KEY getestet.
 */
final class StubAgent implements AgentInterface
{
    /** @var list<string> Antworten, die nacheinander zurueckgegeben werden */
    private array $responses;

    /** @var int Anzahl der durchgefuehrten LLM-Aufrufe */
    private int $callCount = 0;

    /** @var list<MessageBag> Protokoll der gesendeten MessageBags */
    private array $sentMessages = [];

    private string $name = 'stub_agent';

    /**
     * @param string|string[] $response Eine Antwort oder eine Liste von Antworten (werden nacheinander geliefert)
     */
    public function __construct(string|array $response)
    {
        $this->responses = is_array($response) ? array_values($response) : [$response];
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
    {
        if ($input instanceof MessageBag) {
            $this->sentMessages[] = $input;
        } else {
            // string|UserMessage -> normalisiert zu MessageBag wie der echte Agent.
            $this->sentMessages[] = new MessageBag(
                $input instanceof UserMessage ? $input : \Symfony\AI\Platform\Message\Message::ofUser($input)
            );
        }

        $response = $this->responses[$this->callCount] ?? ($this->responses[count($this->responses) - 1] ?? '');
        $this->callCount++;

        return new TextResult($response);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Setzt den Agent-Namen (fuer Multi-Agent-Szenarien in Tests).
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Anzahl der durchgefuehrten LLM-Aufrufe (fuer Minimierungs-Assertions).
     */
    public function getCallCount(): int
    {
        return $this->callCount;
    }

    /**
     * Die gesendeten MessageBags (fuer Assertions auf Prompt-Inhalt).
     *
     * @return list<MessageBag>
     */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }
}
