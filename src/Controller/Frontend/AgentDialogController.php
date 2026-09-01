<?php
// src/Controller/Frontend/AgentDialogController.php
namespace App\Controller\Frontend;

use App\Repository\AgentHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AgentDialogController extends AbstractController
{
    #[Route('/dialog', name: 'frontend_agent_dialog', methods: ['GET'])]
    public function dialog(#[CurrentUser] ?UserInterface $user = null): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }
        
        // Willkommensnachricht als strukturierte Nachricht
        $messages = [
            ['role' => 'system', 'content' => 'Willkommen beim EVIE-Agenten! Wie kann ich dir helfen?'],
        ];

        return $this->render('agent/dialog.html.twig', [
            'messages' => $messages,
            'continuing_conversation' => false,
            'conversation_id' => null,
            'userIdentifier' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('/history', name: 'frontend_agent_history', methods: ['GET'])]
    public function history(
        Request $request, 
        AgentHistoryRepository $historyRepo,
        #[CurrentUser] ?UserInterface $user = null
    ): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }
        
        $userIdentifier = $user->getUserIdentifier();
        
        // Hole alle Eintrge fr den Benutzer
        $entries = $historyRepo->findByUserIdentifier($userIdentifier);
        
        // Sortiere die Eintrge absteigend nach Datum (aktuellste zuerst)
        usort($entries, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });
        
        // Konvertiere die Eintrge in ein fr das Template geeignetes Format
        $history = [];
        foreach ($entries as $entry) {
            // Erst Details prfen (neues Format), dann action (altes Format)
            $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];
            if (empty($details)) {
                // Altes Format: action enthlt JSON wie {"type":"dialog"}
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
    public function continueConversation(
        int $id, 
        Request $request, 
        AgentHistoryRepository $historyRepo,
        #[CurrentUser] ?UserInterface $user = null
    ): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }
        
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
        
        // Baue die Nachrichten fr den Chat auf
        $messages = [];
        
        // System-Nachricht hinzufgen
        $messages[] = ['role' => 'system', 'content' => 'Fortsetzung der Konversation vom ' . $entry->getCreatedAt()->format('d.m.Y H:i:s')];
        
        // User-Nachricht hinzufgen
        if (isset($details['input']['message'])) {
            $messages[] = ['role' => 'user', 'content' => $details['input']['message']];
        }
        
        // Agent-Antwort hinzufgen
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
