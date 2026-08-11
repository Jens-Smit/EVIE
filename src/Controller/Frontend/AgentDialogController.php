<?php
// src/Controller/Frontend/AgentDialogController.php
namespace App\Controller\Frontend;

use App\Repository\AgentHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AgentDialogController extends AbstractController
{
    #[Route('/dialog', name: 'frontend_agent_dialog', methods: ['GET'])]
    public function dialog(Request $request): Response
    {
        // Willkommensnachricht als strukturierte Nachricht
        $messages = [
            ['role' => 'system', 'content' => 'Willkommen beim EVIE-Agenten! Wie kann ich dir helfen?'],
        ];

        return $this->render('agent/dialog.html.twig', [
            'messages' => $messages,
        ]);
    }

    #[Route('/history', name: 'frontend_agent_history', methods: ['GET'])]
    public function history(Request $request, AgentHistoryRepository $historyRepo): Response
    {
        // Standardmäßig den Verlauf für 'default_user' laden
        $userIdentifier = 'default_user';
        
        // Hole alle Einträge für den Benutzer
        $entries = $historyRepo->findByUserIdentifier($userIdentifier);
        
        // Sortiere die Einträge absteigend nach Datum (aktuellste zuerst)
        usort($entries, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });
        
        // Konvertiere die Einträge in ein für das Template geeignetes Format
        $history = [];
        foreach ($entries as $entry) {
            // Erst Details prüfen (neues Format), dann action (altes Format)
            $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];
            if (empty($details)) {
                // Altes Format: action enthält JSON wie {"type":"dialog"}
                $action = json_decode($entry->getAction(), true);
                if (is_array($action)) {
                    $details = $action;
                }
            }
            
            $history[] = [
                'id' => $entry->getId(),
                'timestamp' => $entry->getCreatedAt(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $details['input']['message'] ?? 'Unbekannte Nachricht',
                    ],
                    [
                        'role' => 'agent',
                        'content' => $details['output']['response'] ?? $details['output']['error'] ?? 'Keine Antwort',
                    ],
                ],
            ];
        }

        return $this->render('agent/history.html.twig', [
            'history' => $history,
        ]);
    }
    
    #[Route('/history/continue/{id}', name: 'frontend_agent_history_continue', methods: ['GET'])]
    public function continueConversation(int $id, Request $request, AgentHistoryRepository $historyRepo): Response
    {
        // Hole den historischen Eintrag
        $entry = $historyRepo->find($id);
        
        if (!$entry) {
            throw $this->createNotFoundException('Eintrag nicht gefunden');
        }
        
        // Extrahiere die Nachrichten aus dem Eintrag
        $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];
        if (empty($details)) {
            $action = json_decode($entry->getAction(), true);
            if (is_array($action)) {
                $details = $action;
            }
        }
        
        // Baue die Nachrichten für den Chat auf
        $messages = [];
        
        // System-Nachricht hinzufügen
        $messages[] = ['role' => 'system', 'content' => 'Fortsetzung der Konversation vom ' . $entry->getCreatedAt()->format('d.m.Y H:i:s')];
        
        // User-Nachricht hinzufügen
        if (isset($details['input']['message'])) {
            $messages[] = ['role' => 'user', 'content' => $details['input']['message']];
        }
        
        // Agent-Antwort hinzufügen
        if (isset($details['output']['response'])) {
            $messages[] = ['role' => 'agent', 'content' => $details['output']['response']];
        } elseif (isset($details['output']['error'])) {
            $messages[] = ['role' => 'agent', 'content' => $details['output']['error']];
        }
        
        return $this->render('agent/dialog.html.twig', [
            'messages' => $messages,
            'continuing_conversation' => true,
            'conversation_id' => $id,
        ]);
    }
}
