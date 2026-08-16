<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\DataPrivacyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DSGVO-Datenschutz-Endpunkte (Art. 15 Auskunft, Art. 17 Loeschung).
 *
 * Ermöglicht einem authentifizierten Nutzer, seine personenbezogenen
 * Daten zu exportieren oder zu loeschen (Recht auf Vergessenwerden).
 */
#[Route('/api/privacy', name: 'api_privacy_')]
#[IsGranted('ROLE_USER')]
final class DataPrivacyController extends AbstractController
{
    public function __construct(
        private readonly DataPrivacyService $dataPrivacyService,
    ) {
    }

    /**
     * Art. 15: Export aller personenbezogenen Daten des authenticated Users.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $data = $this->dataPrivacyService->exportUserData($user);

        return $this->json([
            'status' => 'success',
            'user_id' => $user->getId(),
            'exported_at' => (new \DateTimeImmutable())->format('c'),
            'data' => $data,
        ]);
    }

    /**
     * Art. 17: Loeschung aller personenbezogenen Daten des authenticated Users.
     */
    #[Route('/delete', name: 'delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $deletedCount = $this->dataPrivacyService->deleteUserData($user);

        return $this->json([
            'status' => 'success',
            'user_id' => $user->getId(),
            'deleted_at' => (new \DateTimeImmutable())->format('c'),
            'deleted_records' => $deletedCount,
            'message' => 'Personenbezogene Daten geloescht, Account deaktiviert.',
        ]);
    }

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        return $user;
    }
}
