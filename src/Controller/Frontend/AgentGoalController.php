<?php

namespace App\Controller\Frontend;

use App\AI\Security\AuditLogger;
use App\Entity\AgentGoal;
use App\Entity\User;
use App\Repository\AgentGoalRepository;
use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Verwaltung von autonomen Agent-Zielen.
 * 
 * HITL-geschützt: Erstellen und Aktivieren von Zielen erfordert Freigabe.
 */
class AgentGoalController extends AbstractController
{
    public function __construct(
        private AgentGoalRepository $agentGoalRepo,
        private UserProfileRepository $userProfileRepo,
        private AuditLogger $auditLogger,
    ) {
    }

    #[Route('/agent/goals', name: 'app_agent_goals', methods: ['GET'])]
    public function list(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $goals = $this->agentGoalRepo->findByUser($userIdentifier);

        return $this->render('agent/goals.html.twig', [
            'goals' => $goals,
        ]);
    }

    #[Route('/agent/goals', name: 'app_agent_goals_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description', ''));
        $cronExpression = trim((string) $request->request->get('cron_expression', ''));
        $capabilityConstraints = $request->request->all('capability_constraints', []);

        // Validierung
        if (empty($title)) {
            $this->addFlash('error', 'Der Titel darf nicht leer sein.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Validierung der Cron-Expression
        if (!empty($cronExpression)) {
            try {
                new \Cron\CronExpression($cronExpression);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Ungültige Cron-Expression: ' . $e->getMessage());
                return $this->redirectToRoute('app_agent_goals');
            }
        }

        // Erstelle das Ziel
        $goal = new AgentGoal();
        $goal->setUserIdentifier($userIdentifier);
        $goal->setTitle($title);
        $goal->setDescription($description);
        $goal->setCronExpression($cronExpression ?: null);
        $goal->setCapabilityConstraints($capabilityConstraints);
        $goal->setStatus('paused'); // Standardmäßig pausiert, muss aktiviert werden
        $goal->setRequiresApproval(true);
        $goal->setIsApproved(false);

        if ($userProfile) {
            $goal->setUserProfile($userProfile);
        }

        $this->agentGoalRepo->save($goal, true);

        // Audit-Log
        $this->auditLogger->log('agent_goal_create', $user, $goal->getId(), 'AgentGoal', [
            'title' => $title,
            'cron_expression' => $cronExpression,
        ], 'success', 'Agent-Ziel erstellt');

        $this->addFlash('success', 'Ziel wurde erstellt. Es muss noch aktiviert und genehmigt werden.');

        return $this->redirectToRoute('app_agent_goals');
    }

    #[Route('/agent/goals/{id}/activate', name: 'app_agent_goals_activate', methods: ['POST'])]
    public function activate(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $goal = $this->agentGoalRepo->find($id);

        if (null === $goal) {
            $this->addFlash('error', 'Ziel nicht gefunden.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        // Prüfe ob Genehmigung erforderlich ist
        if ($goal->isRequiresApproval() && !$goal->isApproved()) {
            $this->addFlash('warning', 'Das Ziel muss zuerst genehmigt werden, bevor es aktiviert werden kann.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Aktiviere das Ziel
        $this->agentGoalRepo->activate($goal);

        // Audit-Log
        $this->auditLogger->log('agent_goal_activate', $user, $goal->getId(), 'AgentGoal', [
            'title' => $goal->getTitle(),
        ], 'success', 'Agent-Ziel aktiviert');

        $this->addFlash('success', 'Ziel wurde aktiviert.');

        return $this->redirectToRoute('app_agent_goals');
    }

    #[Route('/agent/goals/{id}/pause', name: 'app_agent_goals_pause', methods: ['POST'])]
    public function pause(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $goal = $this->agentGoalRepo->find($id);

        if (null === $goal) {
            $this->addFlash('error', 'Ziel nicht gefunden.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        // Pause das Ziel
        $this->agentGoalRepo->pause($goal);

        // Audit-Log
        $this->auditLogger->log('agent_goal_pause', $user, $goal->getId(), 'AgentGoal', [
            'title' => $goal->getTitle(),
        ], 'success', 'Agent-Ziel pausiert');

        $this->addFlash('success', 'Ziel wurde pausiert.');

        return $this->redirectToRoute('app_agent_goals');
    }

    #[Route('/agent/goals/{id}/approve', name: 'app_agent_goals_approve', methods: ['POST'])]
    public function approve(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $goal = $this->agentGoalRepo->find($id);

        if (null === $goal) {
            $this->addFlash('error', 'Ziel nicht gefunden.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        // Genehmige das Ziel
        $this->agentGoalRepo->approve($goal);

        // Audit-Log
        $this->auditLogger->log('agent_goal_approve', $user, $goal->getId(), 'AgentGoal', [
            'title' => $goal->getTitle(),
        ], 'success', 'Agent-Ziel genehmigt');

        $this->addFlash('success', 'Ziel wurde genehmigt.');

        return $this->redirectToRoute('app_agent_goals');
    }

    #[Route('/agent/goals/{id}', name: 'app_agent_goals_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $goal = $this->agentGoalRepo->find($id);

        if (null === $goal) {
            $this->addFlash('error', 'Ziel nicht gefunden.');
            return $this->redirectToRoute('app_agent_goals');
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        // Lösche das Ziel
        $this->agentGoalRepo->remove($goal, true);

        // Audit-Log
        $this->auditLogger->log('agent_goal_delete', $user, $id, 'AgentGoal', [
            'title' => $goal->getTitle(),
        ], 'success', 'Agent-Ziel gelöscht');

        $this->addFlash('success', 'Ziel wurde gelöscht.');

        return $this->redirectToRoute('app_agent_goals');
    }

    #[Route('/api/agent/goals/{id}/status', name: 'app_api_agent_goals_status', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userIdentifier = $user->getUserIdentifier();
        $goal = $this->agentGoalRepo->find($id);

        if (null === $goal) {
            return new JsonResponse(['error' => 'Goal not found'], Response::HTTP_NOT_FOUND);
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $goal->getId(),
            'title' => $goal->getTitle(),
            'status' => $goal->getStatus(),
            'is_approved' => $goal->isApproved(),
            'last_run_at' => $goal->getLastRunAt()?->format('Y-m-d H:i:s'),
            'next_run_at' => $goal->getNextRunAt()?->format('Y-m-d H:i:s'),
            'execution_count' => $goal->getExecutionCount(),
        ]);
    }
}
