<?php

namespace App\Service\Integration;

use App\Entity\Integration\Integration;
use App\Entity\Tenant\Tenant;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * MicrosoftIntegrationService provides integration with Microsoft services.
 * 
 * Features:
 * - Microsoft Graph API integration
 * - Email sending and reading
 * - Calendar management
 * - File storage (OneDrive)
 * - Contact management
 * - OAuth 2.0 authentication
 */
class MicrosoftIntegrationService
{
    private const string GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';
    private const string TOKEN_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';
    private const string AUTHORIZE_URL = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';

    private Client $httpClient;

    public function __construct(
        private IntegrationService $integrationService,
        private SecretManager $secretManager,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        ?Client $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Get or create the Microsoft Graph integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration The integration
     */
    public function getIntegration(Tenant $tenant): Integration
    {
        $integration = $this->integrationService->getIntegrationByTypeAndIdentifier(
            $tenant,
            'microsoft',
            'microsoft_graph'
        );

        if ($integration === null) {
            $integration = $this->integrationService->registerBuiltinIntegration(
                $tenant,
                'microsoft',
                'microsoft_graph'
            );
        }

        return $integration;
    }

    /**
     * Get the access token for Microsoft Graph API.
     * 
     * @param Tenant $tenant The tenant
     * @return string|null The access token or null
     */
    public function getAccessToken(Tenant $tenant): ?string
    {
        $integration = $this->getIntegration($tenant);
        
        // Check if we have a cached token
        $token = $this->getCachedToken($integration);
        if ($token !== null) {
            return $token;
        }

        // Get credentials
        $clientId = $integration->getCredential('client_id');
        $clientSecret = $integration->getCredential('client_secret');
        $tenantId = $integration->getCredential('tenant_id');

        if ($clientId === null || $clientSecret === null || $tenantId === null) {
            $this->logger->error('Missing Microsoft credentials', [
                'tenantId' => $tenant->getId(),
            ]);
            return null;
        }

        // Request new token
        $token = $this->requestAccessToken($clientId, $clientSecret, $tenantId);
        
        if ($token !== null) {
            // Cache the token
            $this->cacheToken($integration, $token);
        }

        return $token;
    }

    /**
     * Request an access token from Microsoft.
     * 
     * @param string $clientId The client ID
     * @param string $clientSecret The client secret
     * @param string $tenantId The Microsoft tenant ID
     * @return string|null The access token or null
     */
    private function requestAccessToken(string $clientId, string $clientSecret, string $tenantId): ?string
    {
        $url = str_replace('{tenant}', $tenantId, self::TOKEN_URL);

        try {
            $response = $this->httpClient->post($url, [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (isset($data['access_token'])) {
                return $data['access_token'];
            }

            $this->logger->error('Failed to get Microsoft access token', [
                'error' => $data['error'] ?? 'Unknown error',
                'error_description' => $data['error_description'] ?? '',
            ]);

        } catch (GuzzleException $e) {
            $this->logger->error('Microsoft token request failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get a cached access token.
     * 
     * @param Integration $integration The integration
     * @return string|null The cached token or null
     */
    private function getCachedToken(Integration $integration): ?string
    {
        // In a real implementation, you would:
        // 1. Check if token is cached and not expired
        // 2. Return the token if valid
        
        // For now, return null (no caching implemented)
        return null;
    }

    /**
     * Cache an access token.
     * 
     * @param Integration $integration The integration
     * @param string $token The token to cache
     */
    private function cacheToken(Integration $integration, string $token): void
    {
        // In a real implementation, you would:
        // 1. Store the token in cache
        // 2. Store the expiration time
        // 3. Store the refresh token if available
        
        // For now, do nothing
    }

    /**
     * Make a request to the Microsoft Graph API.
     * 
     * @param Tenant $tenant The tenant
     * @param string $method The HTTP method
     * @param string $endpoint The API endpoint (relative to base URL)
     * @param array $data The request data
     * @param array $options Request options
     * @return array|null The response data or null
     */
    public function makeRequest(
        Tenant $tenant,
        string $method,
        string $endpoint,
        array $data = [],
        array $options = []
    ): ?array {
        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            $this->logger->error('No access token for Microsoft Graph API', [
                'tenantId' => $tenant->getId(),
            ]);
            return null;
        }

        $url = self::GRAPH_API_URL . $endpoint;

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($data)) {
            if ($method === 'GET') {
                $requestOptions['query'] = $data;
            } else {
                $requestOptions['json'] = $data;
            }
        }

        $requestOptions = array_merge($requestOptions, $options);

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Microsoft Graph API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send an email using Microsoft Graph API.
     * 
     * @param Tenant $tenant The tenant
     * @param string $to The recipient email address
     * @param string $subject The email subject
     * @param string $body The email body (HTML or text)
     * @param bool $isHtml Whether the body is HTML
     * @param array $cc CC recipients
     * @param array $bcc BCC recipients
     * @param array $attachments Attachments
     * @return array|null The response data or null
     */
    public function sendEmail(
        Tenant $tenant,
        string $to,
        string $subject,
        string $body,
        bool $isHtml = true,
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): ?array {
        $email = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => $isHtml ? 'HTML' : 'Text',
                    'content' => $body,
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $to]],
                ],
            ],
        ];

        if (!empty($cc)) {
            $email['message']['ccRecipients'] = array_map(function($address) {
                return ['emailAddress' => ['address' => $address]];
            }, $cc);
        }

        if (!empty($bcc)) {
            $email['message']['bccRecipients'] = array_map(function($address) {
                return ['emailAddress' => ['address' => $address]];
            }, $bcc);
        }

        if (!empty($attachments)) {
            $email['message']['attachments'] = $attachments;
        }

        return $this->makeRequest($tenant, 'POST', '/me/sendMail', [
            'message' => $email['message'],
        ]);
    }

    /**
     * List emails from the user's mailbox.
     * 
     * @param Tenant $tenant The tenant
     * @param array $options Query options
     * @return array|null The list of emails or null
     */
    public function listEmails(Tenant $tenant, array $options = []): ?array
    {
        $query = [];

        if (isset($options['folder'])) {
            $query['$filter'] = sprintf("parentFolderId eq '%s'", $options['folder']);
        }

        if (isset($options['subject'])) {
            $query['$filter'] = sprintf("contains(subject, '%s')", $options['subject']);
        }

        if (isset($options['from'])) {
            $from = $options['from'];
            if (!isset($query['$filter'])) {
                $query['$filter'] = '';
            } else {
                $query['$filter'] .= ' and ';
            }
            $query['$filter'] .= sprintf("from/emailAddress/address eq '%s'", $from);
        }

        if (isset($options['top'])) {
            $query['$top'] = $options['top'];
        }

        if (isset($options['skip'])) {
            $query['$skip'] = $options['skip'];
        }

        if (isset($options['orderby'])) {
            $query['$orderby'] = $options['orderby'];
        }

        return $this->makeRequest($tenant, 'GET', '/me/mailFolders/inbox/messages', $query);
    }

    /**
     * Get a specific email.
     * 
     * @param Tenant $tenant The tenant
     * @param string $messageId The email ID
     * @return array|null The email data or null
     */
    public function getEmail(Tenant $tenant, string $messageId): ?array
    {
        return $this->makeRequest($tenant, 'GET', "/me/messages/{$messageId}");
    }

    /**
     * Delete an email.
     * 
     * @param Tenant $tenant The tenant
     * @param string $messageId The email ID
     * @return array|null The response data or null
     */
    public function deleteEmail(Tenant $tenant, string $messageId): ?array
    {
        return $this->makeRequest($tenant, 'DELETE', "/me/messages/{$messageId}");
    }

    /**
     * List calendar events.
     * 
     * @param Tenant $tenant The tenant
     * @param array $options Query options
     * @return array|null The list of events or null
     */
    public function listEvents(Tenant $tenant, array $options = []): ?array
    {
        $query = [];

        if (isset($options['start'])) {
            $query['$filter'] = sprintf("start/dateTime ge %s", $options['start']);
        }

        if (isset($options['end'])) {
            if (!isset($query['$filter'])) {
                $query['$filter'] = '';
            } else {
                $query['$filter'] .= ' and ';
            }
            $query['$filter'] .= sprintf("end/dateTime le %s", $options['end']);
        }

        if (isset($options['top'])) {
            $query['$top'] = $options['top'];
        }

        return $this->makeRequest($tenant, 'GET', '/me/calendar/events', $query);
    }

    /**
     * Create a calendar event.
     * 
     * @param Tenant $tenant The tenant
     * @param string $subject The event subject
     * @param string $startDateTime The start date/time (ISO 8601)
     * @param string $endDateTime The end date/time (ISO 8601)
     * @param array $attendees Event attendees
     * @param string $body The event body
     * @param string $location The event location
     * @return array|null The response data or null
     */
    public function createEvent(
        Tenant $tenant,
        string $subject,
        string $startDateTime,
        string $endDateTime,
        array $attendees = [],
        string $body = '',
        string $location = ''
    ): ?array {
        $event = [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $body,
            ],
            'start' => [
                'dateTime' => $startDateTime,
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $endDateTime,
                'timeZone' => 'UTC',
            ],
            'location' => [
                'displayName' => $location,
            ],
        ];

        if (!empty($attendees)) {
            $event['attendees'] = array_map(function($attendee) {
                return [
                    'emailAddress' => [
                        'address' => $attendee['email'],
                        'name' => $attendee['name'] ?? '',
                    ],
                    'type' => $attendee['type'] ?? 'required',
                ];
            }, $attendees);
        }

        return $this->makeRequest($tenant, 'POST', '/me/calendar/events', $event);
    }

    /**
     * List files from OneDrive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $path The path (default: root)
     * @param array $options Query options
     * @return array|null The list of files or null
     */
    public function listFiles(Tenant $tenant, string $path = 'root', array $options = []): ?array
    {
        $query = [];

        if (isset($options['top'])) {
            $query['$top'] = $options['top'];
        }

        if (isset($options['orderby'])) {
            $query['$orderby'] = $options['orderby'];
        }

        return $this->makeRequest($tenant, 'GET', "/me/drive/items/{$path}/children", $query);
    }

    /**
     * Get file content.
     * 
     * @param Tenant $tenant The tenant
     * @param string $fileId The file ID
     * @return string|null The file content or null
     */
    public function getFileContent(Tenant $tenant, string $fileId): ?string
    {
        $response = $this->makeRequest($tenant, 'GET', "/me/drive/items/{$fileId}/content");
        
        if ($response === null) {
            return null;
        }

        // The response might be the raw content or a download URL
        if (isset($response['@odata.mediaContentType'])) {
            // It's a download URL
            return $this->downloadFile($tenant, $response['@odata.mediaContentType'] ?? null);
        }

        // For now, return null - in a real implementation, you would handle the response
        return null;
    }

    /**
     * Upload a file to OneDrive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $parentPath The parent path
     * @param string $fileName The file name
     * @param string $content The file content
     * @return array|null The response data or null
     */
    public function uploadFile(
        Tenant $tenant,
        string $parentPath,
        string $fileName,
        string $content
    ): ?array {
        $url = self::GRAPH_API_URL . "/me/drive/items/{$parentPath}:/{$fileName}:/content";

        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->put($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $content,
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Microsoft file upload failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * List contacts.
     * 
     * @param Tenant $tenant The tenant
     * @param array $options Query options
     * @return array|null The list of contacts or null
     */
    public function listContacts(Tenant $tenant, array $options = []): ?array
    {
        $query = [];

        if (isset($options['top'])) {
            $query['$top'] = $options['top'];
        }

        if (isset($options['filter'])) {
            $query['$filter'] = $options['filter'];
        }

        return $this->makeRequest($tenant, 'GET', '/me/contacts', $query);
    }

    /**
     * Get a specific contact.
     * 
     * @param Tenant $tenant The tenant
     * @param string $contactId The contact ID
     * @return array|null The contact data or null
     */
    public function getContact(Tenant $tenant, string $contactId): ?array
    {
        return $this->makeRequest($tenant, 'GET', "/me/contacts/{$contactId}");
    }

    /**
     * Create a contact.
     * 
     * @param Tenant $tenant The tenant
     * @param string $givenName The first name
     * @param string $surname The last name
     * @param string $email The email address
     * @param string $phone The phone number
     * @param array $additionalData Additional contact data
     * @return array|null The response data or null
     */
    public function createContact(
        Tenant $tenant,
        string $givenName,
        string $surname,
        string $email,
        string $phone = '',
        array $additionalData = []
    ): ?array {
        $contact = [
            'givenName' => $givenName,
            'surname' => $surname,
            'emailAddresses' => [
                ['address' => $email, 'name' => $givenName . ' ' . $surname],
            ],
        ];

        if (!empty($phone)) {
            $contact['phoneNumbers'] = [
                ['number' => $phone, 'name' => 'mobile'],
            ];
        }

        $contact = array_merge($contact, $additionalData);

        return $this->makeRequest($tenant, 'POST', '/me/contacts', $contact);
    }

    /**
     * Get user profile.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The user profile or null
     */
    public function getUserProfile(Tenant $tenant): ?array
    {
        return $this->makeRequest($tenant, 'GET', '/me');
    }

    /**
     * Get user photo.
     * 
     * @param Tenant $tenant The tenant
     * @param string $size The photo size (48x48, 64x64, 96x96, 120x120, 240x240, 360x360, 432x432, 504x504, 648x648)
     * @return string|null The photo URL or null
     */
    public function getUserPhoto(Tenant $tenant, string $size = '96x96'): ?string
    {
        $response = $this->makeRequest($tenant, 'GET', "/me/photo/\${size}/\$value");
        
        if ($response === null) {
            return null;
        }

        return $response['@odata.mediaContentType'] ?? null;
    }

    /**
     * Download a file from a URL.
     * 
     * @param Tenant $tenant The tenant
     * @param string $url The URL to download
     * @return string|null The file content or null
     */
    private function downloadFile(Tenant $tenant, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            return (string)$response->getBody();
        } catch (GuzzleException $e) {
            $this->logger->error('Microsoft file download failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if the Microsoft integration is configured for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isConfigured(Tenant $tenant): bool
    {
        $integration = $this->getIntegration($tenant);
        return $integration->isConfigured();
    }

    /**
     * Check if the Microsoft integration is connected for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isConnected(Tenant $tenant): bool
    {
        $integration = $this->getIntegration($tenant);
        return $integration->isConnected();
    }

    /**
     * Check if the Microsoft integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isReady(Tenant $tenant): bool
    {
        $integration = $this->getIntegration($tenant);
        return $integration->isReady();
    }

    /**
     * Get the integration capabilities.
     * 
     * @return array
     */
    public function getCapabilities(): array
    {
        return [
            'email:send',
            'email:read',
            'email:delete',
            'calendar:read',
            'calendar:write',
            'contacts:read',
            'contacts:write',
            'files:read',
            'files:write',
            'user:profile',
        ];
    }
}
