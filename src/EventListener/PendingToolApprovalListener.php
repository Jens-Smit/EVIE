<?php
// src/EventListener/PendingToolApprovalListener.php

namespace App\EventListener;

use App\Event\PendingToolApprovalEvent;
use App\Entity\ToolDefinition;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener für PendingToolApprovalEvent - sendet Benachrichtigungen an den User
 * und aktualisiert das DynamicSkillRegistry nach Genehmigung.
 */
final readonly class PendingToolApprovalListener
{
    public function __construct(
        private NotifierInterface $notifier,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Wird ausgelöst, wenn ein neues Tool auf Freigabe wartet.
     */
    public function onPendingToolApproval(PendingToolApprovalEvent $event): void
    {
        $toolDefinition = $event->getToolDefinition();
        $userIdentifier = $event->getUserIdentifier();

        $this->logger->info('Neues Tool wartet auf Freigabe', [
            'tool_name' => $toolDefinition->getName(),
            'user_identifier' => $userIdentifier,
            'tool_id' => $toolDefinition->getId(),
        ]);

        // 1. Benachrichtigung per Chat/Notifier senden
        $this->sendNotification($toolDefinition, $userIdentifier);

        // 2. DynamicSkillRegistry aktualisieren, falls Tool genehmigt wird
        // Dies wird jetzt direkt im ToolApprovalController erledigt
        // Aber wir können hier zusätzliche Logik hinzufügen, falls benötigt
    }

    /**
     * Sendet eine Benachrichtigung über den Symfony Notifier.
     */
    private function sendNotification(ToolDefinition $toolDefinition, string $userIdentifier): void
    {
        // Generiere die URL für die Freigabe
        $approvalUrl = $this->urlGenerator->generate('app_tool_approve_api', [
            'id' => $toolDefinition->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $rejectUrl = $this->urlGenerator->generate('app_tool_reject_api', [
            'id' => $toolDefinition->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        // Erstelle eine Chat-Nachricht (kann an Slack, Telegram, etc. gesendet werden)
        $message = new ChatMessage(
            sprintf(
                "🤖 *Neues AI-Tool wartet auf Freigabe*\n\n" .
                "**Tool:** `%s`\n" .
                "**Beschreibung:** %s\n\n" .
                "👍 [Freigeben](%s)\n" .
                "👎 [Ablehnen](%s)",
                $toolDefinition->getName(),
                $toolDefinition->getDescription(),
                $approvalUrl,
                $rejectUrl
            )
        );

        // Sende an den User (hier muss der Recipient angepasst werden)
        $recipient = new Recipient(
            $userIdentifier,
            null,
            'chat' // Standard-Kanal (kann in der Konfiguration angepasst werden)
        );

        try {
            $this->notifier->send($message, $recipient);
            $this->logger->info('Benachrichtigung für Tool-Freigabe gesendet', [
                'tool_id' => $toolDefinition->getId(),
                'user_identifier' => $userIdentifier,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Senden der Benachrichtigung: ' . $e->getMessage());
        }
    }

    /**
     * Wird ausgelöst, wenn ein Tool genehmigt wird.
     * Aktualisiert das DynamicSkillRegistry.
     */
    public function onToolApproved(PendingToolApprovalEvent $event): void
    {
        $toolDefinition = $event->getToolDefinition();

        // Die native DynamicToolbox liest approved Tools live aus der Datenbank
        // (Blueprint §4.B) — ein Registry-Update ist nicht erforderlich.
        if ('approved' === $toolDefinition->getStatus()) {
            $this->logger->info('Tool genehmigt und über DynamicToolbox verfügbar', [
                'tool_id' => $toolDefinition->getId(),
                'tool_name' => $toolDefinition->getName(),
            ]);
        }
    }
}