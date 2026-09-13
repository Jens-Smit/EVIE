<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Intent;

use App\AI\Pipeline\PipelineContext;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Phase 2: Klassifiziert die konkrete Nachricht in einen Intent.
 *
 * Verwendet PlatformInterface::invoke('mistral-small-latest') analog der
 * bisherigen OrchestratorDialogService::classifyIntent()-Logik und faellt
 * bei Fehler konservativ auf Conversation zurueck, damit aus Fehlern keine
 * ungewollten Tools oder HITL-Freigaben entstehen. Keine Mocks, keine
 * Fantasie-Tools; der Prompt ist der bereits existierende
 * Intent-Klassifizierungs-Prompt.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
final class IntentClassifier implements IntentClassifierInterface
{
    private PlatformInterface $platform;
    private LoggerInterface $logger;

    public function __construct(PlatformInterface $platform, LoggerInterface $logger)
    {
        $this->platform = $platform;
        $this->logger = $logger;
    }

    public function classify(PipelineContext $context): Intent
    {
        try {
            $prompt = $this->buildIntentClassificationPrompt($context->getMessage());
            $messages = new MessageBag(Message::ofUser($prompt));
            $result = $this->platform->invoke('mistral-small-latest', $messages)->asText();
            $result = strtoupper(trim($result));

            return match ($result) {
                'INFORMATION' => Intent::Information,
                'UNCLEAR' => Intent::Unclear,
                'TASK' => Intent::Task,
                default => Intent::Conversation,
            };
        } catch (\Exception $e) {
            $this->logger->warning('Intent-Klassifizierung fehlgeschlagen, verwende Fallback Conversation: ' . $e->getMessage());
            return Intent::Conversation;
        }
    }

    private function buildIntentClassificationPrompt(string $userMessage): string
    {
        return "Klassifiziere die folgende User-Anfrage. Antworte ausschliesslich mit einem der Woerter CONVERSATION, INFORMATION, UNCLEAR oder TASK.\n\n"
            . "- CONVERSATION: Begruessung, Identitaetsfrage (wer bist du, was kannst du), allgemeine "
            . "  Unterhaltung, Strategie-Diskussion, Brainstorming.\n"
            . "- INFORMATION: Ein Informationswunsch, der mit vorhandenem Kontext/dem Dialogverlauf "
            . "  direkt mit Text beantwortet werden kann (z.B. 'was kannst du', 'erklaere mir die Strategie', "
            . "  'welche Tools hast du').\n"
            . "- UNCLEAR: Die Anfrage ist mehrdeutig oder unvollstaendig; der Agent muesste erst "
            . "  rueckfragen, bevor er handeln kann.\n"
            . "- TASK: Eine konkrete, ausfuehrbare Aktion, die ein Werkzeug erfordert, z.B. das Abrufen "
            . "  einer externen API, die Analyse einer konkreten Datei, das Versenden einer Nachricht oder "
            . "  eine Datenbankabfrage.\n\n"
            . 'Anfrage: "' . $userMessage . '"';
    }
}
