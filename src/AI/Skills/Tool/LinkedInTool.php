<?php
// src/AI/Skills/Tool/LinkedInTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tool für die Interaktion mit der LinkedIn API.
 * Ermöglicht das Abrufen von Profilen, Posts und das Senden von Nachrichten.
 * 
 * HINWEIS: Benötigt LinkedIn API-Zugang (OAuth 2.0)
 */
class LinkedInTool
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $accessToken
    ) {
    }

    /**
     * Ruft ein LinkedIn-Profil ab
     */
    #[AsTool(
        name: 'get_linkedin_profile',
        description: 'Ruft ein LinkedIn-Profil anhand der Profile-ID oder URL ab.'
    )]
    public function getProfile(string $profileIdOrUrl): array
    {
        try {
            // Extrahiere Profile-ID aus URL
            $profileId = $this->extractProfileId($profileIdOrUrl);
            
            // LinkedIn API aufrufen
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
    #[AsTool(
        name: 'search_linkedin_profiles',
        description: 'Durchsucht LinkedIn nach Profilen basierend auf Suchkriterien.'
    )]
    public function searchProfiles(
        string $query,
        int $limit = 10
    ): array {
        try {
            $response = $this->httpClient->request('GET',
                "https://api.linkedin.com/v2/people/search",
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
    #[AsTool(
        name: 'send_linkedin_message',
        description: 'Sendet eine Nachricht an ein LinkedIn-Profil.'
    )]
    public function sendMessage(
        string $recipientId,
        string $subject,
        string $message
    ): array {
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
        // Prüfe, ob es bereits eine ID ist
        if (preg_match('/^[a-zA-Z0-9-]+$/', $profileIdOrUrl)) {
            return $profileIdOrUrl;
        }

        // Extrahiere aus URL
        if (preg_match('/linkedin\.com\/(in|profile)\/([a-zA-Z0-9-]+)/', $profileIdOrUrl, $matches)) {
            return $matches[2];
        }

        return $profileIdOrUrl;
    }
}
