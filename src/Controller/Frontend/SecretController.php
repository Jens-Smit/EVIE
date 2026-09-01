<?php

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\SecretService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller für die Verwaltung von Secrets pro Tenant.
 * 
 * Secrets werden verschlüsselt gespeichert und sind nur für den jeweiligen Tenant zugänglich.
 */
class SecretController extends AbstractController
{
    public function __construct(
        private readonly SecretService $secretService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/settings/secrets', name: 'app_settings_secrets', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $secretKeys = $this->secretService->getKeysForUser($userIdentifier);

        return $this->render('settings/secrets.html.twig', [
            'secretKeys' => $secretKeys,
        ]);
    }

    #[Route('/settings/secrets', name: 'app_settings_secrets_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();
        $keyName = trim((string) $request->request->get('keyName'));
        $value = trim((string) $request->request->get('value'));
        $scope = trim((string) $request->request->get('scope', ''));

        // Validierung
        if (empty($keyName)) {
            $this->addFlash('error', 'Der Schlüsselname darf nicht leer sein.');
            return $this->redirectToRoute('app_settings_secrets');
        }

        if (empty($value)) {
            $this->addFlash('error', 'Der Wert darf nicht leer sein.');
            return $this->redirectToRoute('app_settings_secrets');
        }

        // Prüfe ob der Schlüsselname gültig ist (nur alphanumerisch, Unterstriche, Bindestriche)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $keyName)) {
            $this->addFlash('error', 'Der Schlüsselname darf nur Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.');
            return $this->redirectToRoute('app_settings_secrets');
        }

        // Secret speichern
        try {
            $this->secretService->set($keyName, $value, $userIdentifier, $scope ?: null);
            
            // Audit-Log
            $this->auditLogger->log('secret_create', null, null, 'Secret', [
                'key_name' => $keyName,
                'scope' => $scope,
            ], 'success', 'Secret gespeichert');

            $this->addFlash('success', 'Secret wurde erfolgreich gespeichert.');
        } catch (\Exception $e) {
            $this->auditLogger->log('secret_create', null, null, 'Secret', [
                'key_name' => $keyName,
                'error' => $e->getMessage(),
            ], 'failed', 'Secret speichern fehlgeschlagen');

            $this->addFlash('error', 'Fehler beim Speichern des Secrets: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_settings_secrets');
    }

    #[Route('/settings/secrets/{keyName}', name: 'app_settings_secrets_delete', methods: ['POST'])]
    public function delete(string $keyName): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $userIdentifier = $user->getUserIdentifier();

        // Secret löschen
        try {
            $this->secretService->delete($keyName, $userIdentifier);
            
            // Audit-Log
            $this->auditLogger->log('secret_delete', null, null, 'Secret', [
                'key_name' => $keyName,
            ], 'success', 'Secret gelöscht');

            $this->addFlash('success', 'Secret wurde erfolgreich gelöscht.');
        } catch (\Exception $e) {
            $this->auditLogger->log('secret_delete', null, null, 'Secret', [
                'key_name' => $keyName,
                'error' => $e->getMessage(),
            ], 'failed', 'Secret löschen fehlgeschlagen');

            $this->addFlash('error', 'Fehler beim Löschen des Secrets.');
        }

        return $this->redirectToRoute('app_settings_secrets');
    }

    #[Route('/api/secrets/check', name: 'app_api_secrets_check', methods: ['POST'])]
    public function checkSecret(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['exists' => false], Response::HTTP_UNAUTHORIZED);
        }

        $userIdentifier = $user->getUserIdentifier();
        $keyName = trim((string) $request->request->get('keyName'));

        if (empty($keyName)) {
            return new JsonResponse(['exists' => false], Response::HTTP_BAD_REQUEST);
        }

        $exists = $this->secretService->exists($keyName, $userIdentifier);

        return new JsonResponse(['exists' => $exists]);
    }
}
