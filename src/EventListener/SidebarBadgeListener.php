<?php
// src/EventListener/SidebarBadgeListener.php

namespace App\EventListener;

use App\Repository\AgentHistoryRepository;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Twig\Environment;

/**
 * Event-Listener, der die Badge-Zahlen für die Sidebar dynamisch berechnet
 * und sie als globale Twig-Variablen verfügbar macht.
 */
final readonly class SidebarBadgeListener
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private AgentHistoryRepository $historyRepo,
        private Environment $twig,
    ) {
    }

    /**
     * Wird vor dem Rendern eines Controllers ausgelöst.
     * Berechnet die Badge-Zahlen und fügt sie als globale Twig-Variablen hinzu.
     */
    public function onKernelController(ControllerEvent $event): void
    {
        // Nur für HTML-Requests (nicht für API-Calls)
        if (!$event->isMainRequest() || $event->getRequest()->isXmlHttpRequest()) {
            return;
        }

        try {
            // 1. Berechne die Anzahl der ausstehenden Tools
            $pendingToolsCount = $this->toolDefinitionRepo->count([
                'status' => ['pending', 'pending_approval'],
            ]);

            // 2. Berechne die Anzahl der neuen Nachrichten (optional)
            // Hier könntest du eine Logik hinzufügen, um neue Nachrichten zu zählen
            // Für jetzt setzen wir es auf 0, da wir keine Nachrichtenzählung haben
            $pendingMessages = 0;

            // 3. Füge die Variablen als globale Twig-Variablen hinzu
            $this->twig->addGlobal('pending_tools_count', $pendingToolsCount);
            $this->twig->addGlobal('pending_messages', $pendingMessages);

        } catch (\Exception $e) {
            // Falls ein Fehler auftritt, setze die Werte auf 0
            $this->twig->addGlobal('pending_tools_count', 0);
            $this->twig->addGlobal('pending_messages', 0);
        }
    }
}
