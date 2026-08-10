<?php
// src/AI/Skills/Tool/LinkedInTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tool für die Interaktion mit der LinkedIn API.
 * Ermöglicht das Abrufen von Profilen und das Senden von Nachrichten.
 */
class LinkedInTool
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $accessToken
    ) {
    }

    /**
     * Hauptmethode - LinkedIn-Aktionen ausführen
     */
    #[AsTool(
        name: 'linkedin_action',
        description: 'Führt LinkedIn-Aktionen aus: Profil abrufen, Nachrichten senden, Profile suchen'
    )]
    public function __invoke(
        string $action,
        array $parameters = []
    ): array {
        switch ($action) {
            case 'get_profile':
                return $this->getProfile($parameters['profileIdOrUrl'] ?? '');
            case 'search_profiles':
                return $this->searchProfiles(
                    $parameters['query'] ?? '',
                    $parameters['limit'] ?? 10
                );
            case 'send_message':
                return $this->sendMessage(
                    $parameters['recipientId'] ?? '',
                    $parameters['subject'] ?? '',
                    $parameters['message'] ?? ''
                );
            default:
                return [
                    'status' => 'error',
                    'message' => 'Unbekannte LinkedIn-Aktion: ' . $action,
                    'available_actions' => ['get_profile', 'search_profiles', 'send_message'],
                ];
        }
    }

    /**
     * Ruft ein LinkedIn-Profil ab
     */
    public function getProfile(string $profileIdOrUrl): array
    {
        try {
            $profileId = $this->extractProfileId($profileIdOrUrl);
            
            $response = $this->httpClient->request('GET', 
                "https://api.linkedin.com/v2/people/~:(id,firstName,lastName,profilePicture)",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                ]
            );

            $data = json_decode($response->getContent(), true);

            return [
                'status' => 'success',
                'profile' => $data,
                'profile_id' => $profileId,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Abrufen des LinkedIn-Profils: ' . $e->getMessage(),
                'profile_id' => $profileIdOrUrl,
            ];
        }
    }

    /**
     * Sucht nach LinkedIn-Profilen
     */
    public function searchProfiles(string $query, int $limit = 10): array
    {
        try {
            $response = $this->httpClient->request('GET',
                "https://api.linkedin.com/v2/people.search",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'query' => [
                        'q' => 'people',
                        'keywords' => $query,
                        'count' => $limit,
                    ],
                ]
            );

            $data = json_decode($response->getContent(), true);

            return [
                'status' => 'success',
                'results' => $data['elements'] ?? [],
                'count' => count($data['elements'] ?? []),
                'query' => $query,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler bei der LinkedIn-Suche: ' . $e->getMessage(),
                'query' => $query,
            ];
        }
    }

    /**
     * Sendet eine LinkedIn-Nachricht
     */
    public function sendMessage(string $recipientId, string $subject, string $message): array
    {
        try {
            $response = $this->httpClient->request('POST',
                "https://api.linkedin.com/v2/messaging/conversations",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json',
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'json' => [
                        'participants' => [
                            ['person' => 'urn:li:person:' . $recipientId]
                        ],
                        'subject' => $subject,
                        'body' => [
                            'text' => $message
                        ]
                    ],
                ]
            );

            $data = json_decode($response->getContent(), true);

            return [
                'status' => 'success',
                'message' => 'LinkedIn-Nachricht erfolgreich gesendet',
                'conversation_id' => $data['id'] ?? null,
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'sent_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Senden der LinkedIn-Nachricht: ' . $e->getMessage(),
                'recipient_id' => $recipientId,
                'subject' => $subject,
            ];
        }
    }

    /**
     * Extrahiere Profile-ID aus URL oder Rückgabe der ID
     */
    private function extractProfileId(string $profileIdOrUrl): string
    {
        if (preg_match('/^[a-zA-Z0-9-]+$/', $profileIdOrUrl)) {
            return $profileIdOrUrl;
        }
        if (preg_match('/linkedin\.com\/(in|profile)\/([a-zA-Z0-9-]+)/', $profileIdOrUrl, $matches)) {
            return $matches[2];
        }
        return $profileIdOrUrl;
    }
}
