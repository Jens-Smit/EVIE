<?php

namespace App\Service;

use App\Entity\Secret;
use App\Repository\SecretRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service für die verschlüsselte Speicherung und Verwaltung von Secrets pro Tenant.
 * 
 * Verwendet AES-256-GCM Verschlüsselung mit einem Master-Key aus kernel.secret.
 * Secrets werden nie im Klartext gespeichert oder an das LLM zurückgegeben.
 */
class SecretService
{
    private string $encryptionKey;

    public function __construct(
        private readonly SecretRepository $secretRepository,
        #[Autowire(param: 'kernel.secret')]
        string $kernelSecret
    ) {
        // Der Encryption-Key wird aus dem kernel.secret abgeleitet
        // In Produktion sollte ein externes KMS/Vault verwendet werden
        $this->encryptionKey = hash('sha256', $kernelSecret, true);
    }

    /**
     * Setze ein Secret für einen Tenant (verschlüsselt).
     * 
     * @param string $keyName Der Name des Secrets (z.B. 'openweather_api_key')
     * @param string $value Der Wert des Secrets (im Klartext)
     * @param string $userIdentifier Der Tenant-Identifier
     * @param string|null $scope Optional: Scope/Bereich des Secrets
     */
    public function set(string $keyName, string $value, string $userIdentifier, ?string $scope = null): void
    {
        // Validierung
        if (empty($keyName)) {
            throw new \InvalidArgumentException('keyName darf nicht leer sein');
        }
        if (empty($value)) {
            throw new \InvalidArgumentException('value darf nicht leer sein');
        }
        if (empty($userIdentifier)) {
            throw new \InvalidArgumentException('userIdentifier darf nicht leer sein');
        }

        // Verschlüsseln
        $encryptedValue = $this->encrypt($value);

        // Existierendes Secret aktualisieren
        $existingSecret = $this->secretRepository->findOneByKeyAndUser($keyName, $userIdentifier);
        
        if ($existingSecret) {
            $existingSecret->setEncryptedValue($encryptedValue);
            $existingSecret->setScope($scope);
            $existingSecret->setUpdatedAt(new \DateTimeImmutable());
            $this->secretRepository->save($existingSecret, true);
            return;
        }

        // Neues Secret erstellen
        $secret = new Secret();
        $secret->setUserIdentifier($userIdentifier);
        $secret->setKeyName($keyName);
        $secret->setEncryptedValue($encryptedValue);
        $secret->setScope($scope);

        $this->secretRepository->save($secret, true);
    }

    /**
     * Hole ein Secret für einen Tenant (entschlüsselt).
     * 
     * @param string $keyName Der Name des Secrets
     * @param string $userIdentifier Der Tenant-Identifier
     * @return string|null Der entschlüsselte Wert oder null wenn nicht gefunden
     */
    public function get(string $keyName, string $userIdentifier): ?string
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($keyName, $userIdentifier);
        
        if (null === $secret) {
            return null;
        }

        return $this->decrypt($secret->getEncryptedValue());
    }

    /**
     * Lösche ein Secret für einen Tenant.
     * 
     * @param string $keyName Der Name des Secrets
     * @param string $userIdentifier Der Tenant-Identifier
     */
    public function delete(string $keyName, string $userIdentifier): void
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($keyName, $userIdentifier);
        
        if ($secret) {
            $this->secretRepository->remove($secret, true);
        }
    }

    /**
     * Prüfe ob ein Secret für einen Tenant existiert.
     * 
     * @param string $keyName Der Name des Secrets
     * @param string $userIdentifier Der Tenant-Identifier
     */
    public function exists(string $keyName, string $userIdentifier): bool
    {
        return null !== $this->secretRepository->findOneByKeyAndUser($keyName, $userIdentifier);
    }

    /**
     * Hole alle Secret-Namen für einen Tenant.
     * 
     * @param string $userIdentifier Der Tenant-Identifier
     * @return string[] Array von Secret-Namen
     */
    public function getKeysForUser(string $userIdentifier): array
    {
        $secrets = $this->secretRepository->findByUser($userIdentifier);
        
        return array_map(function(Secret $secret) {
            return $secret->getKeyName();
        }, $secrets);
    }

    /**
     * Hole alle Secrets für einen Tenant (als Array von key => value).
     * 
     * @param string $userIdentifier Der Tenant-Identifier
     * @return array<string, string> Array von Secret-Namen zu Werten
     */
    public function getAllForUser(string $userIdentifier): array
    {
        $secrets = $this->secretRepository->findByUser($userIdentifier);
        $result = [];
        
        foreach ($secrets as $secret) {
            $result[$secret->getKeyName()] = $this->decrypt($secret->getEncryptedValue());
        }
        
        return $result;
    }

    /**
     * Lösche alle Secrets für einen Tenant.
     * 
     * @param string $userIdentifier Der Tenant-Identifier
     */
    public function deleteAllForUser(string $userIdentifier): void
    {
        $secrets = $this->secretRepository->findByUser($userIdentifier);
        
        foreach ($secrets as $secret) {
            $this->secretRepository->remove($secret);
        }
        
        $this->secretRepository->getEntityManager()->flush();
    }

    /**
     * Aktualisiere das letzte Verwendungsdatum eines Secrets.
     * 
     * @param string $keyName Der Name des Secrets
     * @param string $userIdentifier Der Tenant-Identifier
     * @param string $toolName Der Name des Tools, das das Secret verwendet hat
     */
    public function updateLastUsed(string $keyName, string $userIdentifier, string $toolName): void
    {
        $secret = $this->secretRepository->findOneByKeyAndUser($keyName, $userIdentifier);
        
        if ($secret) {
            $this->secretRepository->updateLastUsed($secret, $toolName);
        }
    }

    /**
     * Verschlüssele einen Wert mit AES-256-GCM.
     */
    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12); // 96-bit IV für GCM
        
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $ciphertext) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }

        // Kombiniere IV + Tag + Ciphertext
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Entschlüssele einen Wert mit AES-256-GCM.
     */
    private function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        
        if (false === $data) {
            throw new \InvalidArgumentException('Ungültige Base64-Daten');
        }

        $ivLength = 12; // 96-bit IV
        $tagLength = 16; // 128-bit Tag
        
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plaintext) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen: ' . openssl_error_string());
        }

        return $plaintext;
    }
}
