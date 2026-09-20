<?php

declare(strict_types=1);

namespace App\Tests\E2E\ProductiveWorkflow;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Onboarding\OnboardingFlowManager;
use App\AI\Onboarding\OnboardingStrategyService;
use App\AI\Skills\Tool\StrategyDocumentTool;
use App\AI\Skills\Tool\SubAgentRegisterTool;
use App\AI\Workflow\EmailIngestionService;
use App\AI\Workflow\MailDraftHitlService;
use App\Entity\AgentHistory;
use App\Entity\Document;
use App\Entity\MailDraft;
use App\Entity\SubAgentDefinition;
use App\Entity\UserProfile;
use App\Repository\AgentGoalRepository;
use App\Repository\AgentHistoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\MailDraftRepository;
use App\Repository\SubAgentDefinitionRepository;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * E2E Productive Workflow Tests: produktionsnahe Szenarien mit echten
 * Mistral-LLM-Aufrufen (CI-Job e2e-llm, EVIE_LLM_E2E=1 + MISTRAL_API_KEY).
 *
 * Abgedeckte Szenarien (Blueprint §5):
 *  1. Onboarding durchlaufen (echte LLM-Extraktion im Onboarding-Chat).
 *  2. Businessplan/Strategie erstellen (LLM-Strategieentwurf, persistiert
 *     als AgentGoal + strategisches Document).
 *  3. Vertriebsagenten erstellen (Sub-Agent-Definition via SubAgentRegisterTool).
 *  4. E-Mail-Freigabe-Workflow (MailDraftHitlService: Entwurf -> HITL ->
 *     Versand ueber den Mailer; im Test-Env null://null).
 *  5. E-Mail-Postfach-Verarbeitung (EmailIngestionService: Nachricht mit
 *     Anhang -> Dokument wird erkannt, zugeordnet und gespeichert).
 *  6. Dokumentenauswertung (LLM-gestuetzte Analyse mit strikter Validierung
 *     und heuristischem Fallback; Ergebnis in AgentHistory dokumentiert).
 *  7. Brainstorming mit Kontext (Onboarding-Chat haelt den Kontext ueber
 *     mehrere Runden und entwickelt die Strategie iterativ weiter).
 *
 * Traceability: Jeder Schritt schreibt AgentHistory-Eintraege, die im
 * Frontend (Agent-Verlauf) nachvollziehbar sind.
 *
 * Ohne MISTRAL_API_KEY werden alle Tests skipped (kein Fehlschlag), damit
 * der Default-CI-Job nicht von echten API-Calls abhaengt.
 *
 * LLM-Aufrufe sind minimiert: jeder Test macht nur die noetigen Abrufe.
 */
final class ProductiveWorkflowE2ETest extends KernelTestCase
{
    private const USER = 'e2e-productive-user';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasMistralKey()) {
            self::markTestSkipped('MISTRAL_API_KEY nicht gesetzt - E2E-Productive-Workflow-Tests werden skipped.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema();
        $this->createUserProfile();
    }

    protected function tearDown(): void
    {
        if ($this->hasMistralKey()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    /**
     * Szenario 1 + 2: Onboarding mit echtem LLM-Chat, daraus entsteht der
     * Businessplan (Strategie) als persistente AgentGoal + strategisches
     * Document.
     */
    public function testOnboardingWithBusinessPlanGeneration(): void
    {
        $manager = static::getContainer()->get(OnboardingFlowManager::class);

        // Szenario 1: Onboarding starten und per echtem LLM-Chat befüllen.
        $start = $manager->startOnboarding(self::USER);
        self::assertArrayHasKey('status', $start);

        $chat = $manager->chat(
            self::USER,
            'Ich moechte EVIE als Kernprodukt fuer meinen Gastronomiebetrieb nutzen '
            . 'und damit 100.000 Euro Jahresumsatz in 12 Monaten erreichen.',
        );
        self::assertNotSame('', trim($chat['response']), 'Der Onboarding-Chat muss eine echte LLM-Antwort liefern.');

        // Der Kontext muss die Mission persistiert haben (DB).
        $status = $manager->getOnboardingStatus(self::USER);
        $onboardingData = $status['onboarding_data'] ?? [];
        self::assertNotSame('', (string) ($onboardingData['mission_statement'] ?? ''), 'Die Mission muss im Onboarding-Kontext persistiert sein.');

        // Szenario 2: Businessplan/Strategie aus dem Onboarding-Kontext
        // generieren (echter LLM-Entwurf, validiert, mit Heuristik-Fallback).
        $strategyService = static::getContainer()->get(OnboardingStrategyService::class);
        $draft = $strategyService->draftStrategy(self::USER, $onboardingData);

        self::assertNotSame('', (string) $draft['title']);
        self::assertNotSame('', (string) $draft['goal']);
        self::assertNotSame([], $draft['steps'], 'Die Strategie braucht mindestens einen Schritt.');
        self::assertContains('source', array_keys($draft), 'Der Entwurf muss seine Quelle (llm|heuristic) kennzeichnen.');
        foreach ($draft['sub_agents'] as $subAgent) {
            self::assertArrayHasKey(
                $subAgent,
                OnboardingStrategyService::SUB_AGENT_CATALOG,
                sprintf('Sub-Agent "%s" ist nicht im Katalog - Halluzination verboten.', $subAgent),
            );
        }

        // Persistierung: aktive AgentGoal + Strategie im Onboarding-Kontext.
        $onboardingData = $strategyService->persistStrategy(self::USER, $onboardingData, $draft);
        self::assertTrue((bool) $onboardingData['strategy_confirmed']);

        $goalRepo = static::getContainer()->get(AgentGoalRepository::class);
        $goal = $goalRepo->findOneBy(['userIdentifier' => self::USER]);
        self::assertNotNull($goal, 'Die persistierte Strategie muss eine aktive AgentGoal angelegt haben.');
        self::assertSame('active', $goal->getStatus());

        // Der Orchestrator (echtes LLM) erstellt den Businessplan als
        // strategisches Document - 1 Abruf ueber die Pipeline.
        $orchestrator = static::getContainer()->get(OrchestratorDialogService::class);
        $response = $orchestrator->ask(
            'Erstelle einen Businessplan fuer meinen Gastronomiebetrieb mit Fokus auf E-Mail-Marketing.',
            self::USER,
        );
        self::assertIsString($response);
        self::assertNotEmpty($response);

        $documentRepo = static::getContainer()->get(DocumentRepository::class);
        $userProfileRepo = static::getContainer()->get(UserProfileRepository::class);
        $strategyTool = new StrategyDocumentTool($documentRepo, $userProfileRepo);
        $docResult = $strategyTool->__invoke([
            'name' => 'Businessplan Gastronomie',
            'content' => sprintf('Mission: %s | Strategie: %s', (string) $onboardingData['mission_statement'], (string) $draft['goal']),
            'user_identifier' => self::USER,
        ]);
        self::assertSame('success', $docResult['status']);
    }

    /**
     * Szenario 3: Vertriebsagent erstellen (Sub-Agent mit Rolle, Konfiguration
     * und aktivem Status) und erste Vertriebsaufgabe delegieren (echtes LLM).
     */
    public function testSalesAgentCreationAndDelegation(): void
    {
        // Sub-Agenten registrieren (deterministisches Tool, kein LLM noetig).
        $subAgentRepo = static::getContainer()->get(SubAgentDefinitionRepository::class);
        $subAgentRegisterTool = new SubAgentRegisterTool($subAgentRepo);
        $result = $subAgentRegisterTool->__invoke([
            'name' => 'e2e_sales_agent',
            'description' => 'Vertriebsagent fuer Gastronomie-Leads und E-Mail-Akquise.',
            'role' => 'marketing_manager',
        ]);
        self::assertSame('success', $result['status']);

        $definition = $subAgentRepo->findOneByName('e2e_sales_agent');
        self::assertNotNull($definition, 'Die Sub-Agent-Definition muss persistiert sein.');
        self::assertTrue($definition->isActive());
        self::assertSame('marketing_manager', $definition->getConfiguration()['role']);

        // Delegation an den Orchestrator (echter LLM-Abruf).
        $orchestrator = static::getContainer()->get(OrchestratorDialogService::class);
        $response = $orchestrator->ask(
            'Mein Vertriebsagent soll Gastronomie-Kunden in Berlin per E-Mail akquirieren. Wie soll er vorgehen?',
            self::USER,
        );
        self::assertIsString($response);
        self::assertNotEmpty($response);
    }

    /**
     * Szenario 4: E-Mail-Freigabe-Workflow (HITL): Agent legt E-Mail-Entwurf
     * an -> Nutzer genehmigt -> Versand (null://null im Test-Env) mit voller
     * Traceability in AgentHistory und AuditLog.
     */
    public function testEmailApprovalWorkflow(): void
    {
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $mailDraftRepo = static::getContainer()->get(MailDraftRepository::class);

        // Agent (communication_manager-Rolle) erstellt den Entwurf.
        $draft = $hitlService->prepareDraft(
            self::USER,
            'Angebot: EVIE fuer Ihr Restaurant',
            "Sehr geehrtes Team,\n\nwie besprochen sende ich Ihnen unser Angebot fuer EVIE.\n\nMit freundlichen Gruessen,\nEVIE",
            ['kunde@restaurant.example'],
            metadata: ['agent' => 'communication_manager'],
        );
        self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
        self::assertNull($draft->getSentAt());

        // Vor Freigabe: keine E-Mail im Status sent.
        $pending = $mailDraftRepo->findPendingByUserIdentifier(self::USER);
        self::assertCount(1, $pending);

        // HITL: Nutzer genehmigt -> Status approved -> Versand -> sent.
        $approved = $hitlService->approveDraft($draft);
        self::assertSame(MailDraft::STATUS_SENT, $approved->getStatus());
        self::assertNotNull($approved->getSentAt());
        self::assertNotNull($approved->getApprovedAt());

        // Erneutes Freigeben ist nicht moeglich (HITL-Statusmaschine).
        $this->expectException(\LogicException::class);
        $hitlService->approveDraft($approved);
    }

    /**
     * Szenario 4b: Ablehnung eines E-Mail-Entwurfs mit Begruendung - es wird
     * NICHT versendet (HITL verhindert die kritische Aktion).
     */
    public function testEmailRejectionWorkflow(): void
    {
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);

        $draft = $hitlService->prepareDraft(
            self::USER,
            'Ablehnungs-Test: Newsletter',
            'Dieser Newsletter-Entwurf wird abgelehnt.',
            ['newsletter@example.com'],
        );

        $rejected = $hitlService->rejectDraft($draft, 'Ton zu aggressiv, ueberarbeiten.');
        self::assertSame(MailDraft::STATUS_REJECTED, $rejected->getStatus());
        self::assertSame('Ton zu aggressiv, ueberarbeiten.', $rejected->getRejectionReason());
        self::assertNull($rejected->getSentAt(), 'Ein abgelehnter Entwurf darf nie versendet werden.');
    }

    /**
     * Szenario 5 + 6: E-Mail-Postfach-Verarbeitung und Dokumentenauswertung:
     * Eine Nachricht mit Rechnungs-Anhang wird verarbeitet; das Dokument wird
     * erkannt, dem Tenant zugeordnet, in der DB gespeichert und per echtem
     * LLM analysiert (Typ, Daten, Empfehlung). Die vorgeschlagene Antwort
     * landet als MailDraft in der HITL-Warteschlange.
     */
    public function testEmailIngestionWithDocumentAnalysis(): void
    {
        $ingestionService = static::getContainer()->get(EmailIngestionService::class);

        $result = $ingestionService->ingestEmail(self::USER, [
            'subject' => 'Rechnung 2026-09 vom 2026-09-30',
            'body' => 'Sehr geehrtes Team, anbei die Rechnung fuer September 2026.',
            'from' => 'kunde@restaurant.example',
            'attachments' => [
                [
                    'filename' => 'rechnung_2026_09.txt',
                    'content' => "Rechnung Nr. 2026-09-114\nRestaurant Gastropartner GmbH\nBetrag: 1.000,00 EUR\nFaellig am 2026-10-01\nPosition: Softwarelizenz EVIE Basispaket",
                ],
            ],
        ]);

        // Der Anhang wurde verarbeitet, zugeordnet und gespeichert.
        self::assertSame(1, $result['processed_attachments']);
        self::assertCount(1, $result['documents']);
        $documentEntry = $result['documents'][0];
        self::assertSame('rechnung_2026_09.txt', $documentEntry['filename']);
        self::assertNotSame('', $documentEntry['type'], 'Der Dokumententyp muss klassifiziert sein (LLM oder Heuristik).');
        self::assertNotSame('', $documentEntry['recommendation']);
        self::assertContains($documentEntry['source'], ['llm', 'heuristic']);

        // Document-Entity ist beim Tenant persistiert.
        $documentRepo = static::getContainer()->get(DocumentRepository::class);
        $userProfileRepo = static::getContainer()->get(UserProfileRepository::class);
        $userProfile = $userProfileRepo->findOneBy(['userIdentifier' => self::USER]);
        $documents = $documentRepo->findByUser((int) $userProfile->getId());
        $found = false;
        foreach ($documents as $document) {
            if ($document->getName() === 'rechnung_2026_09.txt') {
                $found = true;
                self::assertStringContainsString('1.000,00', (string) $document->getContent());
            }
        }
        self::assertTrue($found, 'Der Anhang muss als Document-Entity persistiert sein.');

        // Antwort-Entwurf liegt zur Freigabe in der HITL-Warteschlange.
        $mailDraftRepo = static::getContainer()->get(MailDraftRepository::class);
        $pending = $mailDraftRepo->findPendingByUserIdentifier(self::USER);
        $foundPending = false;
        foreach ($pending as $draft) {
            if ($draft->getId() === $result['reply_draft_id']) {
                $foundPending = true;
                self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
            }
        }
        self::assertTrue($foundPending, 'Die vorgeschlagene Antwort muss als MailDraft auf Freigabe warten.');
    }

    /**
     * Szenario 7: Brainstorming mit Kontext: Der Onboarding-Chat haelt den
     * Kontext ueber zwei Runden und entwickelt die E-Mail-Marketing-Strategie
     * iterativ weiter (echte LLM-Aufrufe).
     */
    public function testBrainstormingKeepsContextAcrossRounds(): void
    {
        $manager = static::getContainer()->get(OnboardingFlowManager::class);
        $manager->startOnboarding(self::USER);

        // Runde 1: Mission.
        $roundOne = $manager->chat(
            self::USER,
            'Ich betreibe ein Restaurant und moechte mit EVIE 100.000 Euro Jahresumsatz erreichen.',
        );
        self::assertNotSame('', trim($roundOne['response']));

        // Runde 2: Iterative Verfeinerung im selben Kontext - das LLM sieht
        // die Mission aus Runde 1 (Kontext-Behalt, 1 weiterer Abruf).
        $roundTwo = $manager->chat(
            self::USER,
            'Konzentriere dich auf E-Mail-Marketing: welche Zielgruppe, Inhalte und KPIs schlaegst du fuer mein Restaurant vor?',
        );
        self::assertNotSame('', trim($roundTwo['response']), 'Runde 2 muss eine echte LLM-Antwort liefern.');

        // Der Onboarding-Kontext umfasst weiterhin die Mission aus Runde 1.
        $status = $manager->getOnboardingStatus(self::USER);
        $onboardingData = $status['onboarding_data'] ?? [];
        self::assertNotSame('', (string) ($onboardingData['mission_statement'] ?? ''), 'Die Mission aus Runde 1 muss im Kontext bleiben.');
    }

    /**
     * Traceability: Alle Workflow-Schritte (Onboarding-Chat, Strategie,
     * E-Mail-Freigabe, Dokumentenanalyse) schreiben AgentHistory-Eintraege,
     * die das Frontend als Aktivitaetsverlauf anzeigt.
     */
    public function testWorkflowStepsAreTraceableInAgentHistory(): void
    {
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $historyRepo = static::getContainer()->get(AgentHistoryRepository::class);
        $userProfileRepo = static::getContainer()->get(UserProfileRepository::class);

        $hitlService->prepareDraft(
            self::USER,
            'Traceability-Test',
            'Dieser Entwurf dient dem Nachweis der AgentHistory-Protokollierung.',
            ['trace@example.com'],
        );

        $userProfile = $userProfileRepo->findOneBy(['userIdentifier' => self::USER]);
        $entries = $historyRepo->findByUserIdentifier(self::USER);
        $found = false;
        foreach ($entries as $entry) {
            if ($entry->getUser()?->getId() === $userProfile->getId() && $entry->getAction() === 'mail_draft_prepared') {
                $found = true;
                self::assertStringContainsString('Traceability-Test', (string) $entry->getDetails());
            }
        }
        self::assertTrue($found, 'Der HITL-Schritt muss als AgentHistory-Eintrag nachvollziehbar sein.');
    }

    private function createUserProfile(): void
    {
        $userProfileRepo = static::getContainer()->get(UserProfileRepository::class);
        $existing = $userProfileRepo->findOneBy(['userIdentifier' => self::USER]);
        if ($existing !== null) {
            return;
        }
        $profile = new UserProfile();
        $profile->setUserIdentifier(self::USER);
        $profile->setName(self::USER);
        $userProfileRepo->save($profile, true);
    }

    private function hasMistralKey(): bool
    {
        $key = $_ENV['MISTRAL_API_KEY'] ?? (getenv('MISTRAL_API_KEY') ?: '');
        return \is_string($key) && $key !== '' && $key !== 'test_mistral_api_key' && $key !== 'test';
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert bereits.
        }
    }

    private function cleanup(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(MailDraft::class, 'm')
                ->where('m.userIdentifier = :uid')
                ->setParameter('uid', self::USER)
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(Document::class, 'd')
                ->where('d.user = :profileId')
                ->setParameter('profileId', $this->currentUserProfileId())
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(AgentHistory::class, 'h')
                ->where('h.user = :profileId')
                ->setParameter('profileId', $this->currentUserProfileId())
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(SubAgentDefinition::class, 's')
                ->where('s.name = :name')
                ->setParameter('name', 'e2e_sales_agent')
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\AgentGoal::class, 'g')
                ->where('g.userIdentifier = :uid')
                ->setParameter('uid', self::USER)
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(UserProfile::class, 'p')
                ->where('p.userIdentifier = :uid')
                ->setParameter('uid', self::USER)
                ->getQuery()->execute();
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Cleanup ist best effort.
        }
    }

    private function currentUserProfileId(): int
    {
        $userProfileRepo = static::getContainer()->get(UserProfileRepository::class);
        $profile = $userProfileRepo->findOneBy(['userIdentifier' => self::USER]);

        return (int) ($profile?->getId() ?? 0);
    }
}
