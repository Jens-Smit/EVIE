<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * SettingsController – Einstellungsseite für den Nutzer.
 *
 * Frontend-Audit F3: Der "Einstellungen"-Link in der Sidebar war href="#".
 * Diese Seite bündelt: Profil-Bearbeitung, Theme-Präferenz, und Verweis auf
 * DSGVO-Aktionen (export/löschen – implementiert in Phase 4).
 */
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        return $this->render('settings/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/settings/profile', name: 'app_settings_profile', methods: ['POST'])]
    public function updateProfile(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $firstName = (string) $request->request->get('firstName', '');
        $lastName = (string) $request->request->get('lastName', '');

        if ($firstName !== '') {
            $user->setFirstName($firstName);
        }
        if ($lastName !== '') {
            $user->setLastName($lastName);
        }
        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'Profil wurde aktualisiert.');

        return $this->redirectToRoute('app_settings');
    }

    #[Route('/settings/onboarding/reset', name: 'app_settings_onboarding_reset', methods: ['POST'])]
    public function resetOnboarding(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $user->setOnboardingComplete(false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_onboarding');
    }

    /**
     * DSGVO Art. 20 - Recht auf Datenuebertragbarkeit.
     * Exportiert alle personenbezogenen Daten als JSON-Download.
     */
    #[Route('/settings/export-data', name: 'app_settings_export_data', methods: ['GET'])]
    public function exportData(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $profile = $user->getProfile();
        $data = [
            'export_date' => (new \DateTimeImmutable())->format('c'),
            'user' => [
                'email' => $user->getEmail(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'onboarding_complete' => $user->isOnboardingComplete(),
                'created_at' => $user->getCreatedAt()?->format('c'),
                'last_login_at' => $user->getLastLoginAt()?->format('c'),
            ],
            'profile' => $profile ? [
                'user_identifier' => $profile->getUserIdentifier(),
                'user_type' => $profile->getUserType(),
                'onboarding_data' => $profile->getOnboardingData(),
            ] : null,
            'legal_notice' => 'Dieser Export wurde gemäß Art. 20 DSGVO (Recht auf Datenübertragbarkeit) erstellt.',
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="evie-data-export-' . $user->getEmail() . '.json"');

        return $response;
    }

    /**
     * DSGVO Art. 17 - Recht auf Loeschung (Recht auf Vergessenwerden).
     * Loescht den Account und alle verknuepften Daten.
     */
    #[Route('/settings/delete-account', name: 'app_settings_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        // CSRF-Schutz fuer kritische Aktion.
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-account', $submittedToken)) {
            $this->addFlash('error', 'Ungültiges Sicherheitstoken. Aktion abgebrochen.');
            return $this->redirectToRoute('app_settings');
        }

        // Bestaetigungs-Phrase pruefen (zusaetzliche Sicherheit).
        $confirmation = $request->request->get('confirmation', '');
        if ($confirmation !== 'LOESCHEN') {
            $this->addFlash('error', 'Bitte geben Sie zur Bestätigung "LOESCHEN" ein.');
            return $this->redirectToRoute('app_settings');
        }

        // Profile loeschen (cascade), dann User.
        $profile = $user->getProfile();
        if ($profile) {
            $this->entityManager->remove($profile);
        }
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // Session loeschen und ausloggen.
        $this->container->get('security.token_storage')->setToken(null);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Ihr Account wurde gelöscht. Wir bedauern Ihren Weggang.');

        return $this->redirectToRoute('app_home');
    }
}
