<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\AI\Onboarding\Exception\InvalidApiKeyException;
use App\AI\Onboarding\OnboardingFlowManager;
use App\Entity\User;
use App\Service\ApiKeyValidator;
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
        private readonly ApiKeyValidator $apiKeyValidator,
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
            // Deterministischer Fallback, falls das Container-Setup unvollstaendig
            // ist. Liefert den ersten phasenbasierten Schritt (KI-Anbieter).
            return $this->json([
                'status' => 'in_progress',
                'step_id' => 'llm_provider',
                'phase' => 'KI-Settings',
                'current_step' => 0,
                'total_steps' => 5,
                'question' => 'Welchen KI-Anbieter moechtest du fuer EVIE nutzen? Diese Einstellung wird zuerst benoetigt, damit EVIE funktioniert.',
                'type' => 'multiple_choice',
                'options' => [
                    'mistral' => 'Mistral AI',
                    'gemini' => 'Google Gemini',
                ],
                'help' => 'Der Anbieter bestimmt, welches Sprachmodell EVIE verwendet.',
                'required' => true,
                'validation' => [],
                'fallback' => true,
                'error' => 'Onboarding-Engine nicht erreichbar, nutze Fallback-Schritt: ' . $e->getMessage(),
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

            // Antworten konnen je nach Schritttyp unterschiedlich sein:
            //  - multiple_choice: String (eine Option) oder Array (Mehrfachauswahl)
            //  - text/secret: String
            //  - email_smtp/email_imap: Array mit host/port/user/pass/encryption/from
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
        } catch (InvalidApiKeyException $e) {
            return $this->json([
                'status' => 'error',
                'error' => $e->getMessage(),
                'error_type' => 'invalid_api_key',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'error' => 'Onboarding-Schritt konnte nicht verarbeitet werden.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/onboarding/validate-key', name: 'app_onboarding_validate_key', methods: ['POST'])]
    public function validateKey(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Nicht authentifiziert'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->getContentTypeFormat() === 'json' ? $request->toArray() : $request->request->all();
        $provider = (string) ($data['provider'] ?? '');
        $apiKey = (string) ($data['api_key'] ?? '');

        if ($provider === '' || $apiKey === '') {
            return $this->json(
                ['valid' => false, 'message' => 'Anbieter und API-Key sind erforderlich.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $result = $this->apiKeyValidator->validate($provider, $apiKey);

        return $this->json($result, $result['valid'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
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
