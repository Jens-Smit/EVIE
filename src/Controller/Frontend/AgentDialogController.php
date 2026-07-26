<?php
// src/Controller/Frontend/AgentDialogController.php
namespace App\Controller\Frontend;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AgentDialogController extends AbstractController
{
    #[Route('/dialog', name: 'frontend_agent_dialog', methods: ['GET'])]
    public function dialog(Request $request): Response
    {
        // Leere Nachrichten für den Anfang
        $messages = [
            ['role' => 'system', 'content' => 'Willkommen beim EVIE-Agenten! Wie kann ich dir helfen?'],
        ];

        return $this->render('agent/dialog.html.twig', [
            'messages' => $messages,
        ]);
    }

    #[Route('/history', name: 'frontend_agent_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        // Leerer Verlauf für den Anfang
        $history = [];

        return $this->render('agent/history.html.twig', [
            'history' => $history,
        ]);
    }
}
