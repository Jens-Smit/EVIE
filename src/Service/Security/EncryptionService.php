<?php

namespace App\Service\Security;

use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * EncryptionService provides AES-256-GCM encryption and decryption for secrets.
 * 
 * Features:
 * - AES-256-GCM encryption (authenticated encryption)
 * - Key versioning support
 * - Automatic IV and authentication tag handling
 * - Secure key management (master key from environment only)
 * 
 * @see https://www.php.net/manual/en/function.openssl-encrypt.php
 * @see https://www.php.net/manual/en/function.openssl-decrypt.php
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_VERSION_ENV_VAR = 'SECRET_ENCRYPTION_KEY';
    private const KEY_VERSIONS_ENV_VAR = 'SECRET_ENCRYPTION_KEY_VERSIONS';
    private const CURRENT_VERSION = 'AES-256-GCM-v1';
    
    /** @var array<string, string> Cache for key versions */
    private array $keyCache = [];
    
    /** @var string Current encryption version */
    private string $currentVersion;

    public function __construct(
        private string $masterKey,
        string $currentVersion = self::CURRENT_VERSION
    ) {
        $this->currentVersion = $currentVersion;
        
        // Validate master key length (256 bits = 32 bytes)
        if (strlen($this->masterKey) !== 32) {
            throw new InvalidArgumentException(
                'Master key must be exactly 32 bytes (256 bits) long for AES-256. ' .
                'Current length: ' . strlen($this->masterKey) . ' bytes.'
            );
        }
    }

    /**
     * Encrypt a plaintext value using AES-256-GCM.
     * 
     * @param string $plaintext The value to encrypt
     * @param string $keyVersion The key version to use (defaults to current)
     * @return string Encrypted value in format: iv:authTag:encryptedData
     * @throws RuntimeException If encryption fails
     */
    public function encrypt(string $plaintext, string $keyVersion = self::CURRENT_VERSION): string
    {
        // Generate a random IV (12 bytes for GCM)
        $iv = random_bytes(12);
        
        // Get the key for this version
        $key = $this->getKeyForVersion($keyVersion);
        
        // Encrypt using AES-256-GCM
        $encrypted = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag
        );

        if ($encrypted === false) {
            throw new RuntimeException(
                'Encryption failed: ' . openssl_error_string()
            );
        }

        // Combine IV, auth tag, and encrypted data
        // Format: iv:authTag:encryptedData (all base64 encoded)
        $result = base64_encode($iv) . ':' . base64_encode($authTag) . ':' . base64_encode($encrypted);
        
        return $result;
    }

    /**
     * Decrypt an encrypted value.
     * 
     * @param string $encryptedValue The encrypted value (iv:authTag:encryptedData)
     * @param string $keyVersion The key version to use
     * @return string The decrypted plaintext
     * @throws RuntimeException If decryption fails
     */
    public function decrypt(string $encryptedValue, string $keyVersion = self::CURRENT_VERSION): string
    {
        // Parse the encrypted value
        $parts = explode(':', $encryptedValue);
        if (count($parts) !== 3) {
            throw new RuntimeException(
                'Invalid encrypted value format. Expected iv:authTag:encryptedData'
            );
        }

        $iv = base64_decode($parts[0]);
        $authTag = base64_decode($parts[1]);
        $encryptedData = base64_decode($parts[2]);

        if ($iv === false || $authTag === false || $encryptedData === false) {
            throw new RuntimeException('Failed to decode base64 components');
        }

        // Get the key for this version
        $key = $this->getKeyForVersion($keyVersion);

        // Decrypt using AES-256-GCM
        $decrypted = openssl_decrypt(
            $encryptedData,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag
        );

        if ($decrypted === false) {
            throw new RuntimeException(
                'Decryption failed: ' . openssl_error_string()
            );
        }

        return $decrypted;
    }

    /**
     * Re-encrypt a value with a new key version.
     * Used during key rotation.
     * 
     * @param string $encryptedValue The encrypted value
     * @param string $fromVersion The current key version
     * @param string $toVersion The target key version
     * @return string The re-encrypted value
     */
    public function reEncrypt(
        string $encryptedValue,
        string $fromVersion,
        string $toVersion
    ): string {
        $plaintext = $this->decrypt($encryptedValue, $fromVersion);
        return $this->encrypt($plaintext, $toVersion);
    }

    /**
     * Get the current encryption version.
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    /**
     * Validate that a key is 256 bits (32 bytes) long.
     */
    public static function validateKey(string $key): bool
    {
        return strlen($key) === 32;
    }

    /**
     * Generate a new random 256-bit key.
     */
    public static function generateKey(): string
    {
        return random_bytes(32);
    }

    /**
     * Get the key for a specific version.
     * The current version uses the master key from the constructor.
     * For other versions, keys must be configured in the environment.
     * 
     * @throws RuntimeException If the key version is not found
     */
    private function getKeyForVersion(string $version): string
    {
        // Current version uses the master key
        if ($version === $this->currentVersion) {
            return $this->masterKey;
        }

        // Check cache
        if (isset($this->keyCache[$version])) {
            return $this->keyCache[$version];
        }

        // For other versions, we need to load from environment
        // This is a placeholder for key rotation support
        // In production, you would store old keys in a secure key management system
        $envVar = self::KEY_VERSIONS_ENV_VAR . '_' . strtoupper($version);
        
        if (!isset($_ENV[$envVar]) && !isset($_SERVER[$envVar])) {
            throw new RuntimeException(
                "Key for version '{$version}' not found. " .
                "Please configure it in the environment variable '{$envVar}'"
            );
        }

        $key = $_ENV[$envVar] ?? $_SERVER[$envVar];
        
        if (!self::validateKey($key)) {
            throw new RuntimeException(
                "Invalid key length for version '{$version}'. " .
                "Key must be exactly 32 bytes (256 bits) long."
            );
        }

        // Cache the key
        $this->keyCache[$version] = $key;
        
        return $key;
    }

    /**
     * Create a new EncryptionService instance with a specific master key.
     * Useful for testing or when you need multiple instances.
     */
    public static function createWithKey(string $masterKey): self
    {
        return new self($masterKey);
    }

    /**
     * Create a new EncryptionService instance from environment variable.
     */
    public static function createFromEnvironment(): self
    {
        if (!isset($_ENV[self::KEY_VERSION_ENV_VAR]) && !isset($_SERVER[self::KEY_VERSION_ENV_VAR])) {
            throw new RuntimeException(
                "Master key not found in environment variable '" . self::KEY_VERSION_ENV_VAR . "'"
            );
        }

        $masterKey = $_ENV[self::KEY_VERSION_ENV_VAR] ?? $_SERVER[self::KEY_VERSION_ENV_VAR];
        
        return new self($masterKey);
    }
}
