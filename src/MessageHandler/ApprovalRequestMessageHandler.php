<?php

namespace App\MessageHandler;

use App\Message\ApprovalRequestMessage;
use App\Repository\Tenant\UserRepository;
use App\Repository\Tenant\TenantRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * ApprovalRequestMessageHandler processes approval requests.
 * 
 * This handler:
 * - Logs the approval request
 * - Notifies administrators
 * - Stores the request for manual approval
 * - Can auto-approve based on rules (future feature)
 */
#[AsMessageHandler]
class ApprovalRequestMessageHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private TenantRepository $tenantRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the ApprovalRequestMessage.
     */
    public function __invoke(ApprovalRequestMessage $message): void
    {
        $approvalId = $message->getApprovalId();
        $tenantId = $message->getTenantId();
        $userId = $message->getUserId();
        $action = $message->getAction();
        $resource = $message->getResource();
        $riskLevel = $message->getRiskLevel();

        $this->logger->info('Processing approval request', [
            'approvalId' => $approvalId,
            'tenantId' => $tenantId,
            'userId' => $userId,
            'action' => $action,
            'resource' => $resource,
            'riskLevel' => $riskLevel,
        ]);

        try {
            // Step 1: Log the approval request
            $this->logApprovalRequest($message);

            // Step 2: Store the request
            $this->storeApprovalRequest($message);

            // Step 3: Notify administrators
            $this->notifyAdministrators($message);

            $this->logger->info('Approval request processed', [
                'approvalId' => $approvalId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process approval request', [
                'approvalId' => $approvalId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Log the approval request.
     */
    private function logApprovalRequest(ApprovalRequestMessage $message): void
    {
        $context = $message->toArray();
        
        // Remove sensitive data from logs
        unset($context['context']); // Remove potentially sensitive context

        $this->logger->warning('Approval request received', $context);
    }

    /**
     * Store the approval request for manual processing.
     */
    private function storeApprovalRequest(ApprovalRequestMessage $message): void
    {
        // In a real implementation, you would:
        // 1. Store the request in an approval_request table
        // 2. Store the full context
        // 3. Set an expiration time
        
        // For now, we'll just log it
        $this->logger->debug('Approval request stored for manual processing', [
            'approvalId' => $message->getApprovalId(),
        ]);
    }

    /**
     * Notify administrators about the approval request.
     */
    private function notifyAdministrators(ApprovalRequestMessage $message): void
    {
        $tenantId = $message->getTenantId();
        $tenant = $this->tenantRepository->find($tenantId);

        if ($tenant === null) {
            $this->logger->warning('Tenant not found for approval request', [
                'tenantId' => $tenantId,
            ]);
            return;
        }

        // In a real implementation, you would:
        // 1. Find administrators for this tenant
        // 2. Send email notifications
        // 3. Send Slack/Teams messages
        // 4. Create dashboard notifications

        // For now, we'll just log it
        $this->logger->alert('Approval request requires attention', [
            'approvalId' => $message->getApprovalId(),
            'tenantId' => $tenantId,
            'tenantName' => $tenant->getName(),
            'action' => $message->getAction(),
            'resource' => $message->getResource(),
            'riskLevel' => $message->getRiskLevel(),
            'userId' => $message->getUserId(),
        ]);
    }
}
