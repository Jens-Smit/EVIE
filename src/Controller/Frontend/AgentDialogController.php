<?php
// src/Controller/Frontend/AgentDialogController.php
namespace App\Controller\Frontend;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AgentDialogController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/dialog', name: 'frontend_agent_dialog', methods: ['GET', 'POST'])]
    public function dialog(Request $request): Response
    {
        $messages = [
            ['role' => 'system', 'content' => 'Willkommen beim EVIE-Agenten! Wie kann ich dir helfen?'],
        ];

        if ($request->isMethod('POST')) {
            $prompt = $request->request->get('prompt');
            $userIdentifier = 'default_user'; // Ersetze durch echte Benutzer-ID (z. B. aus Session)

            if ($prompt) {
                // Füge die Benutzernachricht hinzu
                $messages[] = ['role' => 'user', 'content' => $prompt];

                // Rufe die API auf, um die Agenten-Antwort zu erhalten
                try {
                    $response = $this->httpClient->request('POST', '/api/agent/dialog', [
                        'json' => [
                            'message' => $prompt,
                            'user_identifier' => $userIdentifier,
                        ],
                    ]);

                    $data = $response->toArray();
                    $messages[] = ['role' => 'agent', 'content' => $data['response'] ?? 'Keine Antwort erhalten.'];
                } catch (\Exception $e) {
                    $messages[] = ['role' => 'system', 'content' => 'Fehler bei der Anfrage: ' . $e->getMessage()];
                }
            }
        }

        return $this->render('agent/dialog.html.twig', [
            'messages' => $messages,
        ]);
    }

    #[Route('/history', name: 'frontend_agent_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $userIdentifier = 'default_user'; // Ersetze durch echte Benutzer-ID

        try {
            $response = $this->httpClient->request('GET', "/api/agent/history/{$userIdentifier}");
            $historyData = $response->toArray();

            // Konvertiere die API-Antwort in das Format für das Template
            $history = [];
            foreach ($historyData as $entry) {
                $history[] = [
                    'timestamp' => new \DateTime($entry['executed_at']),
                    'userIdentifier' => $userIdentifier,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Anfrage: ' . ($entry['status'] ?? 'Unbekannt')],
                        ['role' => 'agent', 'content' => 'Agent: ' . ($entry['agent'] ?? 'Unbekannt')],
                    ],
                ];
            }

            return $this->render('agent/history.html.twig', [
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            return $this->render('agent/history.html.twig', [
                'history' => [],
                'error' => 'Fehler beim Laden des Verlaufs: ' . $e->getMessage(),
            ]);
        }
    }
}
