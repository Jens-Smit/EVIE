<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
}
