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
 * GitHubIntegrationService provides integration with GitHub.
 * 
 * Features:
 * - GitHub REST API integration
 * - Repository management
 * - Issue and pull request management
 * - Personal access token authentication
 * - Webhook management
 */
class GitHubIntegrationService
{
    private const string API_URL = 'https://api.github.com';
    private const string USER_AGENT = 'EVIE-AI';

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
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => self::USER_AGENT,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
    }

    /**
     * Get or create the GitHub integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration The integration
     */
    public function getIntegration(Tenant $tenant): Integration
    {
        $integration = $this->integrationService->getIntegrationByTypeAndIdentifier(
            $tenant,
            'github',
            'github_api'
        );

        if ($integration === null) {
            $integration = $this->integrationService->registerBuiltinIntegration(
                $tenant,
                'github',
                'github_api'
            );
        }

        return $integration;
    }

    /**
     * Get the access token for GitHub API.
     * 
     * @param Tenant $tenant The tenant
     * @return string|null The access token or null
     */
    public function getAccessToken(Tenant $tenant): ?string
    {
        $integration = $this->getIntegration($tenant);
        return $integration->getCredential('token');
    }

    /**
     * Make a request to the GitHub API.
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
            $this->logger->error('No access token for GitHub API', [
                'tenantId' => $tenant->getId(),
            ]);
            return null;
        }

        $url = self::API_URL . $endpoint;

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => self::USER_AGENT,
                'X-GitHub-Api-Version' => '2022-11-28',
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
            $this->logger->error('GitHub API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ==================== Repository Methods ====================

    /**
     * List repositories for the authenticated user.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The type of repositories (all, owner, member)
     * @param array $options Query options
     * @return array|null The list of repositories or null
     */
    public function listRepositories(
        Tenant $tenant,
        string $type = 'all',
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['sort'])) {
            $query['sort'] = $options['sort'];
        }

        if (isset($options['direction'])) {
            $query['direction'] = $options['direction'];
        }

        if (isset($options['per_page'])) {
            $query['per_page'] = $options['per_page'];
        }

        if (isset($options['page'])) {
            $query['page'] = $options['page'];
        }

        return $this->makeRequest($tenant, 'GET', "/user/repos", $query);
    }

    /**
     * Get a specific repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @return array|null The repository data or null
     */
    public function getRepository(Tenant $tenant, string $owner, string $repo): ?array
    {
        return $this->makeRequest($tenant, 'GET', "/repos/{$owner}/{$repo}");
    }

    /**
     * Create a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $name The repository name
     * @param string $description The repository description
     * @param bool $isPrivate Whether the repository is private
     * @param bool $initializeWithReadme Initialize with a README
     * @param array $additionalOptions Additional repository options
     * @return array|null The response data or null
     */
    public function createRepository(
        Tenant $tenant,
        string $name,
        string $description = '',
        bool $isPrivate = false,
        bool $initializeWithReadme = true,
        array $additionalOptions = []
    ): ?array {
        $repository = [
            'name' => $name,
            'description' => $description,
            'private' => $isPrivate,
            'auto_init' => $initializeWithReadme,
        ];

        $repository = array_merge($repository, $additionalOptions);

        return $this->makeRequest($tenant, 'POST', '/user/repos', $repository);
    }

    /**
     * Update a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param array $updates Repository updates
     * @return array|null The response data or null
     */
    public function updateRepository(
        Tenant $tenant,
        string $owner,
        string $repo,
        array $updates
    ): ?array {
        return $this->makeRequest($tenant, 'PATCH', "/repos/{$owner}/{$repo}", $updates);
    }

    /**
     * Delete a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @return array|null The response data or null
     */
    public function deleteRepository(Tenant $tenant, string $owner, string $repo): ?array
    {
        return $this->makeRequest($tenant, 'DELETE', "/repos/{$owner}/{$repo}");
    }

    /**
     * Get repository contents.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param string $path The path in the repository
     * @return array|null The contents or null
     */
    public function getContents(
        Tenant $tenant,
        string $owner,
        string $repo,
        string $path = ''
    ): ?array {
        $endpoint = "/repos/{$owner}/{$repo}/contents";
        if (!empty($path)) {
            $endpoint .= '/' . $path;
        }

        return $this->makeRequest($tenant, 'GET', $endpoint);
    }

    /**
     * Create or update a file in a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param string $path The file path
     * @param string $content The file content
     * @param string $message The commit message
     * @param string|null $sha The SHA of the file to update (null for new file)
     * @param string|null $branch The branch name
     * @return array|null The response data or null
     */
    public function createOrUpdateFile(
        Tenant $tenant,
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        ?string $sha = null,
        ?string $branch = null
    ): ?array {
        $file = [
            'message' => $message,
            'content' => base64_encode($content),
        ];

        if ($sha !== null) {
            $file['sha'] = $sha;
        }

        if ($branch !== null) {
            $file['branch'] = $branch;
        }

        $endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";

        return $this->makeRequest($tenant, $sha === null ? 'PUT' : 'PUT', $endpoint, $file);
    }

    /**
     * Delete a file from a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param string $path The file path
     * @param string $message The commit message
     * @param string $sha The SHA of the file to delete
     * @param string|null $branch The branch name
     * @return array|null The response data or null
     */
    public function deleteFile(
        Tenant $tenant,
        string $owner,
        string $repo,
        string $path,
        string $message,
        string $sha,
        ?string $branch = null
    ): ?array {
        $file = [
            'message' => $message,
            'sha' => $sha,
        ];

        if ($branch !== null) {
            $file['branch'] = $branch;
        }

        $endpoint = "/repos/{$owner}/{$repo}/contents/{$path}";

        return $this->makeRequest($tenant, 'DELETE', $endpoint, $file);
    }

    // ==================== Issue Methods ====================

    /**
     * List issues for a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param array $options Query options
     * @return array|null The list of issues or null
     */
    public function listIssues(
        Tenant $tenant,
        string $owner,
        string $repo,
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['milestone'])) {
            $query['milestone'] = $options['milestone'];
        }

        if (isset($options['state'])) {
            $query['state'] = $options['state'];
        }

        if (isset($options['assignee'])) {
            $query['assignee'] = $options['assignee'];
        }

        if (isset($options['creator'])) {
            $query['creator'] = $options['creator'];
        }

        if (isset($options['mentioned'])) {
            $query['mentioned'] = $options['mentioned'];
        }

        if (isset($options['labels'])) {
            $query['labels'] = $options['labels'];
        }

        if (isset($options['sort'])) {
            $query['sort'] = $options['sort'];
        }

        if (isset($options['direction'])) {
            $query['direction'] = $options['direction'];
        }

        if (isset($options['since'])) {
            $query['since'] = $options['since'];
        }

        return $this->makeRequest($tenant, 'GET', "/repos/{$owner}/{$repo}/issues", $query);
    }

    /**
     * Get a specific issue.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param int $issueNumber The issue number
     * @return array|null The issue data or null
     */
    public function getIssue(
        Tenant $tenant,
        string $owner,
        string $repo,
        int $issueNumber
    ): ?array {
        return $this->makeRequest($tenant, 'GET', "/repos/{$owner}/{$repo}/issues/{$issueNumber}");
    }

    /**
     * Create an issue.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param string $title The issue title
     * @param string $body The issue body
     * @param array $labels Issue labels
     * @param array $assignees Issue assignees
     * @param int|null $milestone Milestone number
     * @return array|null The response data or null
     */
    public function createIssue(
        Tenant $tenant,
        string $owner,
        string $repo,
        string $title,
        string $body = '',
        array $labels = [],
        array $assignees = [],
        ?int $milestone = null
    ): ?array {
        $issue = [
            'title' => $title,
            'body' => $body,
        ];

        if (!empty($labels)) {
            $issue['labels'] = $labels;
        }

        if (!empty($assignees)) {
            $issue['assignees'] = $assignees;
        }

        if ($milestone !== null) {
            $issue['milestone'] = $milestone;
        }

        return $this->makeRequest($tenant, 'POST', "/repos/{$owner}/{$repo}/issues", $issue);
    }

    /**
     * Update an issue.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param int $issueNumber The issue number
     * @param array $updates Issue updates
     * @return array|null The response data or null
     */
    public function updateIssue(
        Tenant $tenant,
        string $owner,
        string $repo,
        int $issueNumber,
        array $updates
    ): ?array {
        return $this->makeRequest($tenant, 'PATCH', "/repos/{$owner}/{$repo}/issues/{$issueNumber}", $updates);
    }

    /**
     * Add a comment to an issue.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param int $issueNumber The issue number
     * @param string $body The comment body
     * @return array|null The response data or null
     */
    public function addIssueComment(
        Tenant $tenant,
        string $owner,
        string $repo,
        int $issueNumber,
        string $body
    ): ?array {
        return $this->makeRequest($tenant, 'POST', "/repos/{$owner}/{$repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ]);
    }

    // ==================== Pull Request Methods ====================

    /**
     * List pull requests for a repository.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param array $options Query options
     * @return array|null The list of pull requests or null
     */
    public function listPullRequests(
        Tenant $tenant,
        string $owner,
        string $repo,
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['state'])) {
            $query['state'] = $options['state'];
        }

        if (isset($options['head'])) {
            $query['head'] = $options['head'];
        }

        if (isset($options['base'])) {
            $query['base'] = $options['base'];
        }

        if (isset($options['sort'])) {
            $query['sort'] = $options['sort'];
        }

        if (isset($options['direction'])) {
            $query['direction'] = $options['direction'];
        }

        return $this->makeRequest($tenant, 'GET', "/repos/{$owner}/{$repo}/pulls", $query);
    }

    /**
     * Get a specific pull request.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param int $prNumber The pull request number
     * @return array|null The pull request data or null
     */
    public function getPullRequest(
        Tenant $tenant,
        string $owner,
        string $repo,
        int $prNumber
    ): ?array {
        return $this->makeRequest($tenant, 'GET', "/repos/{$owner}/{$repo}/pulls/{$prNumber}");
    }

    /**
     * Create a pull request.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param string $title The pull request title
     * @param string $head The head branch
     * @param string $base The base branch
     * @param string $body The pull request body
     * @param bool $draft Whether the pull request is a draft
     * @return array|null The response data or null
     */
    public function createPullRequest(
        Tenant $tenant,
        string $owner,
        string $repo,
        string $title,
        string $head,
        string $base,
        string $body = '',
        bool $draft = false
    ): ?array {
        $pr = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
            'draft' => $draft,
        ];

        return $this->makeRequest($tenant, 'POST', "/repos/{$owner}/{$repo}/pulls", $pr);
    }

    /**
     * Merge a pull request.
     * 
     * @param Tenant $tenant The tenant
     * @param string $owner The repository owner
     * @param string $repo The repository name
     * @param int $prNumber The pull request number
     * @param string $mergeMethod The merge method (merge, squash, rebase)
     * @param string|null $commitTitle The commit title
     * @param string|null $commitMessage The commit message
     * @return array|null The response data or null
     */
    public function mergePullRequest(
        Tenant $tenant,
        string $owner,
        string $repo,
        int $prNumber,
        string $mergeMethod = 'merge',
        ?string $commitTitle = null,
        ?string $commitMessage = null
    ): ?array {
        $merge = [
            'merge_method' => $mergeMethod,
        ];

        if ($commitTitle !== null) {
            $merge['commit_title'] = $commitTitle;
        }

        if ($commitMessage !== null) {
            $merge['commit_message'] = $commitMessage;
        }

        return $this->makeRequest($tenant, 'PUT', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/merge", $merge);
    }

    // ==================== Utility Methods ====================

    /**
     * Get the authenticated user's profile.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The user profile or null
     */
    public function getUserProfile(Tenant $tenant): ?array
    {
        return $this->makeRequest($tenant, 'GET', '/user');
    }

    /**
     * List organizations for the authenticated user.
     * 
     * @param Tenant $tenant The tenant
     * @return array|null The list of organizations or null
     */
    public function listOrganizations(Tenant $tenant): ?array
    {
        return $this->makeRequest($tenant, 'GET', '/user/orgs');
    }

    /**
     * List repositories for an organization.
     * 
     * @param Tenant $tenant The tenant
     * @param string $org The organization name
     * @param array $options Query options
     * @return array|null The list of repositories or null
     */
    public function listOrganizationRepositories(
        Tenant $tenant,
        string $org,
        array $options = []
    ): ?array {
        $query = [];

        if (isset($options['type'])) {
            $query['type'] = $options['type'];
        }

        if (isset($options['sort'])) {
            $query['sort'] = $options['sort'];
        }

        if (isset($options['direction'])) {
            $query['direction'] = $options['direction'];
        }

        if (isset($options['per_page'])) {
            $query['per_page'] = $options['per_page'];
        }

        return $this->makeRequest($tenant, 'GET', "/orgs/{$org}/repos", $query);
    }

    /**
     * Check if the GitHub integration is configured for a tenant.
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
     * Check if the GitHub integration is connected for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isConnected(Tenant $tenant): bool
    {
        // GitHub is always "connected" if we have a token
        return $this->getAccessToken($tenant) !== null;
    }

    /**
     * Check if the GitHub integration is ready for a tenant.
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
            'github:read',
            'github:write',
            'github:repositories',
            'github:issues',
            'github:pull_requests',
            'github:comments',
            'github:files',
        ];
    }
}
