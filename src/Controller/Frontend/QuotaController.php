<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Service\QuotaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Anzeige des Token-Quota-Verbrauchs.
 */
class QuotaController extends AbstractController
{
    public function __construct(
        private QuotaService $quotaService,
    ) {
    }

    #[Route('/settings/quota', name: 'app_settings_quota', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $quotaUsage = $this->quotaService->getQuotaUsage($userIdentifier);

        return $this->render('settings/quota.html.twig', [
            'quotaUsage' => $quotaUsage,
        ]);
    }

    #[Route('/api/quota/usage', name: 'app_api_quota_usage', methods: ['GET'])]
    public function getUsage(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userIdentifier = $user->getUserIdentifier();
        $quotaUsage = $this->quotaService->getQuotaUsage($userIdentifier);

        return new JsonResponse($quotaUsage);
    }

    #[Route('/api/quota/remaining', name: 'app_api_quota_remaining', methods: ['GET'])]
    public function getRemaining(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userIdentifier = $user->getUserIdentifier();

        return new JsonResponse([
            'remaining_tokens' => $this->quotaService->getRemainingTokens($userIdentifier),
            'remaining_requests' => $this->quotaService->getRemainingRequests($userIdentifier),
        ]);
    }
}
