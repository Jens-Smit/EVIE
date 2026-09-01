<?php

namespace App\Controller\Frontend;

use App\AI\Strategy\EvaluationService;
use App\AI\Strategy\StrategyManager;
use App\Entity\User;
use App\Repository\GoalEvaluationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Strategie-Review und Anpassungen.
 */
class StrategyController extends AbstractController
{
    public function __construct(
        private StrategyManager $strategyManager,
        private EvaluationService $evaluationService,
        private GoalEvaluationRepository $evaluationRepo,
    ) {
    }

    #[Route('/strategy', name: 'app_strategy', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();

        // Hole Anpassungsvorschläge
        $analysis = $this->strategyManager->analyzeAndSuggestAdjustments($userIdentifier);

        // Hole Evaluationshistorie
        $evaluations = $this->evaluationRepo->findByUser($userIdentifier);

        return $this->render('strategy/index.html.twig', [
            'suggestions' => $analysis['suggestions'],
            'summary' => $analysis['summary'],
            'evaluations' => $evaluations,
        ]);
    }

    #[Route('/strategy/evaluate', name: 'app_strategy_evaluate', methods: ['POST'])]
    public function evaluate(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();

        // Hole alle Ziele und ihre Historie für die Evaluation
        $analysis = $this->strategyManager->analyzeAndSuggestAdjustments($userIdentifier);

        $this->addFlash('success', 'Strategie-Analyse wurde durchgeführt.');

        return $this->redirectToRoute('app_strategy');
    }

    #[Route('/strategy/suggestion/{id}/apply', name: 'app_strategy_suggestion_apply', methods: ['POST'])]
    public function applySuggestion(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();

        // Hole die Vorschläge und finde den passenden
        $analysis = $this->strategyManager->analyzeAndSuggestAdjustments($userIdentifier);

        $suggestion = null;
        foreach ($analysis['suggestions'] as $s) {
            if ((string) $s['goal_id'] === $id) {
                $suggestion = $s;
                break;
            }
        }

        if (null === $suggestion) {
            $this->addFlash('error', 'Vorschlag nicht gefunden.');
            return $this->redirectToRoute('app_strategy');
        }

        // Wende die Anpassung an
        $success = false;
        
        if ($suggestion['goal_id'] !== null) {
            $success = $this->strategyManager->applyAdjustment(
                (int) $suggestion['goal_id'],
                $suggestion['type'],
                $userIdentifier
            );
        }

        if ($success) {
            $this->addFlash('success', 'Anpassung wurde angewendet.');
        } else {
            $this->addFlash('error', 'Anpassung konnte nicht angewendet werden.');
        }

        return $this->redirectToRoute('app_strategy');
    }

    #[Route('/strategy/evaluation/{id}', name: 'app_strategy_evaluation_detail', methods: ['GET'])]
    public function evaluationDetail(int $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $evaluation = $this->evaluationRepo->find($id);

        if (null === $evaluation) {
            throw $this->createNotFoundException('Evaluation nicht gefunden');
        }

        // Prüfe Tenant-Isolation
        if ($evaluation->getGoal()->getUserIdentifier() !== $user->getUserIdentifier()) {
            throw $this->createAccessDeniedException('Zugriff verweigert.');
        }

        return $this->render('strategy/evaluation_detail.html.twig', [
            'evaluation' => $evaluation,
        ]);
    }
}
