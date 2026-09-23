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
 * SetupTask erkennt mehrstufige Aufbau-Aufgaben (CEO-Agent, Unternehmensaufbau,
 * Sub-Agenten-Setup, mehrstufige Strategie mit konkreten Ausfuehrungsschritten).
 * Diese enden nicht als Dialog, sondern erzeugen in Phase 3 einen persistenten
 * Plan mit AgentGoal + Aufgabenschritten.
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
            $this->logger->info('IntentClassifier: Klassifizierungs-Prompt an LLM', [
                'message' => $context->getMessage(),
                'model' => 'mistral-small-latest',
            ]);
            $messages = new MessageBag(Message::ofUser($prompt));
            $result = $this->platform->invoke('mistral-small-latest', $messages)->asText();
            $result = strtoupper(trim($result));
            $this->logger->info('IntentClassifier: Rohe LLM-Antwort', [
                'raw_result' => $result,
            ]);

            return match ($result) {
                'INFORMATION' => Intent::Information,
                'UNCLEAR' => Intent::Unclear,
                'TASK' => Intent::Task,
                'SETUP_TASK' => Intent::SetupTask,
                default => Intent::Conversation,
            };
        } catch (\Exception $e) {
            $this->logger->error('Intent-Klassifizierung fehlgeschlagen, verwende Fallback Conversation', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'user_message' => $context->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Intent::Conversation;
        }
    }

    private function buildIntentClassificationPrompt(string $userMessage): string
    {
        return "Klassifiziere die folgende User-Anfrage. Antworte ausschliesslich mit einem der Woerter CONVERSATION, INFORMATION, UNCLEAR, TASK oder SETUP_TASK.\n\n"
            . "- CONVERSATION: Begruessung, Identitaetsfrage (wer bist du, was kannst du), allgemeine \"\n"
            . "  Unterhaltung, einfache Strategie-Diskussion ohne konkrete Ausfuehrungsaufforderung, Brainstorming.\n"
            . "- INFORMATION: Ein Informationswunsch, der mit vorhandenem Kontext/dem Dialogverlauf \"\n"
            . "  direkt mit Text beantwortet werden kann (z.B. 'was kannst du', 'erklaere mir die Strategie', \"\n"
            . "  'welche Tools hast du').\n"
            . "- UNCLEAR: Die Anfrage ist mehrdeutig oder unvollstaendig; der Agent muesste erst \"\n"
            . "  rueckfragen, bevor er handeln kann.\n"
            . "- TASK: Eine einzelne konkrete, ausfuehrbare Aktion, die ein Werkzeug erfordert, z.B. das Abrufen \"\n"
            . "  einer externen API, die Analyse einer konkreten Datei, das Versenden einer Nachricht oder \"\n"
            . "  eine Datenbankabfrage.\n"
            . "- SETUP_TASK: NUR eine dauerhaft-autonome Aufgabe, die EVIE als persistentes Ziel ueber "
            . "  mehrere Dialogrunden/Sessions eigenverantwortlich abarbeiten soll (Blueprint Luecke 2). "
            . "  Indikatoren: 'EVIE soll mein Unternehmen aufbauen', 'agiere dauerhaft als mein CEO', "
            . "  'uebernimm laufend', 'richten dir selbst ein', mehrjaehrige autonome Ziele. "
            . "  NICHT SETUP_TASK: einmalige Liefergegenstaende wie 'erstelle mir einen Businessplan', "
            . "  'erstelle ein Strategiedokument', 'recherchiere X und analysiere Y' - auch wenn dafuer "
            . "  mehrere Schritte noetig sind. Solche Aufgaben sind TASK: die Pipeline plant und fuehrt "
            . "  sie sofort aus, ohne persistentes AgentGoal und ohne zusaetzliche Freigabe.\n\n"
            . 'Anfrage: "' . $userMessage . '"';
    }
}
