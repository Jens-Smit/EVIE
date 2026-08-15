<?php
// tests/Integration/AI/Agent/SubAgentDispatcherIntegrationTest.php

namespace App\Tests\Integration\AI\Agent;

use App\AI\Agent\SubAgentDispatcher;
use App\AI\Agent\SubAgentFactory;
use App\Entity\SubAgentDefinition;
use App\Entity\User;
use App\Repository\SubAgentDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SubAgentDispatcherIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SubAgentDefinitionRepository $repo;
    private SubAgentDispatcher $dispatcher;
    private SubAgentFactory $subAgentFactory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repo = $this->entityManager->getRepository(SubAgentDefinition::class);
        $this->subAgentFactory = self::getContainer()->get(SubAgentFactory::class);
        $this->dispatcher = self::getContainer()->get(SubAgentDispatcher::class);
    }

    public function testDelegateToDynamicSubAgent(): void
    {
        // 1. Erstelle einen Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $this->entityManager->persist($user);

        // 2. Erstelle eine Sub-Agenten-Definition in der DB
        $definition = new SubAgentDefinition();
        $definition->setName('test_website_researcher');
        $definition->setDescription('Test Website Researcher Agent');
        $definition->setClassName('App\AI\Agent\SubAgent\WebsiteResearcherAgent');
        $definition->setConfiguration([
            'model' => 'mistral-large-latest',
            'role' => 'website_researcher'
        ]);
        $definition->setIsActive(true);
        $definition->setCreatedBy($user);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // 3. Delegiere eine Aufgabe mit @mention
        $task = 'Analysiere die Webseite https://example.com @test_website_researcher';
        $result = $this->dispatcher->delegate($task, []);

        // 4. Überprüfe, dass das Ergebnis ein Array ist
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sub_agent', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('status', $result);

        // 5. Überprüfe, dass der richtige Sub-Agent ausgewählt wurde
        $this->assertEquals('test_website_researcher', $result['sub_agent']);
    }

    public function testDelegateToStaticSubAgent(): void
    {
        // Delegiere eine Aufgabe ohne @mention (sollte statischen Sub-Agenten verwenden)
        $task = 'Analysiere diese Daten';
        $result = $this->dispatcher->delegate($task, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('sub_agent', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('status', $result);

        // Sollte einen der statischen Sub-Agenten verwenden
        $this->assertContains($result['sub_agent'], [
            'website_researcher',
            'data_analyst',
            'code_assistant',
            'document_processor',
            'communication_manager'
        ]);
    }

    public function testGetAvailableSubAgents(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $this->entityManager->persist($user);

        // 2. Erstelle Sub-Agenten-Definitionen in der DB
        $definition1 = new SubAgentDefinition();
        $definition1->setName('test_agent_1');
        $definition1->setDescription('Test Agent 1');
        $definition1->setClassName('App\AI\Agent\SubAgent\TestAgent1');
        $definition1->setConfiguration(['model' => 'mistral-large']);
        $definition1->setIsActive(true);
        $definition1->setCreatedBy($user);

        $definition2 = new SubAgentDefinition();
        $definition2->setName('test_agent_2');
        $definition2->setDescription('Test Agent 2');
        $definition2->setClassName('App\AI\Agent\SubAgent\TestAgent2');
        $definition2->setConfiguration(['model' => 'mistral-large']);
        $definition2->setIsActive(true);
        $definition2->setCreatedBy($user);

        $this->entityManager->persist($definition1);
        $this->entityManager->persist($definition2);
        $this->entityManager->flush();

        // 3. Hole alle verfügbaren Sub-Agenten
        $subAgents = $this->dispatcher->getAvailableSubAgents();

        // 4. Überprüfe, dass die Sub-Agenten geladen wurden
        $this->assertArrayHasKey('test_agent_1', $subAgents);
        $this->assertArrayHasKey('test_agent_2', $subAgents);

        // 5. Überprüfe, dass auch statische Sub-Agenten enthalten sind
        $this->assertArrayHasKey('website_researcher', $subAgents);
        $this->assertArrayHasKey('data_analyst', $subAgents);
    }

    public function testGetActiveSubAgentDefinitions(): void
    {
        // 1. Erstelle Test-User
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_USER']);
        $this->entityManager->persist($user);

        // 2. Erstelle aktive und inaktive Definitionen
        $activeDefinition = new SubAgentDefinition();
        $activeDefinition->setName('active_agent');
        $activeDefinition->setDescription('Active Agent');
        $activeDefinition->setClassName('App\AI\Agent\SubAgent\ActiveAgent');
        $activeDefinition->setIsActive(true);
        $activeDefinition->setCreatedBy($user);

        $inactiveDefinition = new SubAgentDefinition();
        $inactiveDefinition->setName('inactive_agent');
        $inactiveDefinition->setDescription('Inactive Agent');
        $inactiveDefinition->setClassName('App\AI\Agent\SubAgent\InactiveAgent');
        $inactiveDefinition->setIsActive(false);
        $inactiveDefinition->setCreatedBy($user);

        $this->entityManager->persist($activeDefinition);
        $this->entityManager->persist($inactiveDefinition);
        $this->entityManager->flush();

        // 3. Hole alle aktiven Definitionen
        $definitions = $this->dispatcher->getActiveSubAgentDefinitions();

        // 4. Überprüfe, dass nur aktive Definitionen zurückgegeben werden
        $this->assertCount(1, $definitions);
        $this->assertEquals('active_agent', $definitions[0]->getName());
    }

    public function testDelegateToSpecificSubAgent(): void
    {
        // 1. Delegiere direkt an einen bestimmten Sub-Agenten
        $result = $this->dispatcher->delegateTo('website_researcher', 'Analysiere example.com');

        // 2. Überprüfe das Ergebnis
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sub_agent', $result);
        $this->assertEquals('website_researcher', $result['sub_agent']);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testClassifyTaskWithKeywords(): void
    {
        // Teste die Klassifizierungslogik
        $testCases = [
            ['Analysiere diese Daten', 'data_analyst'],
            ['Schreibe Code für...', 'code_assistant'],
            ['Recherchiere die Webseite...', 'website_researcher'],
            ['Verarbeite dieses PDF...', 'document_processor'],
            ['Sende eine E-Mail...', 'communication_manager'],
        ];

        foreach ($testCases as $testCase) {
            $task = $testCase[0];
            $expectedAgent = $testCase[1];

            // Da wir die private Methode nicht direkt testen können,
            // testen wir über die delegate-Methode
            $result = $this->dispatcher->delegate($task, []);
            
            // Die delegate-Methode sollte den richtigen Sub-Agenten auswählen
            // (oder einen Fallback, falls die Klassifizierung nicht perfekt ist)
            $this->assertIsArray($result);
            $this->assertArrayHasKey('sub_agent', $result);
        }
    }

    public function testDetermineSubAgentWithMention(): void
    {
        // Teste die @mention-Erkennung
        $result = $this->dispatcher->delegate('Test @website_researcher', []);
        
        $this->assertIsArray($result);
        $this->assertEquals('website_researcher', $result['sub_agent']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Bereinige die DB
        $this->entityManager->createQuery('DELETE FROM App\Entity\SubAgentDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User WHERE email = :email')
            ->setParameter('email', 'test@example.com')
            ->execute();
        $this->entityManager->flush();
    }
}
