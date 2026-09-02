<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Entity\User;
use App\Service\DataPrivacyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SettingsController - Einstellungsseite fuer den Nutzer.
 *
 * Frontend-Audit F3: Der "Einstellungen"-Link in der Sidebar war href="#".
 * Diese Seite buendelt: Profil-Bearbeitung, Theme-Praeferenz, und Verweis auf
 * DSGVO-Aktionen (export/loeschen - implementiert in Phase 4).
 */
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataPrivacyService $dataPrivacyService,
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
     *
     * Nutzt DataPrivacyService::exportUserData() fuer einen vollstaendigen Export
     * (Fix fuer P1-2 aus Audit Zyklus 6: vereinheitlichte DSGVO-Implementierung).
     */
    #[Route('/settings/export-data', name: 'app_settings_export_data', methods: ['GET'])]
    public function exportData(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $data = $this->dataPrivacyService->exportUserData($user);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="evie-data-export-' . $user->getEmail() . '.json"');

        return $response;
    }

    /**
     * DSGVO Art. 17 - Recht auf Loeschung (Recht auf Vergessenwerden).
     * Loescht den Account und alle verknuepften Daten.
     *
     * Nutzt DataPrivacyService::deleteUserData() um alle verknuepften Entitaeten
     * (SubAgent, AgentHistory, Document, DecisionLog, Embedding, AuditLog) korrekt
     * zu loeschen und Fremdschlüssel-Verletzungen zu vermeiden (Fix fuer P0-1 aus Audit Zyklus 6).
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
            $this->addFlash('error', 'Ungueltiges Sicherheitstoken. Aktion abgebrochen.');
            return $this->redirectToRoute('app_settings');
        }

        // Bestaetigungs-Phrase pruefen (zusaetzliche Sicherheit).
        $confirmation = $request->request->get('confirmation', '');
        if ($confirmation !== 'LOESCHEN') {
            $this->addFlash('error', 'Bitte geben Sie zur Bestaetigung "LOESCHEN" ein.');
            return $this->redirectToRoute('app_settings');
        }

        // DataPrivacyService nutzt die korrekte Loeschreihenfolge und loescht
        // alle verknuepften Entitaeten (SubAgent, AgentHistory, Document, DecisionLog,
        // Embedding, AuditLog) vor dem UserProfile, um Fremdschlüssel-Verletzungen
        // zu vermeiden (Fix fuer P0-1 aus Audit Zyklus 6).
        $this->dataPrivacyService->deleteUserData($user);

        // Session loeschen und ausloggen.
        $this->container->get('security.token_storage')->setToken(null);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Ihr Account wurde geloescht. Wir bedauern Ihren Weggang.');

        return $this->redirectToRoute('app_home');
    }


    #[Route('/settings/llm-preferences', name: 'app_settings_llm_preferences', methods: ['POST'])]
    public function updateLlmPreferences(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }

        $provider = (string) $request->request->get('llm_provider');
        $model = (string) $request->request->get('llm_model');

        // Validierung
        $validProviders = ['mistral', 'gemini'];
        if (!in_array($provider, $validProviders)) {
            $this->addFlash('error', 'Ungültiger Anbieter ausgewählt.');
            return $this->redirectToRoute('app_settings');
        }

        // Hole oder erstelle UserProfile
        $profile = $user->getProfile();
        if (!$profile) {
            $profile = new \App\Entity\UserProfile();
            $profile->setUserIdentifier($user->getUserIdentifier());
            $profile->setName($user->getFullName());
            $profile->setEmail($user->getEmail());
            $profile->setUser($user);
            $this->entityManager->persist($profile);
        }

        $profile->setPreferredLlmProvider($provider);
        $profile->setPreferredLlmModel($model);
        $profile->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $this->addFlash('success', 'LLM-Präferenzen wurden aktualisiert.');

        return $this->redirectToRoute('app_settings');
    }
}
