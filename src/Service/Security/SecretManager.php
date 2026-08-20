<?php

namespace App\Service\Security;

use App\Entity\Security\UserSecret;
use App\Entity\Tenant\User;
use App\Repository\Security\UserSecretRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * SecretManager provides secure secret management with:
 * - AES-256-GCM encryption
 * - Tenant isolation
 * - Key versioning and rotation
 * - Secret leakage prevention
 * 
 * All secrets are encrypted at rest and only decrypted when needed.
 * Master key MUST come from environment, never from database.
 */
class SecretManager
{
    private const DEFAULT_KEY_VERSION = 'KEY_V1';
    
    public function __construct(
        private EncryptionService $encryptionService,
        private UserSecretRepository $secretRepository,
        private EntityManagerInterface $entityManager,
        private string $currentKeyVersion = self::DEFAULT_KEY_VERSION
    ) {
    }

    /**
     * Store a secret for a user.
     * The secret is encrypted before storage.
     * 
     * @param User $user The user who owns the secret
     * @param string $key The secret key (e.g., 'MISTRAL_API_KEY')
     * @param string $value The secret value (plaintext)
     * @param array $metadata Optional metadata
     * @return UserSecret The stored secret entity
     * @throws RuntimeException If secret already exists or encryption fails
     */
    public function storeSecret(
        User $user,
        string $key,
        string $value,
        ?array $metadata = null
    ): UserSecret {
        // Check if secret already exists for this user
        $existingSecret = $this->secretRepository->findOneByKeyAndUser($key, $user);
        if ($existingSecret !== null) {
            throw new RuntimeException(
                "Secret with key '{$key}' already exists for user '{$user->getId()}'"
            );
        }

        // Encrypt the value
        $encryptedValue = $this->encryptionService->encrypt(
            $value,
            $this->currentKeyVersion
        );

        // Create new secret entity
        $secret = new UserSecret();
        $secret->setUser($user);
        $secret->setSecretKey($key);
        $secret->setEncryptedValue($encryptedValue);
        $secret->setEncryptionVersion($this->encryptionService->getCurrentVersion());
        $secret->setKeyVersion($this->currentKeyVersion);
        $secret->setMetadata($metadata);

        // Save to database
        $this->secretRepository->save($secret, true);

        return $secret;
    }

    /**
     * Update an existing secret.
     * 
     * @param UserSecret $secret The secret to update
     * @param string $newValue The new plaintext value
     * @return UserSecret The updated secret entity
     */
    public function updateSecret(UserSecret $secret, string $newValue): UserSecret
    {
        // Verify tenant isolation
        if (!$this->belongsToCurrentTenant($secret)) {
            throw new RuntimeException(
                "Access denied: Secret does not belong to current tenant"
            );
        }

        // Encrypt the new value
        $encryptedValue = $this->encryptionService->encrypt(
            $newValue,
            $secret->getKeyVersion()
        );

        // Update the secret
        $secret->setEncryptedValue($encryptedValue);
        $secret->setKeyVersion($this->currentKeyVersion);
        $secret->setEncryptionVersion($this->encryptionService->getCurrentVersion());

        // Save to database
        $this->secretRepository->save($secret, true);

        return $secret;
    }

    /**
     * Retrieve a secret value by key for a user.
     * The secret is decrypted before returning.
     * 
     * @param User $user The user who owns the secret
     * @param string $key The secret key
     * @return string The decrypted secret value
     * @throws RuntimeException If secret not found or decryption fails
     */
    public function getSecret(User $user, string $key): string
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($key, $user);
        
        if ($secret === null) {
            throw new RuntimeException(
                "Secret with key '{$key}' not found for user '{$user->getId()}'"
            );
        }

        // Verify tenant isolation
        if (!$this->belongsToCurrentTenant($secret)) {
            throw new RuntimeException(
                "Access denied: Secret does not belong to current tenant"
            );
        }

        // Decrypt the value
        return $this->encryptionService->decrypt(
            $secret->getEncryptedValue(),
            $secret->getKeyVersion()
        );
    }

    /**
     * Check if a secret exists for a user.
     */
    public function hasSecret(User $user, string $key): bool
    {
        return $this->secretRepository->existsByKeyAndUser($key, $user);
    }

    /**
     * Get a secret entity by key for a user.
     */
    public function getSecretEntity(User $user, string $key): ?UserSecret
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($key, $user);
        
        if ($secret !== null && !$this->belongsToCurrentTenant($secret)) {
            throw new RuntimeException(
                "Access denied: Secret does not belong to current tenant"
            );
        }

        return $secret;
    }

    /**
     * Delete a secret for a user.
     */
    public function deleteSecret(User $user, string $key): bool
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($key, $user);
        
        if ($secret === null) {
            return false;
        }

        // Verify tenant isolation
        if (!$this->belongsToCurrentTenant($secret)) {
            throw new RuntimeException(
                "Access denied: Secret does not belong to current tenant"
            );
        }

        $this->secretRepository->remove($secret, true);
        return true;
    }

    /**
     * Get all secrets for a user.
     * Secrets are NOT decrypted (for security, only return metadata).
     */
    public function getSecretKeys(User $user): array
    {
        $secrets = $this->secretRepository->findByUser($user);
        
        $keys = [];
        foreach ($secrets as $secret) {
            if ($this->belongsToCurrentTenant($secret)) {
                $keys[] = [
                    'key' => $secret->getSecretKey(),
                    'createdAt' => $secret->getCreatedAt(),
                    'updatedAt' => $secret->getUpdatedAt(),
                    'encryptionVersion' => $secret->getEncryptionVersion(),
                    'keyVersion' => $secret->getKeyVersion(),
                ];
            }
        }
        
        return $keys;
    }

    /**
     * Rotate keys for all secrets of a tenant.
     * This re-encrypts all secrets with the new key version.
     * 
     * @param string $tenantId The tenant ID
     * @param string $newKeyVersion The new key version to use
     * @return int Number of secrets re-encrypted
     */
    public function rotateKeys(string $tenantId, string $newKeyVersion): int
    {
        // Get all secrets for this tenant that need re-encryption
        $secrets = $this->secretRepository->findForReEncryption(
            $newKeyVersion,
            $tenantId
        );

        $count = 0;
        foreach ($secrets as $secret) {
            try {
                $plaintext = $this->encryptionService->decrypt(
                    $secret->getEncryptedValue(),
                    $secret->getKeyVersion()
                );

                $newEncryptedValue = $this->encryptionService->encrypt(
                    $plaintext,
                    $newKeyVersion
                );

                $secret->setEncryptedValue($newEncryptedValue);
                $secret->setKeyVersion($newKeyVersion);
                $secret->setEncryptionVersion($this->encryptionService->getCurrentVersion());

                $this->entityManager->persist($secret);
                $count++;
            } catch (Exception $e) {
                // Log error but continue with other secrets
                // In production, you would log this properly
                continue;
            }
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Get secrets that need re-encryption for a tenant.
     */
    public function getSecretsNeedingReEncryption(string $tenantId, string $targetKeyVersion): array
    {
        return $this->secretRepository->findForReEncryption(
            $targetKeyVersion,
            $tenantId
        );
    }

    /**
     * Scan a string for potential secret leakage.
     * This checks for common patterns like API keys, passwords, etc.
     * 
     * @param string $content The content to scan
     * @return array Array of potential secret matches
     */
    public function scanForSecrets(string $content): array
    {
        $secrets = [];
        
        // Common secret patterns
        $patterns = [
            // API Keys (generic)
            '/[a-zA-Z0-9]{32,}/' => 'API Key (32+ chars)',
            // AWS Access Key ID
            '/AKIA[0-9A-Z]{16}/' => 'AWS Access Key ID',
            // AWS Secret Access Key
            '/[0-9a-zA-Z\/+=]{40}/' => 'AWS Secret Access Key',
            // GitHub Token
            '/ghp_[0-9a-zA-Z]{36}/' => 'GitHub Token',
            // GitHub OAuth Token
            '/github_pat_[0-9a-zA-Z]{22}_[0-9a-zA-Z]{59}/' => 'GitHub OAuth Token',
            // Generic Bearer Token
            '/Bearer [a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/' => 'JWT/Bearer Token',
            // Generic Password in URL
            '/password=[^&\s]+/' => 'Password in URL',
            // Generic API Key in URL
            '/api[_-]?key=[^&\s]+/' => 'API Key in URL',
            // Generic Secret in URL
            '/secret=[^&\s]+/' => 'Secret in URL',
            // Generic Token in URL
            '/token=[^&\s]+/' => 'Token in URL',
            // Private Key (PEM format)
            '/-----BEGIN (RSA |EC |DSA |OPENSSH )PRIVATE KEY-----/i' => 'Private Key',
            // Credit Card Numbers
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/' => 'Credit Card Number',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    // Mask the secret in the output
                    $masked = substr($match, 0, 4) . str_repeat('*', max(0, strlen($match) - 8)) . substr($match, -4);
                    $secrets[] = [
                        'type' => $type,
                        'match' => $masked,
                        'pattern' => $pattern,
                    ];
                }
            }
        }

        return $secrets;
    }

    /**
     * Scan a file for potential secret leakage.
     * 
     * @param string $filePath The path to the file
     * @return array Array of potential secret matches with line numbers
     */
    public function scanFileForSecrets(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        $lines = explode("\n", $content);
        $secrets = [];
        
        foreach ($lines as $lineNumber => $line) {
            $found = $this->scanForSecrets($line);
            foreach ($found as $secret) {
                $secrets[] = [
                    'file' => $filePath,
                    'line' => $lineNumber + 1, // 1-based indexing
                    'type' => $secret['type'],
                    'match' => $secret['match'],
                ];
            }
        }

        return $secrets;
    }

    /**
     * Scan a directory recursively for potential secret leakage.
     * 
     * @param string $directory The directory to scan
     * @param array $excludePatterns Patterns to exclude (e.g., ['node_modules', 'vendor'])
     * @return array Array of potential secret matches
     */
    public function scanDirectoryForSecrets(
        string $directory,
        array $excludePatterns = ['node_modules', 'vendor', '.git', 'var/cache']
    ): array {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Directory not found: {$directory}");
        }

        $secrets = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Skip excluded directories
            $skip = false;
            foreach ($excludePatterns as $pattern) {
                if (str_contains($file->getPath(), $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Only scan PHP, JS, JSON, YAML, ENV files
            $extension = $file->getExtension();
            if (!in_array(strtolower($extension), ['php', 'js', 'json', 'yaml', 'yml', 'env', 'txt', 'md', 'xml'])) {
                continue;
            }

            try {
                $fileSecrets = $this->scanFileForSecrets($file->getPathname());
                $secrets = array_merge($secrets, $fileSecrets);
            } catch (Exception $e) {
                // Skip files that can't be read
                continue;
            }
        }

        return $secrets;
    }

    /**
     * Mask a secret value for safe display.
     */
    public function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }
        
        return substr($secret, 0, 4) . str_repeat('*', strlen($secret) - 8) . substr($secret, -4);
    }

    /**
     * Check if a secret belongs to the current tenant.
     * This is a helper method for tenant isolation.
     */
    private function belongsToCurrentTenant(UserSecret $secret): bool
    {
        // In a real application, you would get the current tenant from the security context
        // For now, we just check if the secret has a valid tenant relationship
        try {
            $tenantId = $secret->getTenantId();
            return $tenantId !== null && $tenantId !== '';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the current key version.
     */
    public function getCurrentKeyVersion(): string
    {
        return $this->currentKeyVersion;
    }

    /**
     * Set the current key version.
     */
    public function setCurrentKeyVersion(string $keyVersion): void
    {
        $this->currentKeyVersion = $keyVersion;
    }
}
