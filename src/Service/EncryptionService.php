<?php

namespace App\Service;

/**
 * Service für die Verschlüsselung und Entschlüsselung von sensiblen Daten
 * 
 * Verwendet AES-256-CBC für eine sichere Verschlüsselung.
 * Der Encryption-Key muss 32 Bytes (256 Bits) lang sein.
 */
class EncryptionService
{
    /**
     * @var string Der Verschlüsselungs-Algorithmus
     */
    private string $encryptionAlgorithm;

    /**
     * @var string Der Verschlüsselungs-Key (32 Bytes für AES-256)
     */
    private string $encryptionKey;

    public function __construct(
        string $encryptionKey,
        string $encryptionAlgorithm = 'AES-256-CBC'
    ) {
        $this->encryptionAlgorithm = $encryptionAlgorithm;
        
        // Validierung des Keys
        if (strlen($encryptionKey) !== 32) {
            throw new \InvalidArgumentException(
                'Encryption key must be exactly 32 bytes (256 bits) long. ' .
                'Current length: ' . strlen($encryptionKey) . ' bytes.'
            );
        }
        
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Verschlüsselt Daten
     * 
     * @param string $data Die zu verschlüsselnden Daten
     * @return string Die verschlüsselten Daten (Base64-kodiert)
     * @throws \RuntimeException Falls die Verschlüsselung fehlschlägt
     */
    public function encrypt(string $data): string
    {
        // Generiere einen zufälligen Initialization Vector (IV)
        $iv = openssl_random_pseudo_bytes(
            openssl_cipher_iv_length($this->encryptionAlgorithm)
        );
        
        if ($iv === false) {
            throw new \RuntimeException('Failed to generate initialization vector');
        }
        
        // Verschlüssle die Daten
        $encrypted = openssl_encrypt(
            $data,
            $this->encryptionAlgorithm,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }
        
        // Kombiniere IV und verschlüsselte Daten und kodiere als Base64
        return base64_encode($iv . $encrypted);
    }

    /**
     * Entschlüsselt Daten
     * 
     * @param string $encryptedData Die verschlüsselten Daten (Base64-kodiert)
     * @return string Die entschlüsselten Daten
     * @throws \InvalidArgumentException Falls die Daten ungültig sind
     * @throws \RuntimeException Falls die Entschlüsselung fehlschlägt
     */
    public function decrypt(string $encryptedData): string
    {
        // Dekodiere die Base64-Daten
        $data = base64_decode($encryptedData);
        
        if ($data === false) {
            throw new \InvalidArgumentException('Invalid base64 encoding');
        }
        
        // Extrahiere den IV und die verschlüsselten Daten
        $ivLength = openssl_cipher_iv_length($this->encryptionAlgorithm);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        if ($iv === false || $encrypted === false) {
            throw new \InvalidArgumentException('Invalid encrypted data format');
        }
        
        // Entschlüssle die Daten
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->encryptionAlgorithm,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }
        
        return $decrypted;
    }

    /**
     * Verschlüsselt ein Array von Daten
     * 
     * @param array $data Die zu verschlüsselnden Daten
     * @return string Die verschlüsselten Daten (Base64-kodiert)
     */
    public function encryptArray(array $data): string
    {
        $json = json_encode($data);
        
        if ($json === false) {
            throw new \RuntimeException('Failed to encode array as JSON');
        }
        
        return $this->encrypt($json);
    }

    /**
     * Entschlüsselt Daten zu einem Array
     * 
     * @param string $encryptedData Die verschlüsselten Daten (Base64-kodiert)
     * @return array Die entschlüsselten Daten
     */
    public function decryptToArray(string $encryptedData): array
    {
        $json = $this->decrypt($encryptedData);
        
        $data = json_decode($json, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }

    /**
     * Prüft, ob ein String gültige verschlüsselte Daten sind
     * 
     * @param string $data Die zu prüfenden Daten
     * @return bool True, falls die Daten gültig sind
     */
    public function isValidEncryptedData(string $data): bool
    {
        try {
            // Versuche, die Daten zu entschlüsseln
            $this->decrypt($data);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Gibt die Länge des Initialization Vectors zurück
     * 
     * @return int Die Länge des IV in Bytes
     */
    public function getIvLength(): int
    {
        return openssl_cipher_iv_length($this->encryptionAlgorithm);
    }

    /**
     * Gibt den verwendeten Algorithmus zurück
     * 
     * @return string Der Algorithmus
     */
    public function getAlgorithm(): string
    {
        return $this->encryptionAlgorithm;
    }

    /**
     * Generiert einen sicheren Encryption-Key
     * 
     * @return string Ein 32-Byte-Key (Base64-kodiert)
     */
    public static function generateKey(): string
    {
        $key = openssl_random_pseudo_bytes(32);
        
        if ($key === false) {
            throw new \RuntimeException('Failed to generate random key');
        }
        
        return base64_encode($key);
    }

    /**
     * Validiert einen Encryption-Key
     * 
     * @param string $key Der zu validierende Key
     * @return bool True, falls der Key gültig ist
     */
    public static function validateKey(string $key): bool
    {
        // Falls der Key Base64-kodiert ist, dekodieren
        $decoded = base64_decode($key, true);
        
        if ($decoded !== false) {
            $key = $decoded;
        }
        
        return strlen($key) === 32;
    }
}
