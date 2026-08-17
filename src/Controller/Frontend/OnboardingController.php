<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\AI\Onboarding\OnboardingFlowManager;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * OnboardingController – triggert den Onboarding-Flow für neue Nutzer.
 *
 * Frontend-Audit F4: Das Backend (OnboardingFlowManager) war vollständig
 * implementiert, aber es fehlten Controller/Route/UI, um den Flow auszulösen.
 *
 * Dieser Controller stellt drei Endpunkte bereit:
 *  - GET  /onboarding         – rendert die Onboarding-Seite / Popup-Trigger
 *  - POST /onboarding/start   – startet den Flow (OnboardingFlowManager::startOnboarding)
 *  - POST /onboarding/next     – verarbeitet die Nutzerantwort (processResponse)
 *
 * Der Flow wird automatisch nach Login getriggert, wenn user.onboardingComplete
 * false ist (siehe LoginFormAuthenticator::onAuthenticationSuccess).
 */
class OnboardingController extends AbstractController
{
    public function __construct(
        private readonly OnboardingFlowManager $onboardingFlowManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/onboarding', name: 'app_onboarding', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        // Wenn Onboarding bereits abgeschlossen, zum Dashboard weiterleiten.
        if ($user->isOnboardingComplete()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('onboarding/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/onboarding/start', name: 'app_onboarding_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Nicht authentifiziert'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $userIdentifier = $user->getUserIdentifier();
            $initialContext = [];
            if ($request->getContentTypeFormat() === 'json') {
                $data = $request->toArray();
                $initialContext = $data['initial_context'] ?? [];
            }

            $step = $this->onboardingFlowManager->startOnboarding($userIdentifier, $initialContext);

            return $this->json($step);
        } catch (\Throwable $e) {
            // Graceful Fallback: wenn der Onboarding-Agent (Mistral) nicht
            // erreichbar ist, liefere den ersten Legacy-Schritt, damit der
            // Nutzer den Flow zumindest durchlaufen kann.
            return $this->json([
                'status' => 'in_progress',
                'step_id' => 'welcome',
                'current_step' => 0,
                'question' => 'Willkommen beim EVIE AI-Agent! Wie möchtest du den Agenten nutzen?',
                'type' => 'multiple_choice',
                'options' => ['Business (CRM, Termine)', 'Privat (Recherche, Notizen)'],
                'validation' => [],
                'fallback' => true,
                'error' => 'Onboarding-Agent nicht erreichbar, nutze Fallback-Schritt.',
            ]);
        }
    }

    #[Route('/onboarding/next', name: 'app_onboarding_next', methods: ['POST'])]
    public function next(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Nicht authentifiziert'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $userIdentifier = $user->getUserIdentifier();
            $response = '';

            if ($request->getContentTypeFormat() === 'json') {
                $data = $request->toArray();
                $response = $data['response'] ?? '';
            } else {
                $response = $request->request->get('response', '');
            }

            $result = $this->onboardingFlowManager->processResponse($userIdentifier, $response);

            // Wenn der Flow abgeschlossen ist, setze den onboardingComplete-Flag.
            if (($result['status'] ?? '') === 'completed') {
                $user->setOnboardingComplete(true);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'error' => 'Onboarding-Schritt konnte nicht verarbeitet werden.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/onboarding/complete', name: 'app_onboarding_complete', methods: ['POST'])]
    public function complete(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Nicht authentifiziert'], Response::HTTP_UNAUTHORIZED);
        }

        $user->setOnboardingComplete(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'completed',
            'redirect' => $this->generateUrl('app_dashboard'),
        ]);
    }

    #[Route('/onboarding/status', name: 'app_onboarding_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Nicht authentifiziert'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'onboarding_complete' => $user->isOnboardingComplete(),
        ]);
    }
}
