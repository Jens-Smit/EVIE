<?php

namespace App\Service\Security;

use App\Entity\Security\Policy;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Repository\Security\PolicyRepository;
use App\Message\ApprovalRequestMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * SecurityGuard enforces security policies and handles Human-in-the-Loop (HITL) approvals.
 * 
 * Features:
 * - Policy evaluation
 * - Risk classification
 * - Approval request handling
 * - Policy management
 * - Audit logging
 * - Human-in-the-Loop integration
 */
class SecurityGuard
{
    private const array RISK_LEVELS = [
        'low' => 0,
        'medium' => 1,
        'high' => 2,
        'critical' => 3,
    ];

    private const array DEFAULT_POLICIES = [
        [
            'identifier' => 'email_send',
            'name' => 'Email Send Policy',
            'description' => 'Controls sending of emails',
            'policyType' => 'action',
            'effect' => 'ask',
            'actions' => ['email:send', 'email:reply', 'email:forward'],
            'resources' => ['email'],
            'priority' => 100,
        ],
        [
            'identifier' => 'email_delete',
            'name' => 'Email Delete Policy',
            'description' => 'Controls deletion of emails',
            'policyType' => 'action',
            'effect' => 'deny',
            'actions' => ['email:delete', 'email:bulk_delete'],
            'resources' => ['email'],
            'priority' => 200,
        ],
        [
            'identifier' => 'file_delete',
            'name' => 'File Delete Policy',
            'description' => 'Controls deletion of files',
            'policyType' => 'action',
            'effect' => 'deny',
            'actions' => ['file:delete', 'file:bulk_delete'],
            'resources' => ['file'],
            'priority' => 200,
        ],
        [
            'identifier' => 'database_write',
            'name' => 'Database Write Policy',
            'description' => 'Controls write operations to databases',
            'policyType' => 'action',
            'effect' => 'deny',
            'actions' => ['database:write', 'database:update', 'database:delete'],
            'resources' => ['database'],
            'priority' => 300,
        ],
        [
            'identifier' => 'system_execute',
            'name' => 'System Execute Policy',
            'description' => 'Controls execution of system commands',
            'policyType' => 'action',
            'effect' => 'deny',
            'actions' => ['system:execute', 'system:command'],
            'resources' => ['system'],
            'priority' => 400,
        ],
        [
            'identifier' => 'web_browse',
            'name' => 'Web Browsing Policy',
            'description' => 'Controls web browsing',
            'policyType' => 'action',
            'effect' => 'allow',
            'actions' => ['web:browse', 'web:search'],
            'resources' => ['web'],
            'priority' => 50,
        ],
        [
            'identifier' => 'ai_generate',
            'name' => 'AI Generation Policy',
            'description' => 'Controls AI content generation',
            'policyType' => 'action',
            'effect' => 'allow',
            'actions' => ['ai:generate', 'ai:complete', 'ai:summarize'],
            'resources' => ['ai'],
            'priority' => 10,
        ],
    ];

    public function __construct(
        private PolicyRepository $policyRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Evaluate a security policy for an action.
     * 
     * @param Tenant $tenant The tenant
     * @param string $action The action to evaluate
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return string The effect (allow, deny, ask)
     */
    public function evaluate(
        Tenant $tenant,
        string $action,
        ?string $resource = null,
        array $context = []
    ): string {
        $context = array_merge($context, [
            'action' => $action,
            'resource' => $resource,
            'tenantId' => $tenant->getId(),
        ]);

        // Get all enabled policies for this tenant
        $policies = $this->policyRepository->findEnabledByTenant($tenant->getId());

        // Sort by priority (highest first)
        usort($policies, function(Policy $a, Policy $b) {
            return $b->getPriority() <=> $a->getPriority();
        });

        // Evaluate each policy
        foreach ($policies as $policy) {
            $effect = $policy->evaluate($context);
            
            if ($effect !== 'allow') {
                $this->logger->debug('Policy evaluation result', [
                    'policyId' => $policy->getId(),
                    'policyIdentifier' => $policy->getIdentifier(),
                    'action' => $action,
                    'resource' => $resource,
                    'effect' => $effect,
                ]);
                
                return $effect;
            }
        }

        // If no policy matches, default to allow
        $this->logger->debug('No policy matched, defaulting to allow', [
            'action' => $action,
            'resource' => $resource,
        ]);

        return 'allow';
    }

    /**
     * Check if an action is allowed.
     * 
     * @param Tenant $tenant The tenant
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return bool True if allowed
     */
    public function isAllowed(
        Tenant $tenant,
        string $action,
        ?string $resource = null,
        array $context = []
    ): bool {
        $effect = $this->evaluate($tenant, $action, $resource, $context);
        return $effect === 'allow';
    }

    /**
     * Check if an action is denied.
     * 
     * @param Tenant $tenant The tenant
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return bool True if denied
     */
    public function isDenied(
        Tenant $tenant,
        string $action,
        ?string $resource = null,
        array $context = []
    ): bool {
        $effect = $this->evaluate($tenant, $action, $resource, $context);
        return $effect === 'deny';
    }

    /**
     * Check if an action requires approval.
     * 
     * @param Tenant $tenant The tenant
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return bool True if approval is required
     */
    public function requiresApproval(
        Tenant $tenant,
        string $action,
        ?string $resource = null,
        array $context = []
    ): bool {
        $effect = $this->evaluate($tenant, $action, $resource, $context);
        return $effect === 'ask';
    }

    /**
     * Classify the risk level of an action.
     * 
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return string Risk level (low, medium, high, critical)
     */
    public function classifyRisk(
        string $action,
        ?string $resource = null,
        array $context = []
    ): string {
        // Define risk classification rules
        $highRiskActions = [
            'email:delete',
            'email:bulk_delete',
            'file:delete',
            'file:bulk_delete',
            'database:write',
            'database:update',
            'database:delete',
            'database:drop',
            'system:execute',
            'system:command',
            'system:shutdown',
            'user:delete',
            'user:disable',
            'tenant:delete',
        ];

        $mediumRiskActions = [
            'email:send',
            'email:reply',
            'email:forward',
            'file:write',
            'file:overwrite',
            'database:read',
            'user:update',
            'user:password_change',
        ];

        // Check action
        if (in_array($action, $highRiskActions, true)) {
            return 'high';
        }

        if (in_array($action, $mediumRiskActions, true)) {
            return 'medium';
        }

        // Check resource
        if ($resource !== null) {
            if (str_starts_with($resource, 'system:')) {
                return 'high';
            }

            if (str_starts_with($resource, 'admin:')) {
                return 'high';
            }

            if (str_starts_with($resource, 'secret:')) {
                return 'high';
            }
        }

        // Default to low risk
        return 'low';
    }

    /**
     * Request approval for an action.
     * 
     * @param Tenant $tenant The tenant
     * @param User $user The user requesting approval
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return string Approval request ID
     */
    public function requestApproval(
        Tenant $tenant,
        User $user,
        string $action,
        ?string $resource = null,
        array $context = []
    ): string {
        $approvalId = Ulid::generate();
        $riskLevel = $this->classifyRisk($action, $resource, $context);

        // Create approval request message
        $message = new ApprovalRequestMessage(
            approvalId: $approvalId,
            tenantId: $tenant->getId(),
            userId: $user->getId(),
            action: $action,
            resource: $resource,
            context: $context,
            riskLevel: $riskLevel,
            requestedAt: new \DateTimeImmutable(),
            status: 'pending'
        );

        // Dispatch message for async processing
        $this->messageBus->dispatch($message);

        $this->logger->info('Approval requested', [
            'approvalId' => $approvalId,
            'tenantId' => $tenant->getId(),
            'userId' => $user->getId(),
            'action' => $action,
            'resource' => $resource,
            'riskLevel' => $riskLevel,
        ]);

        return $approvalId;
    }

    /**
     * Check if an approval request exists and get its status.
     * 
     * @param string $approvalId The approval request ID
     * @return array Approval request status
     */
    public function getApprovalStatus(string $approvalId): array
    {
        // In a real implementation, you would query the approval request repository
        // For now, return a placeholder
        
        return [
            'approvalId' => $approvalId,
            'status' => 'pending', // pending, approved, rejected, expired
            'requestedAt' => (new \DateTimeImmutable())->format('c'),
            'action' => '',
            'resource' => null,
            'riskLevel' => 'medium',
            'context' => [],
        ];
    }

    /**
     * Approve an action.
     * 
     * @param string $approvalId The approval request ID
     * @param User $approvedBy The user who approved
     * @param string|null $comment Approval comment
     * @return bool True if approval was successful
     */
    public function approve(string $approvalId, User $approvedBy, ?string $comment = null): bool
    {
        // In a real implementation, you would:
        // 1. Find the approval request
        // 2. Update its status to 'approved'
        // 3. Log the approval
        // 4. Notify the requesting user

        $this->logger->info('Action approved', [
            'approvalId' => $approvalId,
            'approvedBy' => $approvedBy->getId(),
            'comment' => $comment,
        ]);

        return true;
    }

    /**
     * Reject an action.
     * 
     * @param string $approvalId The approval request ID
     * @param User $rejectedBy The user who rejected
     * @param string|null $reason Rejection reason
     * @return bool True if rejection was successful
     */
    public function reject(string $approvalId, User $rejectedBy, ?string $reason = null): bool
    {
        // In a real implementation, you would:
        // 1. Find the approval request
        // 2. Update its status to 'rejected'
        // 3. Log the rejection
        // 4. Notify the requesting user

        $this->logger->info('Action rejected', [
            'approvalId' => $approvalId,
            'rejectedBy' => $rejectedBy->getId(),
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Enforce a policy decision.
     * 
     * @param Tenant $tenant The tenant
     * @param User $user The user
     * @param string $action The action
     * @param string|null $resource The resource (optional)
     * @param array $context Additional context
     * @return array Enforcement result
     */
    public function enforce(
        Tenant $tenant,
        User $user,
        string $action,
        ?string $resource = null,
        array $context = []
    ): array {
        $effect = $this->evaluate($tenant, $action, $resource, $context);
        $riskLevel = $this->classifyRisk($action, $resource, $context);

        $result = [
            'allowed' => $effect === 'allow',
            'denied' => $effect === 'deny',
            'requiresApproval' => $effect === 'ask',
            'effect' => $effect,
            'riskLevel' => $riskLevel,
            'action' => $action,
            'resource' => $resource,
        ];

        // If approval is required, create an approval request
        if ($result['requiresApproval']) {
            $result['approvalId'] = $this->requestApproval(
                $tenant,
                $user,
                $action,
                $resource,
                $context
            );
        }

        // Log the enforcement
        $this->logger->info('Policy enforcement', [
            'tenantId' => $tenant->getId(),
            'userId' => $user->getId(),
            'action' => $action,
            'resource' => $resource,
            'effect' => $effect,
            'riskLevel' => $riskLevel,
        ]);

        return $result;
    }

    /**
     * Register default policies for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Policy[] Registered policies
     */
    public function registerDefaultPolicies(Tenant $tenant): array
    {
        $policies = [];

        foreach (self::DEFAULT_POLICIES as $policyData) {
            $policy = $this->policyRepository->findOneByIdentifierAndTenant(
                $policyData['identifier'],
                $tenant->getId()
            );

            if ($policy === null) {
                $policy = new Policy();
                $policy->setIdentifier($policyData['identifier']);
                $policy->setName($policyData['name']);
                $policy->setDescription($policyData['description']);
                $policy->setPolicyType($policyData['policyType']);
                $policy->setEffect($policyData['effect']);
                $policy->setActions($policyData['actions']);
                $policy->setResources($policyData['resources'] ?? null);
                $policy->setPriority($policyData['priority']);
                $policy->setTenant($tenant);
                $policy->setIsEnabled(true);

                $this->entityManager->persist($policy);
                $policies[] = $policy;
            }
        }

        if (!empty($policies)) {
            $this->entityManager->flush();
        }

        return $policies;
    }

    /**
     * Get all policies for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Policy[]
     */
    public function getPolicies(Tenant $tenant): array
    {
        return $this->policyRepository->findByTenant($tenant->getId());
    }

    /**
     * Get enabled policies for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Policy[]
     */
    public function getEnabledPolicies(Tenant $tenant): array
    {
        return $this->policyRepository->findEnabledByTenant($tenant->getId());
    }

    /**
     * Get a policy by identifier.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The policy identifier
     * @return Policy|null
     */
    public function getPolicy(Tenant $tenant, string $identifier): ?Policy
    {
        return $this->policyRepository->findOneByIdentifierAndTenant(
            $identifier,
            $tenant->getId()
        );
    }

    /**
     * Create a new policy.
     * 
     * @param Tenant $tenant The tenant
     * @param User $createdBy The user creating the policy
     * @param array $policyData Policy data
     * @return Policy The created policy
     */
    public function createPolicy(Tenant $tenant, User $createdBy, array $policyData): Policy
    {
        $policy = new Policy();
        $policy->setIdentifier($policyData['identifier']);
        $policy->setName($policyData['name']);
        $policy->setDescription($policyData['description'] ?? null);
        $policy->setPolicyType($policyData['policyType'] ?? 'action');
        $policy->setEffect($policyData['effect'] ?? 'allow');
        $policy->setActions($policyData['actions'] ?? []);
        $policy->setResources($policyData['resources'] ?? null);
        $policy->setConditions($policyData['conditions'] ?? []);
        $policy->setExceptions($policyData['exceptions'] ?? null);
        $policy->setPriority($policyData['priority'] ?? 0);
        $policy->setTenant($tenant);
        $policy->setCreatedBy($createdBy);
        $policy->setIsEnabled($policyData['isEnabled'] ?? true);
        $policy->setMetadata($policyData['metadata'] ?? null);

        $this->entityManager->persist($policy);
        $this->entityManager->flush();

        $this->logger->info('Policy created', [
            'policyId' => $policy->getId(),
            'identifier' => $policy->getIdentifier(),
            'tenantId' => $tenant->getId(),
            'createdBy' => $createdBy->getId(),
        ]);

        return $policy;
    }

    /**
     * Update a policy.
     * 
     * @param Policy $policy The policy to update
     * @param array $updates Updates to apply
     * @return Policy The updated policy
     */
    public function updatePolicy(Policy $policy, array $updates): Policy
    {
        if (isset($updates['identifier'])) {
            $policy->setIdentifier($updates['identifier']);
        }

        if (isset($updates['name'])) {
            $policy->setName($updates['name']);
        }

        if (isset($updates['description'])) {
            $policy->setDescription($updates['description']);
        }

        if (isset($updates['policyType'])) {
            $policy->setPolicyType($updates['policyType']);
        }

        if (isset($updates['effect'])) {
            $policy->setEffect($updates['effect']);
        }

        if (isset($updates['actions'])) {
            $policy->setActions($updates['actions']);
        }

        if (isset($updates['resources'])) {
            $policy->setResources($updates['resources']);
        }

        if (isset($updates['conditions'])) {
            $policy->setConditions($updates['conditions']);
        }

        if (isset($updates['exceptions'])) {
            $policy->setExceptions($updates['exceptions']);
        }

        if (isset($updates['priority'])) {
            $policy->setPriority($updates['priority']);
        }

        if (isset($updates['isEnabled'])) {
            $policy->setIsEnabled($updates['isEnabled']);
        }

        if (isset($updates['metadata'])) {
            $policy->setMetadata($updates['metadata']);
        }

        $this->entityManager->persist($policy);
        $this->entityManager->flush();

        $this->logger->info('Policy updated', [
            'policyId' => $policy->getId(),
            'identifier' => $policy->getIdentifier(),
        ]);

        return $policy;
    }

    /**
     * Delete a policy.
     * 
     * @param Policy $policy The policy to delete
     * @return bool True if deletion was successful
     */
    public function deletePolicy(Policy $policy): bool
    {
        $this->entityManager->remove($policy);
        $this->entityManager->flush();

        $this->logger->info('Policy deleted', [
            'policyId' => $policy->getId(),
            'identifier' => $policy->getIdentifier(),
        ]);

        return true;
    }

    /**
     * Enable a policy.
     * 
     * @param Policy $policy The policy to enable
     * @return Policy The enabled policy
     */
    public function enablePolicy(Policy $policy): Policy
    {
        $policy->enable();
        $this->entityManager->persist($policy);
        $this->entityManager->flush();

        $this->logger->info('Policy enabled', [
            'policyId' => $policy->getId(),
            'identifier' => $policy->getIdentifier(),
        ]);

        return $policy;
    }

    /**
     * Disable a policy.
     * 
     * @param Policy $policy The policy to disable
     * @return Policy The disabled policy
     */
    public function disablePolicy(Policy $policy): Policy
    {
        $policy->disable();
        $this->entityManager->persist($policy);
        $this->entityManager->flush();

        $this->logger->info('Policy disabled', [
            'policyId' => $policy->getId(),
            'identifier' => $policy->getIdentifier(),
        ]);

        return $policy;
    }

    /**
     * Get policy statistics for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Statistics
     */
    public function getStatistics(Tenant $tenant): array
    {
        return $this->policyRepository->getStatistics($tenant->getId());
    }

    /**
     * Get risk level numeric value.
     * 
     * @param string $riskLevel The risk level
     * @return int Numeric value
     */
    public function getRiskLevelValue(string $riskLevel): int
    {
        return self::RISK_LEVELS[$riskLevel] ?? 0;
    }

    /**
     * Compare risk levels.
     * 
     * @param string $level1 First risk level
     * @param string $level2 Second risk level
     * @return int -1 if level1 < level2, 0 if equal, 1 if level1 > level2
     */
    public function compareRiskLevels(string $level1, string $level2): int
    {
        $value1 = $this->getRiskLevelValue($level1);
        $value2 = $this->getRiskLevelValue($level2);

        if ($value1 < $value2) {
            return -1;
        }

        if ($value1 > $value2) {
            return 1;
        }

        return 0;
    }

    /**
     * Get the highest risk level from multiple levels.
     * 
     * @param string[] $riskLevels Array of risk levels
     * @return string Highest risk level
     */
    public function getHighestRiskLevel(array $riskLevels): string
    {
        if (empty($riskLevels)) {
            return 'low';
        }

        $highest = 'low';
        
        foreach ($riskLevels as $level) {
            if ($this->compareRiskLevels($level, $highest) > 0) {
                $highest = $level;
            }
        }

        return $highest;
    }
}
