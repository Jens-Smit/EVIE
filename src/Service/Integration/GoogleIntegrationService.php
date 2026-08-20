<?php

namespace App\Service\Integration;

use App\Entity\Integration\Integration;
use App\Entity\Tenant\Tenant;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * GoogleIntegrationService provides integration with Google services.
 * 
 * Features:
 * - Google Workspace APIs (Gmail, Calendar, Drive)
 * - OAuth 2.0 authentication
 * - Service account support
 * - API key support
 */
class GoogleIntegrationService
{
    private const string GMAIL_API_URL = 'https://gmail.googleapis.com/v1';
    private const string CALENDAR_API_URL = 'https://www.googleapis.com/calendar/v3';
    private const string DRIVE_API_URL = 'https://www.googleapis.com/drive/v3';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

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
     * Get or create the Google Workspace integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration The integration
     */
    public function getIntegration(Tenant $tenant): Integration
    {
        $integration = $this->integrationService->getIntegrationByTypeAndIdentifier(
            $tenant,
            'google',
            'google_workspace'
        );

        if ($integration === null) {
            $integration = $this->integrationService->registerBuiltinIntegration(
                $tenant,
                'google',
                'google_workspace'
            );
        }

        return $integration;
    }

    /**
     * Get the access token for Google APIs.
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

        if ($clientId === null || $clientSecret === null) {
            $this->logger->error('Missing Google credentials', [
                'tenantId' => $tenant->getId(),
            ]);
            return null;
        }

        // Request new token using service account or OAuth
        // For now, we'll use a simple approach
        $token = $this->requestAccessToken($clientId, $clientSecret);
        
        if ($token !== null) {
            $this->cacheToken($integration, $token);
        }

        return $token;
    }

    /**
     * Request an access token from Google.
     * 
     * @param string $clientId The client ID
     * @param string $clientSecret The client secret
     * @return string|null The access token or null
     */
    private function requestAccessToken(string $clientId, string $clientSecret): ?string
    {
        // In a real implementation, you would use the appropriate OAuth flow
        // For service accounts, you would use JWT authentication
        // For OAuth, you would use the authorization code flow
        
        // For now, return null - this is a placeholder
        // In production, you would implement proper OAuth 2.0 authentication
        
        $this->logger->warning('Google OAuth not fully implemented - using placeholder');
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
    }

    /**
     * Make a request to a Google API.
     * 
     * @param Tenant $tenant The tenant
     * @param string $api The API to use (gmail, calendar, drive)
     * @param string $method The HTTP method
     * @param string $endpoint The API endpoint (relative to base URL)
     * @param array $data The request data
     * @param array $options Request options
     * @return array|null The response data or null
     */
    public function makeRequest(
        Tenant $tenant,
        string $api,
        string $method,
        string $endpoint,
        array $data = [],
        array $options = []
    ): ?array {
        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            $this->logger->error('No access token for Google API', [
                'tenantId' => $tenant->getId(),
                'api' => $api,
            ]);
            return null;
        }

        $baseUrl = $this->getBaseUrl($api);
        $url = $baseUrl . $endpoint;

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
            $this->logger->error('Google API request failed', [
                'api' => $api,
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get the base URL for a Google API.
     * 
     * @param string $api The API name
     * @return string
     */
    private function getBaseUrl(string $api): string
    {
        return match ($api) {
            'gmail' => self::GMAIL_API_URL,
            'calendar' => self::CALENDAR_API_URL,
            'drive' => self::DRIVE_API_URL,
            default => self::GMAIL_API_URL,
        };
    }

    // ==================== Gmail API Methods ====================

    /**
     * Send an email using Gmail API.
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
        // Create the email message
        $message = [
            'raw' => $this->createRawEmailMessage(
                $to,
                $subject,
                $body,
                $isHtml,
                $cc,
                $bcc,
                $attachments
            ),
        ];

        return $this->makeRequest($tenant, 'gmail', 'POST', '/users/me/messages/send', $message);
    }

    /**
     * Create a raw email message for Gmail API.
     * 
     * @param string $to The recipient
     * @param string $subject The subject
     * @param string $body The body
     * @param bool $isHtml Whether the body is HTML
     * @param array $cc CC recipients
     * @param array $bcc BCC recipients
     * @param array $attachments Attachments
     * @return string Base64-encoded raw message
     */
    private function createRawEmailMessage(
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): string {
        // Create email headers
        $headers = [
            'From: me',
            sprintf('To: %s', $to),
            sprintf('Subject: %s', $subject),
            'MIME-Version: 1.0',
        ];

        if (!empty($cc)) {
            $headers[] = sprintf('Cc: %s', implode(', ', $cc));
        }

        if (!empty($bcc)) {
            $headers[] = sprintf('Bcc: %s', implode(', ', $bcc));
        }

        // Create email body
        $contentType = $isHtml ? 'text/html' : 'text/plain';
        $boundary = 'boundary_' . uniqid();
        
        $message = implode("\r\n", $headers) . "\r\n";
        $message .= sprintf("Content-Type: %s; charset=UTF-8\r\n\r\n", $contentType);
        $message .= $body . "\r\n";

        // If there are attachments, create a multipart message
        if (!empty($attachments)) {
            $message = implode("\r\n", $headers) . "\r\n";
            $message .= sprintf("Content-Type: multipart/mixed; boundary=\"%s\"\r\n\r\n", $boundary);
            
            // Add text part
            $message .= sprintf("--%s\r\n", $boundary);
            $message .= sprintf("Content-Type: %s; charset=UTF-8\r\n\r\n", $contentType);
            $message .= $body . "\r\n\r\n";

            // Add attachments
            foreach ($attachments as $attachment) {
                $message .= sprintf("--%s\r\n", $boundary);
                $message .= sprintf("Content-Type: %s\r\n", $attachment['mimeType'] ?? 'application/octet-stream');
                $message .= sprintf("Content-Disposition: attachment; filename=\"%s\"\r\n", $attachment['filename']);
                $message .= sprintf("Content-Transfer-Encoding: base64\r\n\r\n");
                $message .= chunk_split(base64_encode($attachment['content'])) . "\r\n\r\n";
            }

            $message .= sprintf("--%s--\r\n", $boundary);
        }

        // Base64 encode the message and replace +/ with -_ and = with empty string
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($message));
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

        if (isset($options['q'])) {
            $query['q'] = $options['q'];
        }

        if (isset($options['maxResults'])) {
            $query['maxResults'] = $options['maxResults'];
        }

        if (isset($options['pageToken'])) {
            $query['pageToken'] = $options['pageToken'];
        }

        if (isset($options['labelIds'])) {
            $query['labelIds'] = $options['labelIds'];
        }

        return $this->makeRequest($tenant, 'gmail', 'GET', '/users/me/messages', $query);
    }

    /**
     * Get a specific email.
     * 
     * @param Tenant $tenant The tenant
     * @param string $messageId The email ID
     * @param bool $format Include full message format
     * @return array|null The email data or null
     */
    public function getEmail(Tenant $tenant, string $messageId, bool $format = true): ?array
    {
        $query = [];
        if ($format) {
            $query['format'] = 'full';
        }

        return $this->makeRequest($tenant, 'gmail', 'GET', "/users/me/messages/{$messageId}", $query);
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
        return $this->makeRequest($tenant, 'gmail', 'DELETE', "/users/me/messages/{$messageId}");
    }

    // ==================== Calendar API Methods ====================

    /**
     * List calendar events.
     * 
     * @param Tenant $tenant The tenant
     * @param string $calendarId The calendar ID (default: primary)
     * @param array $options Query options
     * @return array|null The list of events or null
     */
    public function listEvents(
        Tenant $tenant,
        string $calendarId = 'primary',
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['timeMin'])) {
            $query['timeMin'] = $options['timeMin'];
        }

        if (isset($options['timeMax'])) {
            $query['timeMax'] = $options['timeMax'];
        }

        if (isset($options['maxResults'])) {
            $query['maxResults'] = $options['maxResults'];
        }

        if (isset($options['singleEvents'])) {
            $query['singleEvents'] = $options['singleEvents'];
        }

        if (isset($options['orderBy'])) {
            $query['orderBy'] = $options['orderBy'];
        }

        return $this->makeRequest($tenant, 'calendar', 'GET', "/calendars/{$calendarId}/events", $query);
    }

    /**
     * Create a calendar event.
     * 
     * @param Tenant $tenant The tenant
     * @param string $calendarId The calendar ID (default: primary)
     * @param string $summary The event summary
     * @param string $startDateTime The start date/time (ISO 8601)
     * @param string $endDateTime The end date/time (ISO 8601)
     * @param array $attendees Event attendees
     * @param string $description The event description
     * @param string $location The event location
     * @return array|null The response data or null
     */
    public function createEvent(
        Tenant $tenant,
        string $calendarId = 'primary',
        string $summary = '',
        string $startDateTime = '',
        string $endDateTime = '',
        array $attendees = [],
        string $description = '',
        string $location = ''
    ): ?array {
        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $startDateTime,
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $endDateTime,
                'timeZone' => 'UTC',
            ],
        ];

        if (!empty($location)) {
            $event['location'] = $location;
        }

        if (!empty($attendees)) {
            $event['attendees'] = array_map(function($attendee) {
                return [
                    'email' => $attendee['email'],
                    'displayName' => $attendee['name'] ?? '',
                    'responseStatus' => $attendee['responseStatus'] ?? 'needsAction',
                ];
            }, $attendees);
        }

        return $this->makeRequest($tenant, 'calendar', 'POST', "/calendars/{$calendarId}/events", $event);
    }

    /**
     * Get a specific calendar event.
     * 
     * @param Tenant $tenant The tenant
     * @param string $calendarId The calendar ID
     * @param string $eventId The event ID
     * @return array|null The event data or null
     */
    public function getEvent(Tenant $tenant, string $calendarId, string $eventId): ?array
    {
        return $this->makeRequest($tenant, 'calendar', 'GET', "/calendars/{$calendarId}/events/{$eventId}");
    }

    /**
     * Update a calendar event.
     * 
     * @param Tenant $tenant The tenant
     * @param string $calendarId The calendar ID
     * @param string $eventId The event ID
     * @param array $updates Event updates
     * @return array|null The response data or null
     */
    public function updateEvent(
        Tenant $tenant,
        string $calendarId,
        string $eventId,
        array $updates
    ): ?array {
        return $this->makeRequest($tenant, 'calendar', 'PATCH', "/calendars/{$calendarId}/events/{$eventId}", $updates);
    }

    /**
     * Delete a calendar event.
     * 
     * @param Tenant $tenant The tenant
     * @param string $calendarId The calendar ID
     * @param string $eventId The event ID
     * @return array|null The response data or null
     */
    public function deleteEvent(Tenant $tenant, string $calendarId, string $eventId): ?array
    {
        return $this->makeRequest($tenant, 'calendar', 'DELETE', "/calendars/{$calendarId}/events/{$eventId}");
    }

    // ==================== Drive API Methods ====================

    /**
     * List files from Google Drive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $folderId The folder ID (default: root)
     * @param array $options Query options
     * @return array|null The list of files or null
     */
    public function listFiles(
        Tenant $tenant,
        string $folderId = 'root',
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['q'])) {
            $query['q'] = $options['q'];
        }

        if (isset($options['pageSize'])) {
            $query['pageSize'] = $options['pageSize'];
        }

        if (isset($options['fields'])) {
            $query['fields'] = $options['fields'];
        }

        if (isset($options['orderBy'])) {
            $query['orderBy'] = $options['orderBy'];
        }

        return $this->makeRequest($tenant, 'drive', 'GET', "/files", $query);
    }

    /**
     * Get a specific file from Google Drive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $fileId The file ID
     * @param array $options Query options
     * @return array|null The file data or null
     */
    public function getFile(Tenant $tenant, string $fileId, array $options = []): ?array
    {
        $query = [];

        if (isset($options['fields'])) {
            $query['fields'] = $options['fields'];
        }

        return $this->makeRequest($tenant, 'drive', 'GET', "/files/{$fileId}", $query);
    }

    /**
     * Download a file from Google Drive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $fileId The file ID
     * @return string|null The file content or null
     */
    public function downloadFile(Tenant $tenant, string $fileId): ?string
    {
        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            return null;
        }

        $url = self::DRIVE_API_URL . "/files/{$fileId}?alt=media";

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            return (string)$response->getBody();
        } catch (GuzzleException $e) {
            $this->logger->error('Google Drive file download failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload a file to Google Drive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $parentId The parent folder ID
     * @param string $fileName The file name
     * @param string $content The file content
     * @param string $mimeType The MIME type
     * @return array|null The response data or null
     */
    public function uploadFile(
        Tenant $tenant,
        string $parentId,
        string $fileName,
        string $content,
        string $mimeType = 'text/plain'
    ): ?array {
        $token = $this->getAccessToken($tenant);
        
        if ($token === null) {
            return null;
        }

        $url = self::DRIVE_API_URL . '/files';
        
        $metadata = [
            'name' => $fileName,
            'parents' => [$parentId],
        ];

        $boundary = 'boundary_' . uniqid();
        
        $body = '';
        $body .= sprintf("--%s\r\n", $boundary);
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n\r\n";
        $body .= sprintf("--%s\r\n", $boundary);
        $body .= sprintf("Content-Type: %s\r\n\r\n", $mimeType);
        $body .= $content . "\r\n\r\n";
        $body .= sprintf("--%s--\r\n", $boundary);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => sprintf('multipart/related; boundary="%s"', $boundary),
                ],
                'body' => $body,
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Google Drive file upload failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Delete a file from Google Drive.
     * 
     * @param Tenant $tenant The tenant
     * @param string $fileId The file ID
     * @return array|null The response data or null
     */
    public function deleteFile(Tenant $tenant, string $fileId): ?array
    {
        return $this->makeRequest($tenant, 'drive', 'DELETE', "/files/{$fileId}");
    }

    // ==================== Utility Methods ====================

    /**
     * Check if the Google integration is configured for a tenant.
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
     * Check if the Google integration is connected for a tenant.
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
     * Check if the Google integration is ready for a tenant.
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
            'files:read',
            'files:write',
            'files:delete',
        ];
    }
}
