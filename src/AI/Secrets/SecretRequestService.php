<?php

namespace App\AI\Secrets;

use App\Entity\SecretRequest;
use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Repository\SecretRequestRepository;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service für Secret-Anforderungen
 * 
 * Erstellt und verwaltet Anfragen für Secrets, die von Tools benötigt werden.
 */
class SecretRequestService
{
    public function __construct(
        private UserSecretManager $secretManager,
        private ToolDefinitionRepository $toolRepo,
        private SecretRequestRepository $requestRepo,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Erstellt eine Anfrage für ein Secret, das ein Tool benötigt
     * 
     * @param string $toolName Der Name des Tools
     * @param string $secretKey Der Key des Secrets
     * @param string $description Die Beschreibung für den User
     * @param bool $isRequired Ob das Secret erforderlich ist
     * @return SecretRequest Die erstellte SecretRequest
     * @throws \InvalidArgumentException Falls das Tool nicht gefunden wird
     */
    public function requestSecretForTool(
        string $toolName,
        string $secretKey,
        string $description,
        bool $isRequired = true
    ): SecretRequest {
        $tool = $this->toolRepo->findOneBy(['name' => $toolName]);
        
        if (!$tool) {
            throw new \InvalidArgumentException("Tool '$toolName' nicht gefunden");
        }
        
        // Prüfe, ob bereits eine Anfrage für dieses Secret und Tool existiert
        $existing = $this->requestRepo->findOneBy([
            'tool' => $tool,
            'secretKey' => $secretKey
        ]);
        
        if ($existing) {
            // Aktualisiere die bestehende Anfrage
            $existing->setDescription($description);
            $existing->setIsRequired($isRequired);
            $this->entityManager->flush();
            return $existing;
        }
        
        $request = new SecretRequest();
        $request->setTool($tool);
        $request->setSecretKey($secretKey);
        $request->setDescription($description);
        $request->setIsRequired($isRequired);
        
        $this->entityManager->persist($request);
        $this->entityManager->flush();
        
        return $request;
    }

    /**
     * Gibt eine User-freundliche Nachricht zurück, wenn ein Secret fehlt
     * 
     * @param User $user Der User
     * @param ToolDefinition $tool Die Tool-Definition
     * @return string|null Die Nachricht oder null, falls keine Secrets fehlen
     */
    public function getMissingSecretMessage(User $user, ToolDefinition $tool): ?string
    {
        $missing = $this->secretManager->getMissingSecrets($user, $tool);
        
        if (empty($missing)) {
            return null;
        }
        
        $messages = [];
        foreach ($missing as $secret) {
            $messages[] = sprintf(
                "Ich benötige einen **%s** für das Tool '%s'. Bitte hinterlege ihn in den [Einstellungen](%s). %s",
                $secret['key'],
                $secret['tool'],
                '/settings/secrets',
                $secret['description']
            );
        }
        
        return implode("\n\n", $messages);
    }

    /**
     * Gibt eine kurze Nachricht für ein fehlendes Secret zurück
     * 
     * @param User $user Der User
     * @param ToolDefinition $tool Die Tool-Definition
     * @return string|null Die Nachricht oder null
     */
    public function getShortMissingSecretMessage(User $user, ToolDefinition $tool): ?string
    {
        $missing = $this->secretManager->getFirstMissingSecret($user, $tool);
        
        if (!$missing) {
            return null;
        }
        
        return sprintf(
            "Bitte hinterlegen Sie einen **%s** in den [Einstellungen](%s), um dieses Tool zu verwenden.",
            $missing['key'],
            '/settings/secrets'
        );
    }

    /**
     * Prüft, ob ein Tool Secrets benötigt
     * 
     * @param ToolDefinition $tool Die Tool-Definition
     * @return bool True, falls das Tool Secrets benötigt
     */
    public function toolRequiresSecrets(ToolDefinition $tool): bool
    {
        return $this->requestRepo->hasRequestsForTool($tool);
    }

    /**
     * Gibt alle Tools zurück, die Secrets benötigen
     * 
     * @return array Array von ToolDefinition-Entitäten
     */
    public function getToolsRequiringSecrets(): array
    {
        $allTools = $this->toolRepo->findAll();
        $toolsWithSecrets = [];
        
        foreach ($allTools as $tool) {
            if ($this->toolRequiresSecrets($tool)) {
                $toolsWithSecrets[] = $tool;
            }
        }
        
        return $toolsWithSecrets;
    }

    /**
     * Gibt alle SecretRequests für ein Tool zurück
     * 
     * @param ToolDefinition $tool Die Tool-Definition
     * @return array Array von SecretRequest-Entitäten
     */
    public function getSecretRequestsForTool(ToolDefinition $tool): array
    {
        return $this->requestRepo->findByTool($tool);
    }

    /**
     * Löscht alle SecretRequests für ein Tool
     * 
     * @param ToolDefinition $tool Die Tool-Definition
     */
    public function deleteSecretRequestsForTool(ToolDefinition $tool): void
    {
        $this->requestRepo->deleteAllForTool($tool);
    }

    /**
     * Erstellt SecretRequests aus einem SecretAwareToolInterface
     * 
     * @param ToolDefinition $tool Die Tool-Definition
     * @param string $toolClass Die Klasse des Tools
     */
    public function createRequestsFromToolInterface(ToolDefinition $tool, string $toolClass): void
    {
        if (!class_exists($toolClass)) {
            return;
        }
        
        $reflection = new \ReflectionClass($toolClass);
        
        if (!$reflection->implementsInterface('App\AI\Skills\SecretAwareToolInterface')) {
            return;
        }
        
        $requiredSecrets = $toolClass::getRequiredSecrets();
        
        foreach ($requiredSecrets as $secretDef) {
            $this->requestSecretForTool(
                $tool->getName(),
                $secretDef['key'],
                $secretDef['description'] ?? '',
                $secretDef['is_required'] ?? true
            );
        }
    }

    /**
     * Prüft, ob ein User alle Secrets für alle Tools hat
     * 
     * @param User $user Der User
     * @return bool True, falls der User alle benötigten Secrets hat
     */
    public function userHasAllRequiredSecrets(User $user): bool
    {
        $tools = $this->getToolsRequiringSecrets();
        
        foreach ($tools as $tool) {
            if (!$this->secretManager->hasAllRequiredSecrets($user, $tool)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Gibt eine Liste aller fehlenden Secrets für einen User zurück
     * 
     * @param User $user Der User
     * @return array Array mit Informationen zu fehlenden Secrets
     */
    public function getAllMissingSecretsForUser(User $user): array
    {
        $tools = $this->getToolsRequiringSecrets();
        $allMissing = [];
        
        foreach ($tools as $tool) {
            $missing = $this->secretManager->getMissingSecrets($user, $tool);
            $allMissing = array_merge($allMissing, $missing);
        }
        
        return $allMissing;
    }
}
