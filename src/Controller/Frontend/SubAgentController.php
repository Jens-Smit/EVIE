<?php

namespace App\Controller\Frontend;

use App\AI\Agent\SubAgentFactoryInterface;
use App\Entity\User;
use App\Repository\SubAgentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SubAgentController extends AbstractController
{
    public function __construct(
        private SubAgentFactoryInterface $subAgentFactory,
    ) {
    }

    #[Route('/subagents', name: 'app_subagents')]
    public function index(SubAgentRepository $subAgentRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentifizierung erforderlich.');
        }
        $subAgents = $subAgentRepository->findByUser($user->getId());

        $staticSubAgents = [];
        foreach (array_keys($this->subAgentFactory->getAvailableSubAgents()) as $name) {
            $staticSubAgents[] = [
                'name' => $name,
                'description' => $this->describeStaticAgent($name),
            ];
        }

        return $this->render('subagents/index.html.twig', [
            'subAgents' => $subAgents,
            'staticSubAgents' => $staticSubAgents,
        ]);
    }

    private function describeStaticAgent(string $name): string
    {
        return [
            'website_researcher' => 'Webseiten-Recherche: Impressum, Kontakte, Geschäftszweck, Standort, Branche.',
            'data_analyst' => 'Datenanalyse: Analysiert Daten und liefert Erkenntnisse.',
            'code_assistant' => 'Code-Assistenz: Analysiert und generiert Code.',
            'document_processor' => 'Dokumentenverarbeitung: Verarbeitet Dokumente.',
            'communication_manager' => 'Kommunikation: Verwaltet E-Mails, Nachrichten, LinkedIn und andere Kanäle.',
            'api_integration' => 'API-Integration: Bindet externe APIs an, verwaltet OAuth und Authentifizierung.',
            'project_manager' => 'Projektmanagement: Verwaltet Aufgaben, Termine, Ressourcen und Projekte.',
            'finance_manager' => 'Finanzen: Verwaltet Buchhaltung, Rechnungen, Zahlungen.',
            'hr_manager' => 'Personal: Verwaltet Mitarbeiter, Gehälter, Verträge.',
            'marketing_manager' => 'Marketing: Verwaltet Kampagnen, Social Media, Content.',
            'ceo_assistant' => 'CEO-Assistenz: Entwickelt Strategien, trifft Entscheidungen, priorisiert Aufgaben.',
        ][$name] ?? 'Sub-Agent für ' . $name . '.';
    }
}
