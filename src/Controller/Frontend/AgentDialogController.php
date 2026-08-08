<?php
// src/Controller/Frontend/AgentDialogController.php
namespace App\Controller\Frontend;

use App\Repository\AgentHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
        
        // Konvertiere die Einträge in ein für das Template geeignetes Format
        $history = [];
        foreach ($entries as $entry) {
            $history[] = [
                'timestamp' => $entry->getExecutedAt(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $entry->getInput()['message'] ?? 'Unbekannte Nachricht',
                    ],
                    [
                        'role' => 'agent',
                        'content' => $entry->getOutput()['response'] ?? $entry->getOutput()['error'] ?? 'Keine Antwort',
                    ],
                ],
            ];
        }

        return $this->render('agent/history.html.twig', [
            'history' => $history,
        ]);
    }
}
