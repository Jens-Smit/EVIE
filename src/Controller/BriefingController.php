<?php
// src/Controller/BriefingController.php

namespace App\Controller;

use App\AI\Briefing\BriefingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Controller für Briefings und Dashboards.
 * Bietet API-Endpunkte und Web-Interfaces für tägliche und wöchentliche Briefings.
 */
class BriefingController extends AbstractController
{
    public function __construct(
        private BriefingManager $briefingManager
    ) {
    }

    /**
     * Gibt das tägliche Briefing als JSON zurück
     */
    #[Route('/api/briefing/daily', name: 'api_briefing_daily', methods: ['GET'])]
    public function dailyBriefing(#[CurrentUser] ?UserInterface $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $briefing = $this->briefingManager->createDailyBriefing($userIdentifier);

        return $this->json($briefing);
    }

    /**
     * Gibt das wöchentliche Strategie-Briefing als JSON zurück
     */
    #[Route('/api/briefing/weekly', name: 'api_briefing_weekly', methods: ['GET'])]
    public function weeklyBriefing(#[CurrentUser] ?UserInterface $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $briefing = $this->briefingManager->createWeeklyStrategyBriefing($userIdentifier);

        return $this->json($briefing);
    }

    /**
     * Zeigt das Unternehmens-Dashboard an
     */
    #[Route('/briefing', name: 'app_briefing', methods: ['GET'])]
    public function briefingDashboard(#[CurrentUser] ?UserInterface $user = null): Response
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $dailyBriefing = $this->briefingManager->createDailyBriefing($userIdentifier);
        $weeklyBriefing = $this->briefingManager->createWeeklyStrategyBriefing($userIdentifier);

        return $this->render('briefing/dashboard.html.twig', [
            'dailyBriefing' => $dailyBriefing,
            'weeklyBriefing' => $weeklyBriefing,
            'userIdentifier' => $userIdentifier,
        ]);
    }

    /**
     * Gibt eine bestimmte Sektion des Briefings zurück
     */
    #[Route('/api/briefing/section/{section}', name: 'api_briefing_section', methods: ['GET'])]
    public function briefingSection(
        string $section,
        #[CurrentUser] ?UserInterface $user = null
    ): JsonResponse {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $briefing = $this->briefingManager->createDailyBriefing($userIdentifier);

        if (!isset($briefing['sections'][$section])) {
            return $this->json(['error' => 'Section not found'], 404);
        }

        return $this->json($briefing['sections'][$section]);
    }

    /**
     * Gibt die Statistiken als JSON zurück
     */
    #[Route('/api/briefing/statistics', name: 'api_briefing_statistics', methods: ['GET'])]
    public function briefingStatistics(#[CurrentUser] ?UserInterface $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $briefing = $this->briefingManager->createDailyBriefing($userIdentifier);

        return $this->json($briefing['sections']['tool_statistics']);
    }
}
