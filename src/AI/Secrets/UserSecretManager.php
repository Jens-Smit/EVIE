<?php

namespace App\AI\Secrets;

use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Entity\UserSecret;
use App\Repository\SecretRequestRepository;
use App\Repository\UserSecretRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manager für User-Secrets
 * 
 * Verwaltet das Speichern, Abrufen und Verwalten von verschlüsselten User-Secrets.
 */
class UserSecretManager
{
    public function __construct(
        private UserSecretRepository $secretRepo,
        private SecretRequestRepository $requestRepo,
        private EncryptionService $encryption,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Prüft, ob ein Secret für den User existiert
     * 
     * @param User $user Der User
     * @param string $secretKey Der Key des Secrets
     * @return bool True, falls das Secret existiert
     */
    public function hasSecret(User $user, string $secretKey): bool
    {
        return $this->secretRepo->existsForUser($secretKey, $user);
    }

    /**
     * Holt den Wert eines Secrets
     * 
     * @param User $user Der User
     * @param string $secretKey Der Key des Secrets
     * @return string|null Der entschlüsselte Wert oder null, falls nicht gefunden
     */
    public function getSecret(User $user, string $secretKey): ?string
    {
        $secret = $this->secretRepo->findByKeyAndUser($secretKey, $user);
        
        if (!$secret || !$secret->isActive()) {
            return null;
        }
        
        return $secret->getValue($this->encryption);
    }

    /**
     * Speichert ein neues Secret
     * 
     * @param User $user Der User
     * @param string $key Der Key des Secrets
     * @param string $value Der Wert des Secrets
     * @param string|null $description Die Beschreibung des Secrets
     * @param string|null $toolName Der Name des Tools, für das das Secret benötigt wird
     * @return UserSecret Das gespeicherte Secret
     */
    public function setSecret(
        User $user,
        string $key,
        string $value,
        ?string $description = null,
        ?string $toolName = null
    ): UserSecret {
        $existing = $this->secretRepo->findByKeyAndUser($key, $user);
        
        if ($existing) {
            $existing->setValue($value, $this->encryption);
            $existing->setDescription($description);
            $existing->setToolName($toolName);
            $existing->setUpdatedAt(new \DateTimeImmutable());
            
            $this->entityManager->flush();
            return $existing;
        }
        
        $secret = new UserSecret();
        $secret->setUser($user);
        $secret->setKey($key);
        $secret->setValue($value, $this->encryption);
        $secret->setDescription($description);
        $secret->setToolName($toolName);
        
        $this->entityManager->persist($secret);
        $this->entityManager->flush();
        
        return $secret;
    }

    /**
     * Löscht ein Secret
     * 
     * @param User $user Der User
     * @param string $key Der Key des Secrets
     */
    public function deleteSecret(User $user, string $key): void
    {
        $this->secretRepo->deleteByKeyAndUser($key, $user);
    }

    /**
     * Gibt alle Secrets eines Users zurück
     * 
     * @param User $user Der User
     * @return array Array von UserSecret-Entitäten
     */
    public function getAllSecrets(User $user): array
    {
        return $this->secretRepo->findAllActiveForUser($user);
    }

    /**
     * Gibt alle Secrets für ein spezifisches Tool zurück
     * 
     * @param User $user Der User
     * @param string $toolName Der Name des Tools
     * @return array Array von UserSecret-Entitäten
     */
    public function getSecretsForTool(User $user, string $toolName): array
    {
        return $this->secretRepo->findByTool($user, $toolName);
    }

    /**
     * Prüft, welche Secrets für ein Tool benötigt werden
     * 
     * @param ToolDefinition $tool Die Tool-Definition
     * @return array Array von SecretRequest-Entitäten
     */
    public function getRequiredSecretsForTool(ToolDefinition $tool): array
    {
        return $this->requestRepo->findByTool($tool);
    }

    /**
     * Prüft, ob alle benötigten Secrets für ein Tool vorhanden sind
     * 
     * @param User $user Der User
     * @param ToolDefinition $tool Die Tool-Definition
     * @return bool True, falls alle benötigten Secrets vorhanden sind
     */
    public function hasAllRequiredSecrets(User $user, ToolDefinition $tool): bool
    {
        $requiredSecrets = $this->getRequiredSecretsForTool($tool);
        
        foreach ($requiredSecrets as $request) {
            if ($request->isRequired() && !$this->hasSecret($user, $request->getSecretKey())) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Gibt eine Liste der fehlenden Secrets für ein Tool zurück
     * 
     * @param User $user Der User
     * @param ToolDefinition $tool Die Tool-Definition
     * @return array Array mit Informationen zu fehlenden Secrets
     */
    public function getMissingSecrets(User $user, ToolDefinition $tool): array
    {
        $requiredSecrets = $this->getRequiredSecretsForTool($tool);
        $missing = [];
        
        foreach ($requiredSecrets as $request) {
            if ($request->isRequired() && !$this->hasSecret($user, $request->getSecretKey())) {
                $missing[] = [
                    'key' => $request->getSecretKey(),
                    'description' => $request->getDescription(),
                    'tool' => $tool->getName(),
                    'is_required' => $request->isRequired()
                ];
            }
        }
        
        return $missing;
    }

    /**
     * Gibt die ersten fehlenden Secrets für ein Tool zurück
     * 
     * @param User $user Der User
     * @param ToolDefinition $tool Die Tool-Definition
     * @return array|null Array mit dem ersten fehlenden Secret oder null
     */
    public function getFirstMissingSecret(User $user, ToolDefinition $tool): ?array
    {
        $missing = $this->getMissingSecrets($user, $tool);
        return $missing[0] ?? null;
    }

    /**
     * Deaktiviert ein Secret
     * 
     * @param User $user Der User
     * @param string $key Der Key des Secrets
     */
    public function deactivateSecret(User $user, string $key): void
    {
        $secret = $this->secretRepo->findByKeyAndUser($key, $user);
        
        if ($secret) {
            $secret->setIsActive(false);
            $this->entityManager->flush();
        }
    }

    /**
     * Aktiviert ein Secret
     * 
     * @param User $user Der User
     * @param string $key Der Key des Secrets
     */
    public function activateSecret(User $user, string $key): void
    {
        $secret = $this->secretRepo->findByKeyAndUser($key, $user);
        
        if ($secret) {
            $secret->setIsActive(true);
            $this->entityManager->flush();
        }
    }

    /**
     * Sucht nach Secrets, die einen bestimmten Text enthalten
     * 
     * @param User $user Der User
     * @param string $query Der Suchbegriff
     * @return array Array von UserSecret-Entitäten
     */
    public function searchSecrets(User $user, string $query): array
    {
        return $this->secretRepo->searchForUser($user, $query);
    }

    /**
     * Gibt die Anzahl der Secrets für einen User zurück
     * 
     * @param User $user Der User
     * @return int Die Anzahl der Secrets
     */
    public function countSecrets(User $user): int
    {
        return $this->secretRepo->countForUser($user);
    }

    /**
     * Löscht alle Secrets für einen User
     * 
     * @param User $user Der User
     */
    public function deleteAllSecrets(User $user): void
    {
        $this->secretRepo->deleteAllForUser($user);
    }
}
