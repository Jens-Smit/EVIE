<?php

namespace App\Controller\Security;

use App\Entity\Security\Policy;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Form\Security\PolicyType;
use App\Repository\Security\PolicyRepository;
use App\Service\Security\SecurityGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SecurityController provides security management interfaces.
 * 
 * Features:
 * - Policy management (create, read, update, delete)
 * - Policy evaluation testing
 * - Approval request management
 * - Security audit
 * - Risk classification
 */
class SecurityController extends AbstractController
{
    public function __construct(
        private PolicyRepository $policyRepository,
        private SecurityGuard $securityGuard
    ) {
    }

    /**
     * Security Dashboard - Overview of security settings
     */
    #[Route('/security', name: 'security_dashboard')]
    #[IsGranted('ROLE_ADMIN')]
    public function dashboard(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get policies
        $policies = $this->policyRepository->findByTenant($tenant->getId());
        $enabledPolicies = $this->policyRepository->findEnabledByTenant($tenant->getId());

        // Get policy statistics
        $stats = $this->securityGuard->getStatistics($tenant);

        // Get recent approval requests (would be implemented with ApprovalRepository)
        $recentApprovals = [];

        // Get risk levels
        $riskLevels = [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ];

        return $this->render('security/dashboard.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'policies' => $policies,
            'enabledPolicies' => $enabledPolicies,
            'stats' => $stats,
            'recentApprovals' => $recentApprovals,
            'riskLevels' => $riskLevels,
            'currentRoute' => 'security',
        ]);
    }

    /**
     * List all policies for the tenant
     */
    #[Route('/security/policies', name: 'security_policies')]
    #[IsGranted('ROLE_ADMIN')]
    public function policies(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $type = $request->query->get('type');
        $effect = $request->query->get('effect');
        $search = $request->query->get('search');

        $policies = $this->policyRepository->findByTenant($tenant->getId());

        // Filter policies
        if ($type) {
            $policies = array_filter($policies, function($p) use ($type) {
                return $p->getPolicyType() === $type;
            });
        }

        if ($effect) {
            $policies = array_filter($policies, function($p) use ($effect) {
                return $p->getEffect() === $effect;
            });
        }

        if ($search) {
            $policies = array_filter($policies, function($p) use ($search) {
                return stripos($p->getName(), $search) !== false ||
                       stripos($p->getIdentifier(), $search) !== false ||
                       stripos($p->getDescription() ?? '', $search) !== false;
            });
        }

        // Sort by priority (highest first) then by name
        usort($policies, function($a, $b) {
            $priorityCompare = $b->getPriority() <=> $a->getPriority();
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }
            return strcasecmp($a->getName(), $b->getName());
        });

        $policyTypes = ['action', 'resource', 'access', 'custom'];
        $effects = ['allow', 'deny', 'ask'];

        return $this->render('security/policies.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'policies' => $policies,
            'policyTypes' => $policyTypes,
            'effects' => $effects,
            'typeFilter' => $type,
            'effectFilter' => $effect,
            'searchFilter' => $search,
            'currentRoute' => 'security_policies',
        ]);
    }

    /**
     * Create a new policy
     */
    #[Route('/security/policies/new', name: 'security_policy_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function newPolicy(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $policy = new Policy();
        $policy->setTenant($tenant);
        $policy->setCreatedBy($user);

        $form = $this->createForm(PolicyType::class, $policy);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($policy);
            $entityManager->flush();

            $this->addFlash('success', 'Policy created successfully!');

            return $this->redirectToRoute('security_policies');
        }

        return $this->render('security/policy_form.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'form' => $form->createView(),
            'policy' => $policy,
            'currentRoute' => 'security_policies',
        ]);
    }

    /**
     * Edit an existing policy
     */
    #[Route('/security/policies/{id}/edit', name: 'security_policy_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editPolicy(Policy $policy, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only edit policies from your tenant');
        }

        $form = $this->createForm(PolicyType::class, $policy);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($policy);
            $entityManager->flush();

            $this->addFlash('success', 'Policy updated successfully!');

            return $this->redirectToRoute('security_policies');
        }

        return $this->render('security/policy_form.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'form' => $form->createView(),
            'policy' => $policy,
            'currentRoute' => 'security_policies',
        ]);
    }

    /**
     * View a policy
     */
    #[Route('/security/policies/{id}', name: 'security_policy_view')]
    #[IsGranted('ROLE_ADMIN')]
    public function viewPolicy(Policy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view policies from your tenant');
        }

        return $this->render('security/policy_view.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'policy' => $policy,
            'currentRoute' => 'security_policies',
        ]);
    }

    /**
     * Delete a policy
     */
    #[Route('/security/policies/{id}/delete', name: 'security_policy_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deletePolicy(Policy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only delete policies from your tenant');
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($policy);
        $entityManager->flush();

        $this->addFlash('success', 'Policy deleted successfully!');

        return $this->redirectToRoute('security_policies');
    }

    /**
     * Enable a policy
     */
    #[Route('/security/policies/{id}/enable', name: 'security_policy_enable', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enablePolicy(Policy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only enable policies from your tenant');
        }

        $this->securityGuard->enablePolicy($policy);

        $this->addFlash('success', 'Policy enabled successfully!');

        return $this->redirectToRoute('security_policies');
    }

    /**
     * Disable a policy
     */
    #[Route('/security/policies/{id}/disable', name: 'security_policy_disable', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function disablePolicy(Policy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only disable policies from your tenant');
        }

        $this->securityGuard->disablePolicy($policy);

        $this->addFlash('success', 'Policy disabled successfully!');

        return $this->redirectToRoute('security_policies');
    }

    /**
     * Test policy evaluation
     */
    #[Route('/security/policies/test', name: 'security_policy_test', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function testPolicy(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $action = $request->request->get('action', 'email:send');
        $resource = $request->request->get('resource', 'email');
        $context = $request->request->all('context');

        if ($request->isMethod('POST')) {
            // Evaluate the policy
            $effect = $this->securityGuard->evaluate($tenant, $action, $resource, $context);
            $riskLevel = $this->securityGuard->classifyRisk($action, $resource, $context);

            // Get matching policies
            $matchingPolicies = $this->policyRepository->findByActionAndResource(
                $tenant->getId(),
                $action,
                $resource
            );

            return $this->render('security/policy_test.html.twig', [
                'user' => $user,
                'tenant' => $tenant,
                'action' => $action,
                'resource' => $resource,
                'context' => $context,
                'effect' => $effect,
                'riskLevel' => $riskLevel,
                'matchingPolicies' => $matchingPolicies,
                'currentRoute' => 'security_policies',
            ]);
        }

        // Get sample actions and resources
        $sampleActions = [
            'email:send',
            'email:read',
            'email:delete',
            'file:read',
            'file:write',
            'file:delete',
            'database:query',
            'database:write',
            'system:execute',
            'agent:execute',
        ];

        $sampleResources = [
            'email',
            'file',
            'database',
            'system',
            'agent',
            'user',
            'tenant',
        ];

        return $this->render('security/policy_test.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'action' => $action,
            'resource' => $resource,
            'context' => $context,
            'effect' => null,
            'riskLevel' => null,
            'matchingPolicies' => [],
            'sampleActions' => $sampleActions,
            'sampleResources' => $sampleResources,
            'currentRoute' => 'security_policies',
        ]);
    }

    /**
     * Approval Request Management
     */
    #[Route('/security/approvals', name: 'security_approvals')]
    #[IsGranted('ROLE_ADMIN')]
    public function approvals(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get approval requests (would be implemented with ApprovalRepository)
        $approvals = [];

        $status = $request->query->get('status');
        
        // Filter by status if implemented
        if ($status) {
            $approvals = array_filter($approvals, function($a) use ($status) {
                return $a['status'] === $status;
            });
        }

        $statuses = ['pending', 'approved', 'rejected', 'expired'];

        return $this->render('security/approvals.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'approvals' => $approvals,
            'statuses' => $statuses,
            'statusFilter' => $status,
            'currentRoute' => 'security_approvals',
        ]);
    }

    /**
     * View approval request details
     */
    #[Route('/security/approvals/{id}', name: 'security_approval_view')]
    #[IsGranted('ROLE_ADMIN')]
    public function viewApproval(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get approval request (would be implemented with ApprovalRepository)
        $approval = [
            'id' => $id,
            'status' => 'pending',
            'action' => 'email:send',
            'resource' => 'email',
            'riskLevel' => 'medium',
            'requestedAt' => (new \DateTimeImmutable())->format('c'),
            'user' => ['id' => 'user-id', 'email' => 'user@example.com'],
            'context' => [],
        ];

        return $this->render('security/approval_view.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'approval' => $approval,
            'currentRoute' => 'security_approvals',
        ]);
    }

    /**
     * Approve an action
     */
    #[Route('/security/approvals/{id}/approve', name: 'security_approval_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // In a real implementation, you would:
        // 1. Find the approval request
        // 2. Update its status to 'approved'
        // 3. Log the approval
        // 4. Notify the requesting user

        $this->addFlash('success', 'Approval granted successfully!');

        return $this->redirectToRoute('security_approvals');
    }

    /**
     * Reject an action
     */
    #[Route('/security/approvals/{id}/reject', name: 'security_approval_reject', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reject(Request $request, string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $reason = $request->request->get('reason', '');

        // In a real implementation, you would:
        // 1. Find the approval request
        // 2. Update its status to 'rejected'
        // 3. Store the reason
        // 4. Log the rejection
        // 5. Notify the requesting user

        $this->addFlash('success', 'Approval rejected successfully!');

        return $this->redirectToRoute('security_approvals');
    }

    /**
     * Security Audit Log
     */
    #[Route('/security/audit', name: 'security_audit')]
    #[IsGranted('ROLE_ADMIN')]
    public function audit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get audit logs (would be implemented with AuditRepository)
        $auditLogs = [];

        $action = $request->query->get('action');
        $userFilter = $request->query->get('user');
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');

        // Filter logs if implemented
        if ($action) {
            $auditLogs = array_filter($auditLogs, function($log) use ($action) {
                return $log['action'] === $action;
            });
        }

        return $this->render('security/audit.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'auditLogs' => $auditLogs,
            'actionFilter' => $action,
            'userFilter' => $userFilter,
            'startDateFilter' => $startDate,
            'endDateFilter' => $endDate,
            'currentRoute' => 'security_audit',
        ]);
    }

    /**
     * Register default policies for a tenant
     */
    #[Route('/security/policies/register-defaults', name: 'security_policy_register_defaults', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function registerDefaultPolicies(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $policies = $this->securityGuard->registerDefaultPolicies($tenant);

        $this->addFlash('success', sprintf('%d default policies registered successfully!', count($policies)));

        return $this->redirectToRoute('security_policies');
    }
}
