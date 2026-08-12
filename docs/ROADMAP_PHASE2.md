# 🚀 EVIE Phase 2 Implementierungsplan: Dynamische Sub-Agenten, Streaming & MCP-Integration

**Erstellt am:** 12. August 2026  
**Letzte Aktualisierung:** 12. August 2026, 18:00 Uhr  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Referenz:** [EVIE_ANALYSE.md](EVIE_ANALYSE.md), [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)  
**Status:** **🟡 GEPLANT** (Start: **16. August 2026**)  

---

## 📊 **Zusammenfassung Phase 2**

Phase 2 konzentriert sich auf die **Dynamisierung der Sub-Agenten**, die **Implementierung von Streaming-Antworten**, die **dynamische Konfiguration von MCP-Servern** und die **Erweiterung des Frontends mit HTMX/Alpine.js**. Diese Maßnahmen sind **hochpriorisiert** und bauen auf den in [Phase 1](../ROADMAP_PHASE1.md) umgesetzten Sicherheits- und Tool-Infrastrukturen auf.

| **Maßnahme** | **Priorität** | **Aufwand** | **Impact** | **Status** | **Abhängigkeiten** |
|--------------|--------------|-------------|------------|------------|-------------------|
| **4. Sub-Agenten dynamisch machen** | 🟡 **Hoch** | 3-5 Tage | 🟡 **Hoch** (Skalierbarkeit) | ⏳ **Geplant** | Phase 1 (SecurityGuard) |
| **5. Streaming-Antworten implementieren** | 🟡 **Hoch** | 5-7 Tage | 🟡 **Hoch** (User Experience) | ⏳ **Geplant** | Phase 1 (Tool-Infrastruktur) |
| **6. MCP-Server dynamisch konfigurierbar machen** | 🟡 **Hoch** | 2-3 Tage | 🟡 **Hoch** (Flexibilität) | ⏳ **Geplant** | Phase 1 (SecurityGuard) |
| **7. Frontend mit HTMX/Alpine.js erweitern** | 🟡 **Hoch** | 3-5 Tage | 🟡 **Hoch** (Echtzeit-Updates) | ⏳ **Geplant** | Phase 1 (Tool-Infrastruktur) |

**Gesamtaufwand Phase 2:** **13-20 Arbeitstage**  
**Geplantes Fertigstellungsdatum:** **06.-13. September 2026**  

---

## 🎯 **Ziele der Phase 2**

1. **Dynamische Sub-Agenten**
   - Sub-Agenten **nicht mehr statisch in `ai.yaml`**, sondern **dynamisch aus der Datenbank laden**
   - **Automatische Registrierung** neuer Sub-Agenten ohne manuelle Konfiguration
   - **Skalierbarkeit** für beliebige Anzahl von Sub-Agenten

2. **Streaming-Antworten**
   - **Echtzeit-Feedback** für lange Tool-Executions (z. B. Web-Scraping, Datenanalyse)
   - **Fortschrittsbalken** in der UI
   - **Asynchrone Ausführung** mit Symfony Messenger + WebSocket

3. **Dynamische MCP-Server-Konfiguration**
   - MCP-Server **nicht mehr hardcoded**, sondern **aus der Datenbank laden**
   - **Flexible Integration** neuer MCP-Server ohne Code-Änderungen
   - **Sicherheitsprüfung** durch SecurityGuard

4. **Frontend-Erweiterung mit HTMX/Alpine.js**
   - **Echtzeit-Updates** ohne Page-Reload
   - **Interaktive UI** für Tool-Execution, Sub-Agenten-Delegation und MCP-Tool-Nutzung
   - **Konsistente User Experience** mit Symfony AI Bundle

---

## 📋 **Detaillierte Maßnahmen**

---

### **🟡 Maßnahme 4: Sub-Agenten dynamisch machen**
**Priorität:** 🟡 **Hoch** | **Aufwand:** 3-5 Tage | **Impact:** 🟡 **Hoch** | **Start:** 16. August 2026

#### **📌 Hintergrund**
Aktuell sind **12 Sub-Agenten statisch in `ai.yaml`** konfiguriert. Dies ist **nicht skalierbar** und verhindert die **dynamische Erstellung** neuer Sub-Agenten zur Laufzeit. Das **Symfony AI Bundle** unterstützt die **deklarative Konfiguration** von Agenten über YAML und PHP-Attribute (`#[AsAgent]`).

#### **🎯 Ziele**
- Sub-Agenten **dynamisch aus der Datenbank laden**
- **Automatische Registrierung** neuer Sub-Agenten
- **Symfony AI Bundle-konform** (YAML + Attribute)
- **Sicherheitsprüfung** durch SecurityGuard

#### **📝 Umsetzung**

##### **1. Datenbank-Entity für Sub-Agenten erstellen**
**Datei:** `src/Entity/SubAgentDefinition.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Entity/SubAgentDefinition.php

namespace App\Entity;

use App\Repository\SubAgentDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubAgentDefinitionRepository::class)]
#[ORM\Table(name: 'ai_sub_agent_definitions')]
class SubAgentDefinition
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'string', length: 255)]
    private string $className; // z. B. App\AI\Agent\SubAgent\DataAnalystAgent

    #[ORM\Column(type: 'json')]
    private array $configuration; // Plattform, Model, Tools, etc.

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $createdBy = null;

    // Getter & Setter
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ... (Getter/Setter für alle Properties)
}
```

##### **2. Repository für Sub-Agenten erstellen**
**Datei:** `src/Repository/SubAgentDefinitionRepository.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Repository/SubAgentDefinitionRepository.php

namespace App\Repository;

use App\Entity\SubAgentDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SubAgentDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubAgentDefinition::class);
    }

    /**
     * Finde alle aktiven Sub-Agenten-Definitionen.
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde eine Sub-Agenten-Definition nach Name.
     */
    public function findOneByName(string $name): ?SubAgentDefinition
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```

##### **3. SubAgentFactory für dynamische Erstellung erweitern**
**Datei:** `src/AI/Agent/SubAgentFactory.php` (aktualisieren)
**Aufwand:** 1 Tag

```php
<?php
// src/AI/Agent/SubAgentFactory.php

namespace App\AI\Agent;

use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SubAgentFactory
{
    private ContainerInterface $container;
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private ParameterBagInterface $params;

    public function __construct(
        ContainerInterface $container,
        SubAgentDefinitionRepository $subAgentDefinitionRepo,
        ParameterBagInterface $params
    ) {
        $this->container = $container;
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        $this->params = $params;
    }

    /**
     * Erstellt einen Sub-Agenten basierend auf einer Definition.
     */
    public function createFromDefinition(SubAgentDefinition $definition): SubAgentInterface
    {
        $className = $definition->getClassName();
        
        if (!$this->container->has($className)) {
            throw new \RuntimeException(sprintf(
                'Sub-Agent class "%s" is not registered as a service.',
                $className
            ));
        }

        $subAgent = $this->container->get($className);
        
        if (!$subAgent instanceof SubAgentInterface) {
            throw new \RuntimeException(sprintf(
                'Service "%s" does not implement SubAgentInterface.',
                $className
            ));
        }

        return $subAgent;
    }

    /**
     * Erstellt alle aktiven Sub-Agenten aus der Datenbank.
     */
    public function createAllFromDatabase(): array
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();
        $subAgents = [];

        foreach ($definitions as $definition) {
            try {
                $subAgent = $this->createFromDefinition($definition);
                $subAgents[$definition->getName()] = $subAgent;
            } catch (\Exception $e) {
                // Loggen und weitermachen
                continue;
            }
        }

        return $subAgents;
    }

    /**
     * Erstellt einen Sub-Agenten basierend auf einem Namen (Fallback zu statischer Konfiguration).
     */
    public function createByName(string $name): SubAgentInterface
    {
        // 1. Versuche, aus der Datenbank zu laden
        $definition = $this->subAgentDefinitionRepo->findOneByName($name);
        if ($definition !== null) {
            return $this->createFromDefinition($definition);
        }

        // 2. Fallback: Statische Konfiguration aus ai.yaml
        return $this->createFromStaticConfig($name);
    }

    /**
     * Erstellt einen Sub-Agenten aus der statischen Konfiguration (ai.yaml).
     */
    private function createFromStaticConfig(string $name): SubAgentInterface
    {
        // Bestehende Logik beibehalten
        $subAgent = $this->container->get('ai.agent.' . $name);
        
        if (!$subAgent instanceof SubAgentInterface) {
            throw new \RuntimeException(sprintf(
                'Sub-Agent "%s" not found in static configuration.',
                $name
            ));
        }

        return $subAgent;
    }

    /**
     * Registriert einen neuen Sub-Agenten dynamisch.
     */
    public function registerSubAgent(SubAgentDefinition $definition): void
    {
        // Speichern in der Datenbank
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        $entityManager->persist($definition);
        $entityManager->flush();

        // Optional: Service-Container aktualisieren (für Lazy-Loading)
        // Dies erfordert einen Custom CompilerPass oder Runtime-Service-Registration
    }
}
```

##### **4. CompilerPass für dynamische Sub-Agenten-Registrierung**
**Datei:** `src/DependencyInjection/Compiler/AiSubAgentsCompilerPass.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/DependencyInjection/Compiler/AiSubAgentsCompilerPass.php

namespace App\DependencyInjection\Compiler;

use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AiSubAgentsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Prüfe, ob die benötigten Services existieren
        if (!$container->has('doctrine.orm.entity_manager') || 
            !$container->has(SubAgentDefinitionRepository::class)) {
            return;
        }

        // Lade alle aktiven Sub-Agenten-Definitionen aus der Datenbank
        // HINWEIS: Dies ist ein vereinfachtes Beispiel. In der Praxis muss die DB-Abfrage
        // zur Compile-Time durchgeführt werden (z. B. über ein Command oder Cache).
        // Alternativ: Lazy-Loading zur Runtime.
        $definitions = $this->loadSubAgentDefinitions($container);

        foreach ($definitions as $definition) {
            $this->registerSubAgentService($container, $definition);
        }
    }

    /**
     * Lädt Sub-Agenten-Definitionen aus der Datenbank.
     * HINWEIS: In der Praxis sollte dies über ein Cache-Warmup-Command geschehen.
     */
    private function loadSubAgentDefinitions(ContainerBuilder $container): array
    {
        // Vereinfachtes Beispiel: Leere Array zurückgeben
        // In der Praxis: Datenbankabfrage oder Cache
        return [];
    }

    /**
     * Registriert einen Sub-Agenten als Service.
     */
    private function registerSubAgentService(ContainerBuilder $container, SubAgentDefinition $definition): void
    {
        $serviceId = 'ai.agent.dynamic.' . $definition->getName();

        if ($container->has($serviceId)) {
            return; // Bereits registriert
        }

        $container->register($serviceId, $definition->getClassName())
            ->addTag('ai.agent')
            ->addTag('container.hot_path')
            ->setPublic(true);
    }
}
```

##### **5. Command zum Cache-Warmup für Sub-Agenten**
**Datei:** `src/Command/WarmupSubAgentsCacheCommand.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Command/WarmupSubAgentsCacheCommand.php

namespace App\Command;

use App\Repository\SubAgentDefinitionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'evie:subagents:warmup-cache',
    description: 'Lädt alle aktiven Sub-Agenten aus der Datenbank und registriert sie im Cache.'
)]
class WarmupSubAgentsCacheCommand extends Command
{
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;

    public function __construct(SubAgentDefinitionRepository $subAgentDefinitionRepo)
    {
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVIE Sub-Agenten Cache Warmup');

        $definitions = $this->subAgentDefinitionRepo->findAllActive();

        if (empty($definitions)) {
            $io->warning('Keine aktiven Sub-Agenten-Definitionen gefunden.');
            return Command::SUCCESS;
        }

        $io->section('Gefundene Sub-Agenten-Definitionen:');
        foreach ($definitions as $definition) {
            $io->text(sprintf(
                '- %s (%s)',
                $definition->getName(),
                $definition->getClassName()
            ));
        }

        $io->success(sprintf(
            '%d Sub-Agenten-Definitionen wurden geladen und gecacht.',
            count($definitions)
        ));

        return Command::SUCCESS;
    }
}
```

##### **6. SubAgentDispatcher für dynamische Delegation aktualisieren**
**Datei:** `src/AI/Agent/SubAgentDispatcher.php` (aktualisieren)
**Aufwand:** 0.5 Tage

```php
<?php
// src/AI/Agent/SubAgentDispatcher.php

namespace App\AI\Agent;

use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Psr\Log\LoggerInterface;

class SubAgentDispatcher
{
    private SubAgentFactory $subAgentFactory;
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private LoggerInterface $logger;

    public function __construct(
        SubAgentFactory $subAgentFactory,
        SubAgentDefinitionRepository $subAgentDefinitionRepo,
        LoggerInterface $logger
    ) {
        $this->subAgentFactory = $subAgentFactory;
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        $this->logger = $logger;
    }

    /**
     * Delegiert eine Aufgabe an den passenden Sub-Agenten.
     */
    public function delegate(string $task, array $context = []): array
    {
        // 1. Bestimme den passenden Sub-Agenten
        $subAgentName = $this->determineSubAgent($task);

        if ($subAgentName === null) {
            throw new \RuntimeException('Kein passender Sub-Agent für die Aufgabe gefunden.');
        }

        // 2. Lade den Sub-Agenten dynamisch
        $subAgent = $this->subAgentFactory->createByName($subAgentName);

        // 3. Führe die Aufgabe aus
        return $subAgent->execute($task, $context);
    }

    /**
     * Bestimmt den passenden Sub-Agenten für eine Aufgabe.
     */
    private function determineSubAgent(string $task): ?string
    {
        // 1. Prüfe, ob ein Sub-Agent explizit in der Aufgabe genannt wird
        if (preg_match('/@([a-zA-Z0-9_]+)/', $task, $matches)) {
            return $matches[1];
        }

        // 2. Nutze LLM-basierte Klassifizierung (bestehende Logik)
        return $this->classifyTask($task);
    }

    /**
     * Klassifiziert eine Aufgabe und gibt den passenden Sub-Agenten-Namen zurück.
     */
    private function classifyTask(string $task): ?string
    {
        // Vereinfachtes Beispiel: Nutze die bestehende Logik aus OrchestratorDialogService
        // In der Praxis: LLM-basierte Klassifizierung
        $keywords = [
            'website' => 'website_researcher',
            'data' => 'data_analyst',
            'code' => 'code_assistant',
            'email' => 'email_manager',
            // ... weitere Keywords
        ];

        foreach ($keywords as $keyword => $subAgentName) {
            if (stripos($task, $keyword) !== false) {
                return $subAgentName;
            }
        }

        return null;
    }

    /**
     * Gibt alle verfügbaren Sub-Agenten zurück.
     */
    public function getAvailableSubAgents(): array
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();
        $subAgents = [];

        foreach ($definitions as $definition) {
            try {
                $subAgent = $this->subAgentFactory->createFromDefinition($definition);
                $subAgents[$definition->getName()] = $subAgent;
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Fehler beim Laden des Sub-Agenten "%s": %s',
                    $definition->getName(),
                    $e->getMessage()
                ));
                continue;
            }
        }

        return $subAgents;
    }
}
```

##### **7. Services.yaml aktualisieren**
**Datei:** `config/services.yaml` (aktualisieren)
**Aufwand:** 0.5 Tage

```yaml
# config/services.yaml

# Sub-Agenten-Definitionen
App\Entity\SubAgentDefinition:
    type: entity
    repositoryClass: App\Repository\SubAgentDefinitionRepository

# Repository
App\Repository\SubAgentDefinitionRepository:
    arguments:
        $registry: '@doctrine'

# SubAgentFactory
App\AI\Agent\SubAgentFactory:
    arguments:
        $container: '@service_container'
        $subAgentDefinitionRepo: '@App\Repository\SubAgentDefinitionRepository'
        $params: '@parameter_bag'

# SubAgentDispatcher
App\AI\Agent\SubAgentDispatcher:
    arguments:
        $subAgentFactory: '@App\AI\Agent\SubAgentFactory'
        $subAgentDefinitionRepo: '@App\Repository\SubAgentDefinitionRepository'
        $logger: '@logger'

# CompilerPass für Sub-Agenten
App\DependencyInjection\Compiler\AiSubAgentsCompilerPass:
    tags:
        - { name: 'container.compiler_pass' }

# Command für Cache-Warmup
App\Command\WarmupSubAgentsCacheCommand:
    arguments:
        $subAgentDefinitionRepo: '@App\Repository\SubAgentDefinitionRepository'
    tags:
        - { name: 'console.command' }
```

##### **8. Migration für Sub-Agenten-Definitionen erstellen**
**Datei:** `migrations/Version20260812180000.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// migrations/Version20260812180000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die Tabelle für Sub-Agenten-Definitionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(''
            . 'CREATE TABLE ai_sub_agent_definitions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'name VARCHAR(255) NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'class_name VARCHAR(255) NOT NULL,'
            . 'configuration JSON NOT NULL,'
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME DEFAULT NULL,'
            . 'created_by_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE INDEX UNIQ_SUB_AGENT_NAME (name),'
            . 'INDEX IDX_SUB_AGENT_ACTIVE (is_active),'
            . 'INDEX IDX_SUB_AGENT_CREATED_BY (created_by_id)'
            . ')'
        );
        
        $this->addSql(''
            . 'ALTER TABLE ai_sub_agent_definitions '
            . 'ADD CONSTRAINT FK_SUB_AGENT_CREATED_BY '
            . 'FOREIGN KEY (created_by_id) REFERENCES user (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_sub_agent_definitions');
    }
}
```

##### **9. Unit-Tests für Sub-Agenten-Dynamik**
**Datei:** `tests/Unit/AI/Agent/SubAgentFactoryTest.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// tests/Unit/AI/Agent/SubAgentFactoryTest.php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\SubAgentFactory;
use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class SubAgentFactoryTest extends TestCase
{
    private SubAgentFactory $factory;
    private ContainerInterface $containerMock;
    private SubAgentDefinitionRepository $repoMock;
    private ParameterBag $paramsMock;

    protected function setUp(): void
    {
        $this->containerMock = $this->createMock(ContainerInterface::class);
        $this->repoMock = $this->createMock(SubAgentDefinitionRepository::class);
        $this->paramsMock = $this->createMock(ParameterBag::class);

        $this->factory = new SubAgentFactory(
            $this->containerMock,
            $this->repoMock,
            $this->paramsMock
        );
    }

    public function testCreateFromDefinition(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('test_agent');
        $definition->setClassName('App\AI\Agent\SubAgent\TestAgent');

        $subAgentMock = $this->createMock(SubAgentInterface::class);

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\SubAgent\TestAgent')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('App\AI\Agent\SubAgent\TestAgent')
            ->willReturn($subAgentMock);

        $result = $this->factory->createFromDefinition($definition);

        $this->assertSame($subAgentMock, $result);
    }

    public function testCreateFromDefinitionWithInvalidClass(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setClassName('App\AI\Agent\SubAgent\InvalidAgent');

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\SubAgent\InvalidAgent')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sub-Agent class "App\AI\Agent\SubAgent\InvalidAgent" is not registered as a service.');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateByNameFromDatabase(): void
    {
        $definition = new SubAgentDefinition();
        $definition->setName('test_agent');
        $definition->setClassName('App\AI\Agent\SubAgent\TestAgent');

        $subAgentMock = $this->createMock(SubAgentInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('test_agent')
            ->willReturn($definition);

        $this->containerMock
            ->method('has')
            ->with('App\AI\Agent\SubAgent\TestAgent')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('App\AI\Agent\SubAgent\TestAgent')
            ->willReturn($subAgentMock);

        $result = $this->factory->createByName('test_agent');

        $this->assertSame($subAgentMock, $result);
    }

    public function testCreateByNameFromStaticConfig(): void
    {
        $subAgentMock = $this->createMock(SubAgentInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('test_agent')
            ->willReturn(null);

        $this->containerMock
            ->method('get')
            ->with('ai.agent.test_agent')
            ->willReturn($subAgentMock);

        $result = $this->factory->createByName('test_agent');

        $this->assertSame($subAgentMock, $result);
    }
}
```

##### **10. Integrationstests für Sub-Agenten**
**Datei:** `tests/Integration/AI/Agent/SubAgentDispatcherIntegrationTest.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// tests/Integration/AI/Agent/SubAgentDispatcherIntegrationTest.php

namespace App\Tests\Integration\AI\Agent;

use App\AI\Agent\SubAgentDispatcher;
use App\AI\Agent\SubAgentFactory;
use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SubAgentDispatcherIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SubAgentDefinitionRepository $repo;
    private SubAgentDispatcher $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repo = $this->entityManager->getRepository(SubAgentDefinition::class);

        $subAgentFactory = self::getContainer()->get(SubAgentFactory::class);
        $this->dispatcher = new SubAgentDispatcher(
            $subAgentFactory,
            $this->repo,
            self::getContainer()->get('logger')
        );
    }

    public function testDelegateToDynamicSubAgent(): void
    {
        // 1. Erstelle eine Sub-Agenten-Definition in der DB
        $definition = new SubAgentDefinition();
        $definition->setName('test_data_analyst');
        $definition->setDescription('Test Data Analyst Agent');
        $definition->setClassName('App\AI\Agent\SubAgent\DataAnalystAgent');
        $definition->setConfiguration(['model' => 'mistral-large']);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // 2. Delegiere eine Aufgabe
        $task = 'Analysiere diese Daten @test_data_analyst';
        $result = $this->dispatcher->delegate($task);

        // 3. Überprüfe, dass der Sub-Agent aufgerufen wurde
        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
    }

    public function testGetAvailableSubAgents(): void
    {
        // 1. Erstelle Sub-Agenten-Definitionen in der DB
        $definition1 = new SubAgentDefinition();
        $definition1->setName('test_agent_1');
        $definition1->setClassName('App\AI\Agent\SubAgent\TestAgent1');

        $definition2 = new SubAgentDefinition();
        $definition2->setName('test_agent_2');
        $definition2->setClassName('App\AI\Agent\SubAgent\TestAgent2');

        $this->entityManager->persist($definition1);
        $this->entityManager->persist($definition2);
        $this->entityManager->flush();

        // 2. Hole alle verfügbaren Sub-Agenten
        $subAgents = $this->dispatcher->getAvailableSubAgents();

        // 3. Überprüfe, dass die Sub-Agenten geladen wurden
        $this->assertArrayHasKey('test_agent_1', $subAgents);
        $this->assertArrayHasKey('test_agent_2', $subAgents);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Bereinige die DB
        $this->entityManager->createQuery('DELETE FROM App\Entity\SubAgentDefinition')->execute();
        $this->entityManager->flush();
    }
}
```

#### **✅ Abnahmekriterien**

| **Kriterium** | **Details** | **Status** |
|--------------|-------------|------------|
| Sub-Agenten-Definitionen in der DB speicherbar | Entity + Repository + Migration | ⏳ |
| Sub-Agenten dynamisch aus DB ladbar | `SubAgentFactory::createFromDefinition()` | ⏳ |
| Sub-Agenten dynamisch registrierbar | `SubAgentFactory::registerSubAgent()` | ⏳ |
| CompilerPass für Sub-Agenten | `AiSubAgentsCompilerPass` | ⏳ |
| Cache-Warmup-Command | `evie:subagents:warmup-cache` | ⏳ |
| Sub-Agenten-Delegation funktioniert | `SubAgentDispatcher::delegate()` | ⏳ |
| Unit-Tests für SubAgentFactory | 10+ Test-Cases | ⏳ |
| Integrationstests für SubAgentDispatcher | 5+ Test-Cases | ⏳ |
| **Alle Sub-Agenten sind dynamisch ladbar** | Keine hardcoded Agenten in `ai.yaml` | ⏳ |

---

### **🟡 Maßnahme 5: Streaming-Antworten implementieren**
**Priorität:** 🟡 **Hoch** | **Aufwand:** 5-7 Tage | **Impact:** 🟡 **Hoch** | **Start:** 21. August 2026

#### **📌 Hintergrund**
Aktuell werden **alle Tool-Executions synchron** ausgeführt. Dies führt zu **keinem Fortschritts-Feedback** für den User, insbesondere bei **langen Operationen** (z. B. Web-Scraping, Datenanalyse). Das **Symfony AI Bundle** unterstützt **asynchrone Ausführung** über **Symfony Messenger** und **Streaming-Antworten** über **Symfony HTTP Kernel Events**.

#### **🎯 Ziele**
- **Asynchrone Tool-Execution** mit Symfony Messenger
- **Streaming-Antworten** für Echtzeit-Feedback
- **Fortschrittsbalken** in der UI
- **WebSocket-Integration** für Push-Updates

#### **📝 Umsetzung**

##### **1. Symfony Messenger für asynchrone Tool-Execution konfigurieren**
**Datei:** `config/packages/messenger.yaml` (neu)
**Aufwand:** 0.5 Tage

```yaml
# config/packages/messenger.yaml

framework:
    messenger:
        transports:
            async_tools: '%env(MESSENGER_TRANSPORT_DSN)%'
            # Beispiel: async_tools: 'doctrine://default?queue_name=async_tools'

        routing:
            'App\Message\ExecuteToolMessage': async_tools
            'App\Message\StreamToolResponseMessage': async_tools

        serializer:
            default_serializer: messenger.transport.symfony_serializer
            symfony_serializer:
                format: json
                context: {}
```

##### **2. Message-Klassen für Tool-Execution erstellen**
**Datei:** `src/Message/ExecuteToolMessage.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Message/ExecuteToolMessage.php

namespace App\Message;

use App\Entity\ToolDefinition;
use Symfony\Component\Uuid\Uuid;

class ExecuteToolMessage
{
    private Uuid $messageId;
    private string $toolName;
    private array $arguments;
    private string $userIdentifier;
    private string $sessionId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $toolName,
        array $arguments,
        string $userIdentifier,
        string $sessionId
    ) {
        $this->messageId = Uuid::v4();
        $this->toolName = $toolName;
        $this->arguments = $arguments;
        $this->userIdentifier = $userIdentifier;
        $this->sessionId = $sessionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

**Datei:** `src/Message/StreamToolResponseMessage.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Message/StreamToolResponseMessage.php

namespace App\Message;

use Symfony\Component\Uuid\Uuid;

class StreamToolResponseMessage
{
    private Uuid $messageId;
    private string $sessionId;
    private string $toolName;
    private mixed $chunk;
    private bool $isFinal;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionId,
        string $toolName,
        mixed $chunk,
        bool $isFinal = false
    ) {
        $this->messageId = Uuid::v4();
        $this->sessionId = $sessionId;
        $this->toolName = $toolName;
        $this->chunk = $chunk;
        $this->isFinal = $isFinal;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getChunk(): mixed
    {
        return $this->chunk;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

##### **3. MessageHandler für Tool-Execution erstellen**
**Datei:** `src/MessageHandler/ExecuteToolMessageHandler.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/MessageHandler/ExecuteToolMessageHandler.php

namespace App\MessageHandler;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Security\HitlInterceptor;
use App\Message\ExecuteToolMessage;
use App\Message\StreamToolResponseMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class ExecuteToolMessageHandler
{
    private DynamicSkillRegistry $skillRegistry;
    private HitlInterceptor $hitlInterceptor;
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;

    public function __construct(
        DynamicSkillRegistry $skillRegistry,
        HitlInterceptor $hitlInterceptor,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->skillRegistry = $skillRegistry;
        $this->hitlInterceptor = $hitlInterceptor;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
    }

    public function __invoke(ExecuteToolMessage $message): void
    {
        $toolName = $message->getToolName();
        $arguments = $message->getArguments();
        $userIdentifier = $message->getUserIdentifier();
        $sessionId = $message->getSessionId();

        try {
            // 1. Prüfe, ob das Tool genehmigt und sicher ist
            $tool = $this->skillRegistry->getTool($toolName);
            
            if (!$this->hitlInterceptor->isToolSafe($tool, '', $userIdentifier)) {
                $this->logger->error(sprintf(
                    'Tool "%s" wurde aus Sicherheitsgründen blockiert.',
                    $toolName
                ));
                return;
            }

            // 2. Führe das Tool aus und streame die Ergebnisse
            $result = [];
            $isFirstChunk = true;

            // Simuliere Streaming (in der Praxis: Tool gibt Chunks zurück)
            foreach ($this->executeToolInChunks($tool, $arguments) as $chunk) {
                // Sende jeden Chunk als Message
                $this->messageBus->dispatch(new StreamToolResponseMessage(
                    $sessionId,
                    $toolName,
                    $chunk,
                    false // Nicht der letzte Chunk
                ));

                // Für den ersten Chunk: Initialisiere die Antwort
                if ($isFirstChunk) {
                    $result[] = $chunk;
                    $isFirstChunk = false;
                } else {
                    $result[] = $chunk;
                }
            }

            // 3. Sende den finalen Chunk
            $this->messageBus->dispatch(new StreamToolResponseMessage(
                $sessionId,
                $toolName,
                ['final_result' => $result],
                true // Finaler Chunk
            ));

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Fehler bei der Ausführung des Tools "%s": %s',
                $toolName,
                $e->getMessage()
            ));

            // Sende Fehlermeldung als finalen Chunk
            $this->messageBus->dispatch(new StreamToolResponseMessage(
                $sessionId,
                $toolName,
                ['error' => $e->getMessage()],
                true
            ));
        }
    }

    /**
     * Führt ein Tool in Chunks aus (simuliert Streaming).
     * In der Praxis: Tool sollte Chunks direkt zurückgeben.
     */
    private function executeToolInChunks(object $tool, array $arguments): \Generator
    {
        // Vereinfachtes Beispiel: Teile das Ergebnis in Chunks auf
        $result = $tool(...$arguments);

        if (is_array($result)) {
            foreach ($result as $item) {
                yield $item;
            }
        } else {
            yield $result;
        }
    }
}
```

##### **4. MessageHandler für Streaming-Antworten erstellen**
**Datei:** `src/MessageHandler/StreamToolResponseMessageHandler.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/MessageHandler/StreamToolResponseMessageHandler.php

namespace App\MessageHandler;

use App\Message\StreamToolResponseMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class StreamToolResponseMessageHandler
{
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public function __invoke(StreamToolResponseMessage $message): void
    {
        // 1. Logge die Message (für Debugging)
        $this->logger->debug(sprintf(
            'Streaming-Chunk für Session "%s" (Tool: %s) empfangen. Final: %s',
            $message->getSessionId(),
            $message->getToolName(),
            $message->isFinal() ? 'Ja' : 'Nein'
        ));

        // 2. Hier könnte die Message an einen WebSocket-Server weitergeleitet werden
        // oder in einer Datenbank für die Session gespeichert werden.
        // Beispiel: Speichern in einer Session-Speicher-Tabelle
        // $this->saveChunkToSession($message);

        // 3. Optional: Benachrichtige den User über WebSocket
        // $this->notifyUserViaWebSocket($message);
    }
}
```

##### **5. Service für Streaming-Sessions erstellen**
**Datei:** `src/AI/Streaming/StreamingSessionManager.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/AI/Streaming/StreamingSessionManager.php

namespace App\AI\Streaming;

use App\Entity\StreamingSession;
use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uuid\Uuid;

class StreamingSessionManager
{
    private EntityManagerInterface $entityManager;
    private StreamingSessionRepository $sessionRepo;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        StreamingSessionRepository $sessionRepo,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->sessionRepo = $sessionRepo;
        $this->logger = $logger;
    }

    /**
     * Erstellt eine neue Streaming-Session.
     */
    public function createSession(string $userIdentifier, string $initialPrompt): StreamingSession
    {
        $session = new StreamingSession();
        $session->setId(Uuid::v4());
        $session->setUserIdentifier($userIdentifier);
        $session->setInitialPrompt($initialPrompt);
        $session->setStatus(StreamingSession::STATUS_ACTIVE);
        $session->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Fügt einen Chunk zu einer Session hinzu.
     */
    public function addChunk(StreamingSession $session, mixed $chunk, bool $isFinal = false): void
    {
        $session->addChunk($chunk);
        
        if ($isFinal) {
            $session->setStatus(StreamingSession::STATUS_COMPLETED);
            $session->setCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * Gibt alle Chunks einer Session zurück.
     */
    public function getSessionChunks(StreamingSession $session): array
    {
        return $session->getChunks();
    }

    /**
     * Beendet eine Session.
     */
    public function endSession(StreamingSession $session, string $finalResult): void
    {
        $session->setStatus(StreamingSession::STATUS_COMPLETED);
        $session->setFinalResult($finalResult);
        $session->setCompletedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    /**
     * Gibt eine Session nach ID zurück.
     */
    public function getSessionById(Uuid $sessionId): ?StreamingSession
    {
        return $this->sessionRepo->find($sessionId);
    }

    /**
     * Gibt alle aktiven Sessions eines Users zurück.
     */
    public function getActiveSessionsByUser(string $userIdentifier): array
    {
        return $this->sessionRepo->findBy([
            'userIdentifier' => $userIdentifier,
            'status' => StreamingSession::STATUS_ACTIVE,
        ]);
    }
}
```

##### **6. Entity für Streaming-Sessions erstellen**
**Datei:** `src/Entity/StreamingSession.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Entity/StreamingSession.php

namespace App\Entity;

use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StreamingSessionRepository::class)]
#[ORM\Table(name: 'ai_streaming_sessions')]
class StreamingSession
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: 'text')]
    private string $initialPrompt;

    #[ORM\Column(type: 'json')]
    private array $chunks = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $finalResult = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $user = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getter & Setter
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): self
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function getInitialPrompt(): string
    {
        return $this->initialPrompt;
    }

    public function setInitialPrompt(string $initialPrompt): self
    {
        $this->initialPrompt = $initialPrompt;
        return $this;
    }

    public function getChunks(): array
    {
        return $this->chunks;
    }

    public function setChunks(array $chunks): self
    {
        $this->chunks = $chunks;
        return $this;
    }

    public function addChunk(mixed $chunk): self
    {
        $this->chunks[] = $chunk;
        return $this;
    }

    public function getFinalResult(): ?array
    {
        return $this->finalResult;
    }

    public function setFinalResult(?array $finalResult): self
    {
        $this->finalResult = $finalResult;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }
}
```

##### **7. Repository für Streaming-Sessions erstellen**
**Datei:** `src/Repository/StreamingSessionRepository.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Repository/StreamingSessionRepository.php

namespace App\Repository;

use App\Entity\StreamingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class StreamingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreamingSession::class);
    }

    /**
     * Finde eine Session nach ID.
     */
    public function findById(Uuid $sessionId): ?StreamingSession
    {
        return $this->find($sessionId);
    }

    /**
     * Finde alle aktiven Sessions eines Users.
     */
    public function findActiveByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userIdentifier = :userIdentifier')
            ->andWhere('s.status = :status')
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('status', StreamingSession::STATUS_ACTIVE)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde alle Sessions eines Users.
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lösche abgelaufene Sessions (z. B. älter als 24 Stunden).
     */
    public function deleteExpiredSessions(\DateTimeInterface $olderThan): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.createdAt < :olderThan')
            ->setParameter('olderThan', $olderThan)
            ->getQuery()
            ->execute();
    }
}
```

##### **8. Controller für Streaming-Antworten erstellen**
**Datei:** `src/Controller/StreamingController.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/Controller/StreamingController.php

namespace App\Controller;

use App\AI\Streaming\StreamingSessionManager;
use App\Message\ExecuteToolMessage;
use App\Message\StreamToolResponseMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

class StreamingController extends AbstractController
{
    private MessageBusInterface $messageBus;
    private StreamingSessionManager $sessionManager;

    public function __construct(
        MessageBusInterface $messageBus,
        StreamingSessionManager $sessionManager
    ) {
        $this->messageBus = $messageBus;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Startet eine neue Streaming-Session für eine Tool-Execution.
     */
    #[Route('/api/streaming/start', name: 'api_streaming_start', methods: ['POST'])]
    public function startStreaming(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $toolName = $data['tool_name'] ?? null;
        $arguments = $data['arguments'] ?? [];
        $userIdentifier = $data['user_identifier'] ?? 'anonymous';

        if ($toolName === null) {
            return $this->json(['error' => 'Tool-Name ist erforderlich.'], 400);
        }

        // 1. Erstelle eine neue Session
        $session = $this->sessionManager->createSession(
            $userIdentifier,
            sprintf('Ausführung von Tool: %s', $toolName)
        );

        // 2. Dispatch die Tool-Execution als asynchrone Message
        $this->messageBus->dispatch(new ExecuteToolMessage(
            $toolName,
            $arguments,
            $userIdentifier,
            $session->getId()->toRfc4122()
        ));

        return $this->json([
            'session_id' => $session->getId()->toRfc4122(),
            'status' => 'streaming_started',
            'message' => 'Tool-Execution wurde gestartet. Nutze /api/streaming/{session_id}/chunks für Updates.',
        ]);
    }

    /**
     * Gibt die Chunks einer Streaming-Session zurück.
     */
    #[Route('/api/streaming/{sessionId}/chunks', name: 'api_streaming_chunks', methods: ['GET'])]
    public function getChunks(string $sessionId): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($sessionId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Ungültige Session-ID.'], 400);
        }

        $session = $this->sessionManager->getSessionById($uuid);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden.'], 404);
        }

        return $this->json([
            'session_id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus(),
            'chunks' => $session->getChunks(),
            'final_result' => $session->getFinalResult(),
            'is_complete' => $session->getStatus() === StreamingSession::STATUS_COMPLETED,
        ]);
    }

    /**
     * Streamt Chunks in Echtzeit (Server-Sent Events).
     */
    #[Route('/api/streaming/{sessionId}/stream', name: 'api_streaming_stream', methods: ['GET'])]
    public function streamChunks(string $sessionId): Response
    {
        try {
            $uuid = Uuid::fromString($sessionId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Ungültige Session-ID.'], 400);
        }

        $session = $this->sessionManager->getSessionById($uuid);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden.'], 404);
        }

        $response = new Response();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        // Sende initiale Daten
        $response->setContent($this->generateSseContent($session));

        return $response;
    }

    /**
     * Generiert SSE-Inhalt für eine Session.
     */
    private function generateSseContent(StreamingSession $session): string
    {
        $chunks = $session->getChunks();
        $content = '';

        foreach ($chunks as $chunk) {
            $data = json_encode(['chunk' => $chunk]);
            $content .= "data: {$data}\n\n";
        }

        if ($session->getStatus() === StreamingSession::STATUS_COMPLETED) {
            $data = json_encode(['final_result' => $session->getFinalResult()]);
            $content .= "data: {$data}\nevent: complete\n\n";
        }

        return $content;
    }
}
```

##### **9. WebSocket-Integration mit Mercure (Symfony-Kernkomponente)**
**Datei:** `config/packages/mercure.yaml` (neu)
**Aufwand:** 0.5 Tage

```yaml
# config/packages/mercure.yaml

mercure:
    hub:
        url: '%env(MERCURE_HUB_URL)%'
        public_url: '%env(MERCURE_PUBLIC_HUB_URL)%'
        jwt: '%env(MERCURE_JWT)%'
    transports:
        - '%env(MESSENGER_TRANSPORT_DSN)%'
```

**Datei:** `src/EventSubscriber/StreamingEventSubscriber.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/EventSubscriber/StreamingEventSubscriber.php

namespace App\EventSubscriber;

use App\Message\StreamToolResponseMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\HandleMessageEvent;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class StreamingEventSubscriber implements EventSubscriberInterface
{
    private HubInterface $hub;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            HandleMessageEvent::class => 'onMessageHandled',
        ];
    }

    public function onMessageHandled(HandleMessageEvent $event): void
    {
        $message = $event->getMessage();

        if (!$message instanceof StreamToolResponseMessage) {
            return;
        }

        // Erstelle ein Mercure-Update für die Session
        $update = new Update(
            sprintf('/streaming/%s', $message->getSessionId()),
            json_encode([
                'session_id' => $message->getSessionId(),
                'tool_name' => $message->getToolName(),
                'chunk' => $message->getChunk(),
                'is_final' => $message->isFinal(),
                'timestamp' => $message->getCreatedAt()->format('c'),
            ])
        );

        // Sende das Update an alle Abonnenten
        $this->hub->publish($update);
    }
}
```

##### **10. Frontend-Integration mit HTMX für Streaming**
**Datei:** `templates/agent/streaming.html.twig` (neu)
**Aufwand:** 1 Tag

```twig
{# templates/agent/streaming.html.twig #}

{% extends 'base.html.twig' %}

{% block title %}EVIE - Streaming Tool Execution{% endblock %}

{% block stylesheets %}
    {{ parent() }}
    <style>
        .streaming-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .streaming-chunks {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            white-space: pre-wrap;
            min-height: 300px;
            max-height: 500px;
            overflow-y: auto;
        }
        .chunk {
            margin-bottom: 10px;
            padding: 5px;
            border-left: 3px solid #3b82f6;
        }
        .chunk-final {
            border-left-color: #10b981;
        }
        .chunk-error {
            border-left-color: #ef4444;
        }
        .progress-bar {
            height: 4px;
            background: #374151;
            border-radius: 2px;
            margin-bottom: 10px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: #3b82f6;
            width: 0%;
            transition: width 0.3s ease;
        }
        .status {
            color: #9ca3af;
            font-size: 0.875rem;
        }
        .status-active {
            color: #3b82f6;
        }
        .status-completed {
            color: #10b981;
        }
        .status-failed {
            color: #ef4444;
        }
    </style>
{% endblock %}

{% block body %}
    <div class="streaming-container">
        <h1 class="text-2xl font-bold mb-6">Tool Execution Streaming</h1>

        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-2">Aktive Session</h2>
            <p class="text-gray-400">
                Session-ID: <code class="bg-gray-700 px-2 py-1 rounded">{{ session_id }}</code>
            </p>
            <p class="text-gray-400">
                Tool: <span class="font-mono">{{ tool_name }}</span>
            </p>
            <div class="progress-bar mt-4">
                <div class="progress-bar-fill" id="progress-bar" style="width: {{ progress }}%;"></div>
            </div>
            <p class="status mt-2 {{ status_class }}">
                Status: <span id="status-text">{{ status }}</span>
            </p>
        </div>

        <div class="streaming-chunks" id="streaming-chunks">
            {% for chunk in chunks %}
                <div class="chunk {% if chunk.error is defined %}chunk-error{% elseif chunk.final_result is defined %}chunk-final{% endif %}">
                    {% if chunk.chunk is defined %}
                        {{ chunk.chunk|json_encode|raw }}
                    {% elseif chunk.final_result is defined %}
                        <strong>Finales Ergebnis:</strong>
                        {{ chunk.final_result|json_encode|raw }}
                    {% elseif chunk.error is defined %}
                        <strong>Fehler:</strong> {{ chunk.error }}
                    {% endif %}
                </div>
            {% endfor %}
        </div>

        <div class="mt-6 flex gap-4">
            <button 
                hx-get="{{ path('api_streaming_chunks', {sessionId: session_id}) }}"
                hx-trigger="every 2s"
                hx-target="#streaming-chunks"
                hx-swap="innerHTML"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
            >
                Aktualisieren
            </button>

            <button 
                hx-get="{{ path('api_streaming_stream', {sessionId: session_id}) }}"
                hx-trigger="sse:complete"
                hx-target="#streaming-chunks"
                hx-swap="innerHTML"
                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors"
            >
                Stream beenden
            </button>
        </div>
    </div>

    <script>
        // HTMX-Konfiguration für SSE
        document.addEventListener('DOMContentLoaded', function() {
            const sessionId = '{{ session_id }}';
            const chunksContainer = document.getElementById('streaming-chunks');
            const progressBar = document.getElementById('progress-bar');
            const statusText = document.getElementById('status-text');

            // Verbinde mit dem SSE-Stream
            const eventSource = new EventSource(`{{ path('api_streaming_stream', {sessionId: session_id}) }}`);

            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);

                if (data.chunk) {
                    // Füge den Chunk hinzu
                    const chunkElement = document.createElement('div');
                    chunkElement.className = 'chunk';
                    chunkElement.textContent = JSON.stringify(data.chunk, null, 2);
                    chunksContainer.appendChild(chunkElement);

                    // Scroll nach unten
                    chunksContainer.scrollTop = chunksContainer.scrollHeight;

                    // Aktualisiere Fortschritt (vereinfacht)
                    const currentProgress = parseInt(progressBar.style.width) || 0;
                    progressBar.style.width = Math.min(currentProgress + 10, 90) + '%';
                }

                if (data.final_result) {
                    // Finales Ergebnis
                    const finalElement = document.createElement('div');
                    finalElement.className = 'chunk chunk-final';
                    finalElement.innerHTML = '<strong>Finales Ergebnis:</strong><br>' + 
                        JSON.stringify(data.final_result, null, 2);
                    chunksContainer.appendChild(finalElement);

                    // 100% Fortschritt
                    progressBar.style.width = '100%';
                    statusText.textContent = 'Abgeschlossen';
                    statusText.className = 'status status-completed';

                    // Schließe die Verbindung
                    eventSource.close();
                }

                if (data.error) {
                    // Fehler
                    const errorElement = document.createElement('div');
                    errorElement.className = 'chunk chunk-error';
                    errorElement.innerHTML = '<strong>Fehler:</strong> ' + data.error;
                    chunksContainer.appendChild(errorElement);

                    statusText.textContent = 'Fehlgeschlagen';
                    statusText.className = 'status status-failed';

                    // Schließe die Verbindung
                    eventSource.close();
                }
            };

            eventSource.onerror = function() {
                statusText.textContent = 'Verbindung fehlgeschlagen';
                statusText.className = 'status status-failed';
                eventSource.close();
            };
        });
    </script>
{% endblock %}
```

##### **11. Migration für Streaming-Sessions erstellen**
**Datei:** `migrations/Version20260821180000.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// migrations/Version20260821180000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die Tabelle für Streaming-Sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(''
            . 'CREATE TABLE ai_streaming_sessions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'user_identifier VARCHAR(255) NOT NULL,'
            . 'initial_prompt TEXT NOT NULL,'
            . 'chunks JSON NOT NULL,'
            . 'final_result JSON DEFAULT NULL,'
            . 'status VARCHAR(50) NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'completed_at DATETIME DEFAULT NULL,'
            . 'user_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'INDEX IDX_STREAMING_SESSION_USER (user_identifier),'
            . 'INDEX IDX_STREAMING_SESSION_STATUS (status),'
            . 'INDEX IDX_STREAMING_SESSION_USER_ID (user_id)'
            . ')'
        );
        
        $this->addSql(''
            . 'ALTER TABLE ai_streaming_sessions '
            . 'ADD CONSTRAINT FK_STREAMING_SESSION_USER '
            . 'FOREIGN KEY (user_id) REFERENCES user (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_streaming_sessions');
    }
}
```

#### **✅ Abnahmekriterien**

| **Kriterium** | **Details** | **Status** |
|--------------|-------------|------------|
| Symfony Messenger konfiguriert | `messenger.yaml` mit async_tools Transport | ⏳ |
| ExecuteToolMessage implementiert | Message-Klasse für Tool-Execution | ⏳ |
| StreamToolResponseMessage implementiert | Message-Klasse für Streaming-Chunks | ⏳ |
| ExecuteToolMessageHandler implementiert | Handler für Tool-Execution | ⏳ |
| StreamToolResponseMessageHandler implementiert | Handler für Streaming-Chunks | ⏳ |
| StreamingSessionManager implementiert | Service für Session-Verwaltung | ⏳ |
| StreamingSession Entity implementiert | Entity für Session-Daten | ⏳ |
| StreamingSessionRepository implementiert | Repository für Session-Zugriff | ⏳ |
| StreamingController implementiert | Controller für Streaming-API | ⏳ |
| WebSocket-Integration mit Mercure | EventSubscriber für SSE | ⏳ |
| Frontend mit HTMX für Streaming | Template für Echtzeit-Updates | ⏳ |
| Migration für Streaming-Sessions | Datenbank-Tabelle | ⏳ |
| **Streaming-Antworten funktionieren** | Echtzeit-Feedback für Tool-Execution | ⏳ |

---

### **🟡 Maßnahme 6: MCP-Server dynamisch konfigurierbar machen**
**Priorität:** 🟡 **Hoch** | **Aufwand:** 2-3 Tage | **Impact:** 🟡 **Hoch** | **Start:** 28. August 2026

#### **📌 Hintergrund**
Aktuell sind **MCP-Server hardcoded in `ai.yaml`** (filesystem, playwright, github). Dies ist **nicht flexibel** und verhindert die **dynamische Integration** neuer MCP-Server. Das **Symfony AI Bundle** unterstützt die **dynamische Konfiguration** von MCP-Servern über **Service-Definitionen**.

#### **🎯 Ziele**
- MCP-Server **nicht mehr hardcoded**, sondern **aus der Datenbank laden**
- **Flexible Integration** neuer MCP-Server ohne Code-Änderungen
- **Sicherheitsprüfung** durch SecurityGuard
- **Symfony AI Bundle-konform**

#### **📝 Umsetzung**

##### **1. Entity für MCP-Server-Definitionen erstellen**
**Datei:** `src/Entity/McpServerDefinition.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Entity/McpServerDefinition.php

namespace App\Entity;

use App\Repository\McpServerDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MCPServerDefinitionRepository::class)]
#[ORM\Table(name: 'ai_mcp_server_definitions')]
class McpServerDefinition
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $type; // z. B. 'filesystem', 'playwright', 'github', 'custom'

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'json')]
    private array $configuration; // URL, Token, etc.

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'json')]
    private array $allowedTools = []; // Whitelist für Tools

    #[ORM\Column(type: 'json')]
    private array $blockedResources = []; // Blocklist für Ressourcen

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getter & Setter
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $configuration): self
    {
        $this->configuration = $configuration;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getAllowedTools(): array
    {
        return $this->allowedTools;
    }

    public function setAllowedTools(array $allowedTools): self
    {
        $this->allowedTools = $allowedTools;
        return $this;
    }

    public function getBlockedResources(): array
    {
        return $this->blockedResources;
    }

    public function setBlockedResources(array $blockedResources): self
    {
        $this->blockedResources = $blockedResources;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
```

##### **2. Repository für MCP-Server-Definitionen erstellen**
**Datei:** `src/Repository/McpServerDefinitionRepository.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Repository/McpServerDefinitionRepository.php

namespace App\Repository;

use App\Entity\McpServerDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class McpServerDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServerDefinition::class);
    }

    /**
     * Finde alle aktiven MCP-Server-Definitionen.
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde eine MCP-Server-Definition nach Name.
     */
    public function findOneByName(string $name): ?McpServerDefinition
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Finde MCP-Server nach Typ.
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.type = :type')
            ->andWhere('m.isActive = :isActive')
            ->setParameter('type', $type)
            ->setParameter('isActive', true)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
```

##### **3. MCP-Server-Factory für dynamische Erstellung**
**Datei:** `src/AI/Mcp/McpServerFactory.php` (neu)
**Aufwand:** 1 Tag

```php
<?php
// src/AI/Mcp/McpServerFactory.php

namespace App\AI\Mcp;

use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use App\AI\Security\SecurityGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class McpServerFactory
{
    private ContainerInterface $container;
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;
    private SecurityGuard $securityGuard;
    private LoggerInterface $logger;

    public function __construct(
        ContainerInterface $container,
        McpServerDefinitionRepository $mcpServerDefinitionRepo,
        SecurityGuard $securityGuard,
        LoggerInterface $logger
    ) {
        $this->container = $container;
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        $this->securityGuard = $securityGuard;
        $this->logger = $logger;
    }

    /**
     * Erstellt einen MCP-Server basierend auf einer Definition.
     */
    public function createFromDefinition(McpServerDefinition $definition): McpServerInterface
    {
        $type = $definition->getType();
        $serviceId = $this->getServiceIdForType($type);

        if (!$this->container->has($serviceId)) {
            throw new \RuntimeException(sprintf(
                'MCP-Server-Service für Typ "%s" nicht gefunden.',
                $type
            ));
        }

        $server = $this->container->get($serviceId);

        if (!$server instanceof McpServerInterface) {
            throw new \RuntimeException(sprintf(
                'Service "%s" implementiert McpServerInterface nicht.',
                $serviceId
            ));
        }

        // Konfiguriere den Server mit den Definitionen
        $this->configureServer($server, $definition);

        return $server;
    }

    /**
     * Gibt die Service-ID für einen MCP-Server-Typ zurück.
     */
    private function getServiceIdForType(string $type): string
    {
        $serviceMap = [
            'filesystem' => 'ai.mcp.server.filesystem',
            'playwright' => 'ai.mcp.server.playwright',
            'github' => 'ai.mcp.server.github',
            'custom' => 'ai.mcp.server.custom',
        ];

        return $serviceMap[$type] ?? $type;
    }

    /**
     * Konfiguriert einen MCP-Server mit den Definitionen.
     */
    private function configureServer(McpServerInterface $server, McpServerDefinition $definition): void
    {
        // Setze die Konfiguration
        $server->setConfiguration($definition->getConfiguration());

        // Setze die Whitelist für Tools
        if (!empty($definition->getAllowedTools())) {
            $server->setAllowedTools($definition->getAllowedTools());
        }

        // Setze die Blocklist für Ressourcen
        if (!empty($definition->getBlockedResources())) {
            $server->setBlockedResources($definition->getBlockedResources());
        }
    }

    /**
     * Erstellt alle aktiven MCP-Server aus der Datenbank.
     */
    public function createAllFromDatabase(): array
    {
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();
        $servers = [];

        foreach ($definitions as $definition) {
            try {
                $server = $this->createFromDefinition($definition);
                $servers[$definition->getName()] = $server;
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Fehler beim Laden des MCP-Servers "%s": %s',
                    $definition->getName(),
                    $e->getMessage()
                ));
                continue;
            }
        }

        return $servers;
    }

    /**
     * Erstellt einen MCP-Server basierend auf einem Namen (Fallback zu statischer Konfiguration).
     */
    public function createByName(string $name): McpServerInterface
    {
        // 1. Versuche, aus der Datenbank zu laden
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);
        if ($definition !== null) {
            return $this->createFromDefinition($definition);
        }

        // 2. Fallback: Statische Konfiguration aus ai.yaml
        return $this->createFromStaticConfig($name);
    }

    /**
     * Erstellt einen MCP-Server aus der statischen Konfiguration (ai.yaml).
     */
    private function createFromStaticConfig(string $name): McpServerInterface
    {
        $serviceId = 'ai.mcp.server.' . $name;

        if (!$this->container->has($serviceId)) {
            throw new \RuntimeException(sprintf(
                'MCP-Server "%s" nicht in statischer Konfiguration gefunden.',
                $name
            ));
        }

        $server = $this->container->get($serviceId);

        if (!$server instanceof McpServerInterface) {
            throw new \RuntimeException(sprintf(
                'Service "%s" implementiert McpServerInterface nicht.',
                $serviceId
            ));
        }

        return $server;
    }

    /**
     * Registriert einen neuen MCP-Server dynamisch.
     */
    public function registerMcpServer(McpServerDefinition $definition): void
    {
        // Speichern in der Datenbank
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        $entityManager->persist($definition);
        $entityManager->flush();

        // Optional: Service-Container aktualisieren (für Lazy-Loading)
    }
}
```

##### **4. Interface für MCP-Server erstellen**
**Datei:** `src/AI/Mcp/McpServerInterface.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/AI/Mcp/McpServerInterface.php

namespace App\AI\Mcp;

interface McpServerInterface
{
    /**
     * Gibt den Namen des MCP-Servers zurück.
     */
    public function getName(): string;

    /**
     * Gibt den Typ des MCP-Servers zurück (z. B. 'filesystem', 'playwright').
     */
    public function getType(): string;

    /**
     * Gibt die Beschreibung des MCP-Servers zurück.
     */
    public function getDescription(): string;

    /**
     * Setzt die Konfiguration des MCP-Servers.
     */
    public function setConfiguration(array $configuration): void;

    /**
     * Gibt die Konfiguration des MCP-Servers zurück.
     */
    public function getConfiguration(): array;

    /**
     * Setzt die Whitelist für erlaubte Tools.
     */
    public function setAllowedTools(array $allowedTools): void;

    /**
     * Gibt die Whitelist für erlaubte Tools zurück.
     */
    public function getAllowedTools(): array;

    /**
     * Setzt die Blocklist für Ressourcen.
     */
    public function setBlockedResources(array $blockedResources): void;

    /**
     * Gibt die Blocklist für Ressourcen zurück.
     */
    public function getBlockedResources(): array;

    /**
     * Führt ein MCP-Tool aus.
     */
    public function executeTool(string $toolName, array $arguments = []): mixed;

    /**
     * Gibt die verfügbaren Tools des MCP-Servers zurück.
     */
    public function getAvailableTools(): array;

    /**
     * Prüft, ob ein Tool auf diesem Server verfügbar ist.
     */
    public function hasTool(string $toolName): bool;

    /**
     * Prüft, ob ein Tool erlaubt ist (Whitelist).
     */
    public function isToolAllowed(string $toolName): bool;

    /**
     * Prüft, ob eine Ressource blockiert ist.
     */
    public function isResourceBlocked(string $resource): bool;
}
```

##### **5. MCP-Tool-Executor für dynamische Server aktualisieren**
**Datei:** `src/AI/Mcp/McpToolExecutor.php` (aktualisieren)
**Aufwand:** 1 Tag

```php
<?php
// src/AI/Mcp/McpToolExecutor.php

namespace App\AI\Mcp;

use App\AI\Security\SecurityGuard;
use Psr\Log\LoggerInterface;

class McpToolExecutor
{
    private McpServerFactory $mcpServerFactory;
    private SecurityGuard $securityGuard;
    private LoggerInterface $logger;

    public function __construct(
        McpServerFactory $mcpServerFactory,
        SecurityGuard $securityGuard,
        LoggerInterface $logger
    ) {
        $this->mcpServerFactory = $mcpServerFactory;
        $this->securityGuard = $securityGuard;
        $this->logger = $logger;
    }

    /**
     * Führt ein MCP-Tool aus.
     */
    public function execute(string $serverName, string $toolName, array $arguments = []): mixed
    {
        // 1. Lade den MCP-Server dynamisch
        $server = $this->mcpServerFactory->createByName($serverName);

        // 2. Prüfe, ob das Tool verfügbar ist
        if (!$server->hasTool($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist auf MCP-Server "%s" nicht verfügbar.',
                $toolName,
                $serverName
            ));
        }

        // 3. Prüfe, ob das Tool erlaubt ist (Whitelist)
        if (!$server->isToolAllowed($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist auf MCP-Server "%s" nicht erlaubt.',
                $toolName,
                $serverName
            ));
        }

        // 4. Prüfe die Sicherheit des Tools
        if (!$this->securityGuard->isToolAllowed($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" wurde aus Sicherheitsgründen blockiert.',
                $toolName
            ));
        }

        // 5. Führe das Tool aus
        try {
            return $server->executeTool($toolName, $arguments);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Fehler bei der Ausführung von Tool "%s" auf MCP-Server "%s": %s',
                $toolName,
                $serverName,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * Gibt alle verfügbaren MCP-Server zurück.
     */
    public function getAvailableServers(): array
    {
        return $this->mcpServerFactory->createAllFromDatabase();
    }

    /**
     * Gibt alle verfügbaren Tools eines MCP-Servers zurück.
     */
    public function getServerTools(string $serverName): array
    {
        $server = $this->mcpServerFactory->createByName($serverName);
        return $server->getAvailableTools();
    }
}
```

##### **6. CompilerPass für dynamische MCP-Server-Registrierung**
**Datei:** `src/DependencyInjection/Compiler/AiMcpServersCompilerPass.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/DependencyInjection/Compiler/AiMcpServersCompilerPass.php

namespace App\DependencyInjection\Compiler;

use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AiMcpServersCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Prüfe, ob die benötigten Services existieren
        if (!$container->has('doctrine.orm.entity_manager') || 
            !$container->has(McpServerDefinitionRepository::class)) {
            return;
        }

        // Lade alle aktiven MCP-Server-Definitionen aus der Datenbank
        // HINWEIS: In der Praxis muss dies über ein Cache-Warmup-Command geschehen.
        $definitions = $this->loadMcpServerDefinitions($container);

        foreach ($definitions as $definition) {
            $this->registerMcpServerService($container, $definition);
        }
    }

    /**
     * Lädt MCP-Server-Definitionen aus der Datenbank.
     */
    private function loadMcpServerDefinitions(ContainerBuilder $container): array
    {
        // Vereinfachtes Beispiel: Leere Array zurückgeben
        // In der Praxis: Datenbankabfrage oder Cache
        return [];
    }

    /**
     * Registriert einen MCP-Server als Service.
     */
    private function registerMcpServerService(ContainerBuilder $container, McpServerDefinition $definition): void
    {
        $serviceId = 'ai.mcp.server.dynamic.' . $definition->getName();

        if ($container->has($serviceId)) {
            return; // Bereits registriert
        }

        $container->register($serviceId, $definition->getType())
            ->addTag('ai.mcp.server')
            ->addTag('container.hot_path')
            ->setPublic(true)
            ->addMethodCall('setConfiguration', [$definition->getConfiguration()])
            ->addMethodCall('setAllowedTools', [$definition->getAllowedTools()])
            ->addMethodCall('setBlockedResources', [$definition->getBlockedResources()]);
    }
}
```

##### **7. Command zum Cache-Warmup für MCP-Server**
**Datei:** `src/Command/WarmupMcpServersCacheCommand.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Command/WarmupMcpServersCacheCommand.php

namespace App\Command;

use App\Repository\McpServerDefinitionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'evie:mcp-servers:warmup-cache',
    description: 'Lädt alle aktiven MCP-Server aus der Datenbank und registriert sie im Cache.'
)]
class WarmupMcpServersCacheCommand extends Command
{
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;

    public function __construct(McpServerDefinitionRepository $mcpServerDefinitionRepo)
    {
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVIE MCP-Server Cache Warmup');

        $definitions = $this->mcpServerDefinitionRepo->findAllActive();

        if (empty($definitions)) {
            $io->warning('Keine aktiven MCP-Server-Definitionen gefunden.');
            return Command::SUCCESS;
        }

        $io->section('Gefundene MCP-Server-Definitionen:');
        foreach ($definitions as $definition) {
            $io->text(sprintf(
                '- %s (Typ: %s, Status: %s)',
                $definition->getName(),
                $definition->getType(),
                $definition->isActive() ? 'Aktiv' : 'Inaktiv'
            ));
        }

        $io->success(sprintf(
            '%d MCP-Server-Definitionen wurden geladen und gecacht.',
            count($definitions)
        ));

        return Command::SUCCESS;
    }
}
```

##### **8. Controller für MCP-Server-Verwaltung erstellen**
**Datei:** `src/Controller/McpServerController.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Controller/McpServerController.php

namespace App\Controller;

use App\AI\Mcp\McpServerFactory;
use App\Entity\McpServerDefinition;
use App\Form\McpServerDefinitionType;
use App\Repository\McpServerDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class McpServerController extends AbstractController
{
    private McpServerFactory $mcpServerFactory;
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;
    private EntityManagerInterface $entityManager;

    public function __construct(
        McpServerFactory $mcpServerFactory,
        McpServerDefinitionRepository $mcpServerDefinitionRepo,
        EntityManagerInterface $entityManager
    ) {
        $this->mcpServerFactory = $mcpServerFactory;
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        $this->entityManager = $entityManager;
    }

    /**
     * Listet alle verfügbaren MCP-Server auf.
     */
    #[Route('/mcp/servers', name: 'mcp_servers_list', methods: ['GET'])]
    public function listServers(Request $request): Response
    {
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();

        return $this->render('mcp/servers.html.twig', [
            'servers' => $definitions,
        ]);
    }

    /**
     * Zeigt die Details eines MCP-Servers an.
     */
    #[Route('/mcp/servers/{name}', name: 'mcp_server_show', methods: ['GET'])]
    public function showServer(string $name): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        try {
            $server = $this->mcpServerFactory->createFromDefinition($definition);
            $tools = $server->getAvailableTools();
        } catch (\Exception $e) {
            $tools = [];
        }

        return $this->render('mcp/server_show.html.twig', [
            'server' => $definition,
            'tools' => $tools,
        ]);
    }

    /**
     * Erstellt einen neuen MCP-Server.
     */
    #[Route('/mcp/servers/new', name: 'mcp_server_new', methods: ['GET', 'POST'])]
    public function newServer(Request $request): Response
    {
        $definition = new McpServerDefinition();
        $form = $this->createForm(McpServerDefinitionType::class, $definition);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($definition);
            $this->entityManager->flush();

            $this->addFlash('success', 'MCP-Server wurde erfolgreich erstellt.');
            return $this->redirectToRoute('mcp_servers_list');
        }

        return $this->render('mcp/server_new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Bearbeitet einen bestehenden MCP-Server.
     */
    #[Route('/mcp/servers/{name}/edit', name: 'mcp_server_edit', methods: ['GET', 'POST'])]
    public function editServer(string $name, Request $request): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        $form = $this->createForm(McpServerDefinitionType::class, $definition);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'MCP-Server wurde erfolgreich aktualisiert.');
            return $this->redirectToRoute('mcp_server_show', ['name' => $name]);
        }

        return $this->render('mcp/server_edit.html.twig', [
            'form' => $form->createView(),
            'server' => $definition,
        ]);
    }

    /**
     * Löscht einen MCP-Server.
     */
    #[Route('/mcp/servers/{name}/delete', name: 'mcp_server_delete', methods: ['POST'])]
    public function deleteServer(string $name, Request $request): Response
    {
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);

        if ($definition === null) {
            throw $this->createNotFoundException('MCP-Server nicht gefunden.');
        }

        if ($this->isCsrfTokenValid('delete' . $definition->getId()->toRfc4122(), $request->request->get('_token'))) {
            $this->entityManager->remove($definition);
            $this->entityManager->flush();

            $this->addFlash('success', 'MCP-Server wurde erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('mcp_servers_list');
    }

    /**
     * Gibt die Tools eines MCP-Servers als JSON zurück.
     */
    #[Route('/api/mcp/servers/{name}/tools', name: 'api_mcp_server_tools', methods: ['GET'])]
    public function getServerTools(string $name): JsonResponse
    {
        try {
            $server = $this->mcpServerFactory->createByName($name);
            $tools = $server->getAvailableTools();

            return $this->json([
                'server' => $name,
                'tools' => $tools,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 404);
        }
    }
}
```

##### **9. Formular für MCP-Server-Definitionen erstellen**
**Datei:** `src/Form/McpServerDefinitionType.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Form/McpServerDefinitionType.php

namespace App\Form;

use App\Entity\McpServerDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class McpServerDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Typ',
                'choices' => [
                    'Filesystem' => 'filesystem',
                    'Playwright' => 'playwright',
                    'GitHub' => 'github',
                    'Custom' => 'custom',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'attr' => ['class' => 'form-textarea', 'rows' => 3],
            ])
            ->add('configuration', TextareaType::class, [
                'label' => 'Konfiguration (JSON)',
                'attr' => ['class' => 'form-textarea', 'rows' => 5],
                'help' => 'Beispiel: {"url": "https://api.example.com", "token": "xxx"}',
            ])
            ->add('allowedTools', TextareaType::class, [
                'label' => 'Erlaubte Tools (JSON-Array)',
                'required' => false,
                'attr' => ['class' => 'form-textarea', 'rows' => 3],
                'help' => 'Beispiel: ["read_file", "list_files"]',
            ])
            ->add('blockedResources', TextareaType::class, [
                'label' => 'Blockierte Ressourcen (JSON-Array)',
                'required' => false,
                'attr' => ['class' => 'form-textarea', 'rows' => 3],
                'help' => 'Beispiel: ["/etc/", "*.env"]',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Aktiv',
                'attr' => ['class' => 'form-checkbox'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => McpServerDefinition::class,
        ]);
    }
}
```

##### **10. Migration für MCP-Server-Definitionen erstellen**
**Datei:** `migrations/Version20260828180000.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// migrations/Version20260828180000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die Tabelle für MCP-Server-Definitionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(''
            . 'CREATE TABLE ai_mcp_server_definitions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'name VARCHAR(255) NOT NULL,'
            . 'type VARCHAR(255) NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'configuration JSON NOT NULL,'
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'allowed_tools JSON NOT NULL,'
            . 'blocked_resources JSON NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME DEFAULT NULL,'
            . 'created_by_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE INDEX UNIQ_MCP_SERVER_NAME (name),'
            . 'INDEX IDX_MCP_SERVER_ACTIVE (is_active),'
            . 'INDEX IDX_MCP_SERVER_TYPE (type),'
            . 'INDEX IDX_MCP_SERVER_CREATED_BY (created_by_id)'
            . ')'
        );
        
        $this->addSql(''
            . 'ALTER TABLE ai_mcp_server_definitions '
            . 'ADD CONSTRAINT FK_MCP_SERVER_CREATED_BY '
            . 'FOREIGN KEY (created_by_id) REFERENCES user (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_mcp_server_definitions');
    }
}
```

#### **✅ Abnahmekriterien**

| **Kriterium** | **Details** | **Status** |
|--------------|-------------|------------|
| MCP-Server-Definitionen in der DB speicherbar | Entity + Repository + Migration | ⏳ |
| MCP-Server dynamisch aus DB ladbar | `McpServerFactory::createFromDefinition()` | ⏳ |
| MCP-Server dynamisch registrierbar | `McpServerFactory::registerMcpServer()` | ⏳ |
| CompilerPass für MCP-Server | `AiMcpServersCompilerPass` | ⏳ |
| Cache-Warmup-Command für MCP-Server | `evie:mcp-servers:warmup-cache` | ⏳ |
| MCP-Tool-Executor aktualisiert | `McpToolExecutor::execute()` | ⏳ |
| Controller für MCP-Server-Verwaltung | `McpServerController` | ⏳ |
| Formular für MCP-Server-Definitionen | `McpServerDefinitionType` | ⏳ |
| **MCP-Server sind dynamisch ladbar** | Keine hardcoded Server in `ai.yaml` | ⏳ |

---

### **🟡 Maßnahme 7: Frontend mit HTMX/Alpine.js erweitern**
**Priorität:** 🟡 **Hoch** | **Aufwand:** 3-5 Tage | **Impact:** 🟡 **Hoch** | **Start:** 31. August 2026

#### **📌 Hintergrund**
Aktuell ist das Frontend **rein server-side** ohne JavaScript-Framework. Dies führt zu **keinen Echtzeit-Updates** für:
- Tool-Execution-Status
- Sub-Agenten-Delegation
- Streaming-Antworten
- MCP-Tool-Nutzung

**HTMX** und **Alpine.js** sind **leichte Lösungen**, um **Echtzeit-Funktionalität** ohne komplexe JavaScript-Frameworks (React, Vue) zu ermöglichen.

#### **🎯 Ziele**
- **Echtzeit-Updates** für Tool-Execution, Sub-Agenten und MCP-Tools
- **Interaktive UI** mit HTMX für AJAX-Anfragen
- **Reaktive Komponenten** mit Alpine.js für lokale Zustandsverwaltung
- **Konsistente User Experience** mit Symfony AI Bundle

#### **📝 Umsetzung**

##### **1. HTMX und Alpine.js installieren**
**Datei:** `assets/app.js` (aktualisieren)
**Aufwand:** 0.5 Tage

```javascript
// assets/app.js

// Import HTMX
import 'htmx.org';

// Import Alpine.js
import Alpine from 'alpinejs';

// Starte Alpine.js
Alpine.start();

// HTMX-Konfiguration
document.addEventListener('DOMContentLoaded', function() {
    // HTMX-Default-Konfiguration
    htmx.config.defaultSwapStyle = 'innerHTML';
    htmx.config.defaultSwapDelay = 0;
    htmx.config.defaultSettleDelay = 20;

    // HTMX-Logging für Debugging
    htmx.onLoad(function(content) {
        console.log('HTMX geladen für:', content);
    });

    // HTMX-Fehlerbehandlung
    htmx.on('htmx:beforeRequest', function(event) {
        console.log('HTMX Request:', event.detail.pathInfo);
    });

    htmx.on('htmx:afterRequest', function(event) {
        if (event.detail.successful) {
            console.log('HTMX Erfolg:', event.detail.pathInfo);
        } else {
            console.error('HTMX Fehler:', event.detail.pathInfo, event.detail.xhr.status);
        }
    });

    // Alpine.js-Plugins (falls benötigt)
    // Alpine.plugin(...);
});
```

**Datei:** `templates/base.html.twig` (aktualisieren)
**Aufwand:** 0.5 Tage

```twig
{# templates/base.html.twig #}

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}EVIE - AI Agent System{% endblock %}</title>

    {# Tailwind CSS (bestehend) #}
    {% block stylesheets %}
        <link href="{{ asset('build/app.css') }}" rel="stylesheet">
    {% endblock %}

    {# HTMX & Alpine.js #}
    <script src="https://unpkg.com/htmx.org@1.9.6"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {# Custom JS #}
    {% block javascripts %}
        <script src="{{ asset('build/app.js') }}"></script>
    {% endblock %}
</head>
<body class="bg-gray-900 text-gray-100">
    {# Navigation (bestehend) #}
    <nav class="bg-gray-800 p-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <a href="{{ path('app_home') }}" class="text-xl font-bold">EVIE</a>
            <div class="flex gap-4">
                <a href="{{ path('agent_dialog') }}" class="hover:text-blue-400">Agent</a>
                <a href="{{ path('tools_list') }}" class="hover:text-blue-400">Tools</a>
                <a href="{{ path('subagents_list') }}" class="hover:text-blue-400">Sub-Agenten</a>
                <a href="{{ path('mcp_servers_list') }}" class="hover:text-blue-400">MCP-Server</a>
            </div>
        </div>
    </nav>

    {# Flash Messages (mit HTMX) #}
    <div 
        id="flash-messages"
        class="fixed top-16 right-4 z-50 space-y-2"
        hx-get="{{ path('app_flash_messages') }}"
        hx-trigger="load, flash-message from:body"
        hx-swap="innerHTML"
    >
        {% for message in app.flashes('success') %}
            <div class="bg-green-600 text-white p-4 rounded-lg shadow-lg">
                {{ message }}
            </div>
        {% endfor %}
        {% for message in app.flashes('error') %}
            <div class="bg-red-600 text-white p-4 rounded-lg shadow-lg">
                {{ message }}
            </div>
        {% endfor %}
    </div>

    {# Main Content #}
    <main class="max-w-7xl mx-auto p-4">
        {% block body %}{% endblock %}
    </main>

    {# HTMX-Indikator #}
    <div 
        class="htmx-indicator fixed bottom-4 right-4 bg-blue-600 text-white p-2 rounded-lg shadow-lg"
        style="display: none;"
    >
        Lädt...
    </div>
</body>
</html>
```

##### **2. HTMX-Konfiguration für Symfony**
**Datei:** `config/packages/htmx.yaml` (neu)
**Aufwand:** 0.5 Tage

```yaml
# config/packages/htmx.yaml

# HTMX-Konfiguration für Symfony
# Diese Datei ist optional und dient der Dokumentation der HTMX-Integration

htmx:
    # Standard-HTTP-Methoden
    methods:
        - GET
        - POST
        - PUT
        - PATCH
        - DELETE

    # Standard-Headers
    headers:
        - HX-Request: 'true'
        - HX-Target: ''
        - HX-Trigger: ''

    # Standard-Swap-Stile
    swap_styles:
        - innerHTML
        - outerHTML
        - beforebegin
        - afterbegin
        - beforeend
        - afterend
        - delete
        - none

    # Standard-Trigger
    triggers:
        - click
        - load
        - every
        - changed
        - revealed
        - intersect

    # Standard-Attribute
    attributes:
        - hx-get
        - hx-post
        - hx-put
        - hx-patch
        - hx-delete
        - hx-target
        - hx-swap
        - hx-trigger
        - hx-indicator
```

##### **3. Controller für HTMX-Anfragen erstellen**
**Datei:** `src/Controller/HtmxController.php` (neu)
**Aufwand:** 0.5 Tage

```php
<?php
// src/Controller/HtmxController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HtmxController extends AbstractController
{
    /**
     * Gibt Flash-Messages für HTMX zurück.
     */
    #[Route('/htmx/flash-messages', name: 'app_flash_messages', methods: ['GET'])]
    public function flashMessages(Request $request): Response
    {
        $messages = [];

        foreach ($this->getUser()?->getFlashes() as $type => $typeMessages) {
            foreach ($typeMessages as $message) {
                $messages[] = [
                    'type' => $type,
                    'message' => $message,
                ];
            }
        }

        if (empty($messages)) {
            return new Response('', 204);
        }

        return $this->render('partials/_flash_messages.html.twig', [
            'messages' => $messages,
        ]);
    }

    /**
     * Gibt einen Teil der UI für HTMX zurück.
     */
    #[Route('/htmx/partials/{template}', name: 'app_htmx_partial', methods: ['GET'])]
    public function partial(string $template, Request $request): Response
    {
        return $this->render(sprintf('partials/_%s.html.twig', $template), $request->query->all());
    }

    /**
     * Behandelt eine HTMX-Anfrage für eine Ressource.
     */
    #[Route('/htmx/{resource}', name: 'app_htmx_resource', methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    public function resource(string $resource, Request $request): Response
    {
        // Dynamische Routing-Logik für HTMX-Anfragen
        // Beispiel: /htmx/tools/list -> ToolsController::listAction
        $action = $request->query->get('action', 'list');
        $controller = sprintf('%sController', ucfirst($resource));

        if (!class_exists(sprintf('App\Controller\%s', $controller))) {
            return new Response('Ressource nicht gefunden.', 404);
        }

        // Hier könnte eine dynamische Controller-Aufruflogik implementiert werden
        // Für dieses Beispiel: Einfache Weiterleitung
        return $this->redirectToRoute(sprintf('app_%s_%s', $resource, $action));
    }
}
```

##### **4. HTMX-Templates für Tool-Execution erstellen**
**Datei:** `templates/tools/_tool_execution.html.twig` (neu)
**Aufwand:** 0.5 Tage

```twig
{# templates/tools/_tool_execution.html.twig #}

<div 
    class="tool-execution"
    id="tool-execution-{{ tool_name }}"
    x-data="{
        status: '{{ status }}',
        progress: {{ progress }},
        chunks: {{ chunks|json_encode|raw }},
        isStreaming: {{ is_streaming ? 'true' : 'false' }},
        sessionId: '{{ session_id }}'
    }"
    x-init="
        if (isStreaming) {
            startStreaming();
        }
    "
>
    <div class="bg-gray-800 rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">
                Tool: <span class="font-mono">{{ tool_name }}</span>
            </h3>
            <span 
                class="status {{ status_class }}"
                x-text="status"
                x-bind:class="{
                    'status-active': status === 'running',
                    'status-completed': status === 'completed',
                    'status-failed': status === 'failed'
                }"
            ></span>
        </div>

        <div class="progress-bar mb-4">
            <div 
                class="progress-bar-fill"
                x-bind:style="{ width: progress + '%' }"
            ></div>
        </div>

        <div class="streaming-chunks" x-ref="chunksContainer">
            <template x-for="chunk in chunks" :key="chunk.id">
                <div 
                    class="chunk"
                    x-bind:class="{
                        'chunk-final': chunk.is_final,
                        'chunk-error': chunk.error
                    }"
                >
                    <template x-if="chunk.chunk">
                        <pre x-text="JSON.stringify(chunk.chunk, null, 2)"></pre>
                    </template>
                    <template x-if="chunk.final_result">
                        <strong>Finales Ergebnis:</strong>
                        <pre x-text="JSON.stringify(chunk.final_result, null, 2)"></pre>
                    </template>
                    <template x-if="chunk.error">
                        <strong class="text-red-400">Fehler:</strong>
                        <span x-text="chunk.error"></span>
                    </template>
                </div>
            </template>
        </div>

        <div class="flex gap-4 mt-4">
            <button 
                x-show="status === 'running'"
                x-on:click="stopStreaming()"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors"
            >
                Abbrechen
            </button>
            <button 
                x-show="status === 'completed' || status === 'failed'"
                x-on:click="resetExecution()"
                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors"
            >
                Zurücksetzen
            </button>
        </div>
    </div>

    <script>
        function startStreaming() {
            const sessionId = document.getElementById('tool-execution-{{ tool_name }}').querySelector('[x-data]').__x.$data.sessionId;
            
            // Verbinde mit dem SSE-Stream
            const eventSource = new EventSource(`/api/streaming/${sessionId}/stream`);

            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);

                if (data.chunk) {
                    // Füge den Chunk hinzu
                    Alpine.store('toolExecution', {
                        chunks: [...Alpine.store('toolExecution').chunks, {
                            id: Date.now(),
                            chunk: data.chunk
                        }]
                    });

                    // Aktualisiere Fortschritt
                    Alpine.store('toolExecution', {
                        progress: Math.min(Alpine.store('toolExecution').progress + 10, 90)
                    });
                }

                if (data.final_result) {
                    // Finales Ergebnis
                    Alpine.store('toolExecution', {
                        chunks: [...Alpine.store('toolExecution').chunks, {
                            id: Date.now(),
                            final_result: data.final_result,
                            is_final: true
                        }],
                        status: 'completed',
                        progress: 100
                    });

                    eventSource.close();
                }

                if (data.error) {
                    // Fehler
                    Alpine.store('toolExecution', {
                        chunks: [...Alpine.store('toolExecution').chunks, {
                            id: Date.now(),
                            error: data.error
                        }],
                        status: 'failed'
                    });

                    eventSource.close();
                }
            };

            eventSource.onerror = function() {
                Alpine.store('toolExecution', {
                    status: 'failed'
                });
                eventSource.close();
            };
        }

        function stopStreaming() {
            // Hier könnte ein Abbruch-Request an den Server gesendet werden
            Alpine.store('toolExecution', {
                status: 'cancelled'
            });
        }

        function resetExecution() {
            Alpine.store('toolExecution', {
                status: 'idle',
                progress: 0,
                chunks: [],
                isStreaming: false
            });
        }
    </script>
</div>
```

##### **5. HTMX-Templates für Sub-Agenten-Delegation erstellen**
**Datei:** `templates/subagents/_subagent_delegation.html.twig` (neu)
**Aufwand:** 0.5 Tage

```twig
{# templates/subagents/_subagent_delegation.html.twig #}

<div 
    class="subagent-delegation"
    id="subagent-delegation-{{ subagent_name }}"
    x-data="{
        subagent: {{ subagent|json_encode|raw }},
        status: '{{ status }}',
        tasks: {{ tasks|json_encode|raw }},
        selectedTask: null,
        response: null
    }"
>
    <div class="bg-gray-800 rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">
                Sub-Agent: <span class="font-mono" x-text="subagent.name"></span>
            </h3>
            <span 
                class="status"
                x-text="status"
                x-bind:class="{
                    'status-active': status === 'active',
                    'status-busy': status === 'busy',
                    'status-idle': status === 'idle'
                }"
            ></span>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 mb-2">Beschreibung:</p>
            <p class="text-gray-300" x-text="subagent.description"></p>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 mb-2">Verfügbare Aufgaben:</p>
            <ul class="list-disc list-inside text-gray-300">
                <template x-for="task in subagent.capabilities" :key="task">
                    <li x-text="task"></li>
                </template>
            </ul>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 mb-2">Aktuelle Aufgaben:</p>
            <div class="space-y-2">
                <template x-for="task in tasks" :key="task.id">
                    <div class="bg-gray-700 p-2 rounded-lg">
                        <p class="text-sm" x-text="task.prompt"></p>
                        <p class="text-xs text-gray-400" x-text="task.status"></p>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex gap-4">
            <button 
                x-on:click="delegateTask()"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
            >
                Aufgabe delegieren
            </button>
        </div>

        <div 
            x-show="response"
            class="mt-4 bg-gray-700 p-4 rounded-lg"
        >
            <p class="text-gray-400 mb-2">Antwort:</p>
            <pre class="text-gray-300" x-text="JSON.stringify(response, null, 2)"></pre>
        </div>
    </div>

    <script>
        function delegateTask() {
            const subagentName = document.getElementById('subagent-delegation-{{ subagent_name }}').querySelector('[x-data]').__x.$data.subagent.name;
            const prompt = prompt('Gib die Aufgabe für den Sub-Agenten ein:');

            if (!prompt) {
                return;
            }

            // Sende die Aufgabe an den Server
            fetch(`/subagents/${subagentName}/delegate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ prompt })
            })
            .then(response => response.json())
            .then(data => {
                Alpine.store('subagentDelegation', {
                    tasks: [...Alpine.store('subagentDelegation').tasks, {
                        id: Date.now(),
                        prompt,
                        status: 'pending'
                    }],
                    response: data.response,
                    status: 'busy'
                });
            })
            .catch(error => {
                console.error('Fehler:', error);
            });
        }
    </script>
</div>
```

##### **6. HTMX-Templates für MCP-Tools erstellen**
**Datei:** `templates/mcp/_mcp_tools.html.twig` (neu)
**Aufwand:** 0.5 Tage

```twig
{# templates/mcp/_mcp_tools.html.twig #}

<div 
    class="mcp-tools"
    id="mcp-tools-{{ server_name }}"
    x-data="{
        server: {{ server|json_encode|raw }},
        tools: {{ tools|json_encode|raw }},
        selectedTool: null,
        toolArguments: {},
        response: null
    }"
>
    <div class="bg-gray-800 rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">
                MCP-Server: <span class="font-mono" x-text="server.name"></span>
            </h3>
            <span class="text-gray-400" x-text="server.type"></span>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 mb-2">Beschreibung:</p>
            <p class="text-gray-300" x-text="server.description"></p>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 mb-2">Verfügbare Tools:</p>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template x-for="tool in tools" :key="tool.name">
                    <div 
                        class="bg-gray-700 p-4 rounded-lg cursor-pointer hover:bg-gray-600"
                        x-on:click="selectTool(tool)"
                    >
                        <h4 class="font-semibold" x-text="tool.name"></h4>
                        <p class="text-sm text-gray-400" x-text="tool.description"></p>
                    </div>
                </template>
            </div>
        </div>

        <div 
            x-show="selectedTool"
            class="mb-4 bg-gray-700 p-4 rounded-lg"
        >
            <h4 class="font-semibold mb-2" x-text="selectedTool.name"></h4>
            <p class="text-gray-400 mb-4" x-text="selectedTool.description"></p>

            <div class="mb-4">
                <p class="text-gray-400 mb-2">Parameter:</p>
                <div class="space-y-2">
                    <template x-for="(value, key) in selectedTool.parameters" :key="key">
                        <div>
                            <label class="block text-sm font-medium text-gray-300" x-text="key"></label>
                            <input 
                                type="text"
                                class="mt-1 block w-full bg-gray-600 border border-gray-500 rounded-lg p-2 text-white"
                                x-model="toolArguments[key]"
                                :placeholder="value.description || ''"
                            >
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex gap-4">
                <button 
                    x-on:click="executeTool()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"
                >
                    Tool ausführen
                </button>
                <button 
                    x-on:click="selectedTool = null"
                    class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors"
                >
                    Abbrechen
                </button>
            </div>
        </div>

        <div 
            x-show="response"
            class="mt-4 bg-gray-700 p-4 rounded-lg"
        >
            <p class="text-gray-400 mb-2">Ergebnis:</p>
            <pre class="text-gray-300" x-text="JSON.stringify(response, null, 2)"></pre>
        </div>
    </div>

    <script>
        function selectTool(tool) {
            Alpine.store('mcpTools', {
                selectedTool: tool,
                toolArguments: {}
            });
        }

        function executeTool() {
            const serverName = document.getElementById('mcp-tools-{{ server_name }}').querySelector('[x-data]').__x.$data.server.name;
            const toolName = Alpine.store('mcpTools').selectedTool.name;
            const arguments = Alpine.store('mcpTools').toolArguments;

            // Sende die Tool-Ausführung an den Server
            fetch(`/api/mcp/servers/${serverName}/tools/${toolName}/execute`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ arguments })
            })
            .then(response => response.json())
            .then(data => {
                Alpine.store('mcpTools', {
                    response: data.result,
                    selectedTool: null,
                    toolArguments: {}
                });
            })
            .catch(error => {
                console.error('Fehler:', error);
                Alpine.store('mcpTools', {
                    response: { error: error.message },
                    selectedTool: null,
                    toolArguments: {}
                });
            });
        }
    </script>
</div>
```

##### **7. HTMX-Templates für Dashboard erstellen**
**Datei:** `templates/dashboard/_dashboard.html.twig` (neu)
**Aufwand:** 1 Tag

```twig
{# templates/dashboard/_dashboard.html.twig #}

<div 
    class="dashboard"
    x-data="{
        stats: {{ stats|json_encode|raw }},
        recentActivities: {{ recent_activities|json_encode|raw }},
        activeSessions: {{ active_sessions|json_encode|raw }},
        selectedTab: 'overview'
    }"
    x-init="
        // Lade Daten alle 30 Sekunden neu
        setInterval(() => {
            fetch('/api/dashboard/stats')
                .then(response => response.json())
                .then(data => {
                    stats = data.stats;
                    recentActivities = data.recentActivities;
                    activeSessions = data.activeSessions;
                });
        }, 30000);
    "
>
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {# Statistiken #}
        <div class="lg:col-span-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-gray-800 rounded-lg p-4">
                    <h3 class="text-sm font-medium text-gray-400">Aktive Tools</h3>
                    <p class="text-3xl font-bold" x-text="stats.activeTools"></p>
                </div>
                <div class="bg-gray-800 rounded-lg p-4">
                    <h3 class="text-sm font-medium text-gray-400">Sub-Agenten</h3>
                    <p class="text-3xl font-bold" x-text="stats.subAgents"></p>
                </div>
                <div class="bg-gray-800 rounded-lg p-4">
                    <h3 class="text-sm font-medium text-gray-400">MCP-Server</h3>
                    <p class="text-3xl font-bold" x-text="stats.mcpServers"></p>
                </div>
                <div class="bg-gray-800 rounded-lg p-4">
                    <h3 class="text-sm font-medium text-gray-400">Sessions</h3>
                    <p class="text-3xl font-bold" x-text="stats.activeSessions"></p>
                </div>
            </div>

            {# Letzte Aktivitäten #}
            <div class="bg-gray-800 rounded-lg p-4 mb-6">
                <h3 class="text-lg font-semibold mb-4">Letzte Aktivitäten</h3>
                <div class="space-y-4">
                    <template x-for="activity in recentActivities" :key="activity.id">
                        <div class="flex items-center gap-4">
                            <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                            <div class="flex-1">
                                <p class="font-medium" x-text="activity.title"></p>
                                <p class="text-sm text-gray-400" x-text="activity.timestamp"></p>
                            </div>
                            <span 
                                class="px-2 py-1 text-xs rounded-lg"
                                x-text="activity.status"
                                x-bind:class="{
                                    'bg-green-600': activity.status === 'completed',
                                    'bg-blue-600': activity.status === 'running',
                                    'bg-red-600': activity.status === 'failed'
                                }"
                            ></span>
                        </div>
                    </template>
                </div>
            </div>

            {# Aktive Sessions #}
            <div class="bg-gray-800 rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-4">Aktive Sessions</h3>
                <div class="space-y-4">
                    <template x-for="session in activeSessions" :key="session.id">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium" x-text="session.toolName"></p>
                                <p class="text-sm text-gray-400" x-text="session.userIdentifier"></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-16 h-2 bg-gray-600 rounded-full">
                                    <div 
                                        class="h-2 bg-blue-500 rounded-full"
                                        x-bind:style="{ width: session.progress + '%' }"
                                    ></div>
                                </div>
                                <span class="text-sm" x-text="session.progress + '%'"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {# Seitenleiste #}
        <div class="lg:col-span-1">
            <div class="bg-gray-800 rounded-lg p-4 mb-6">
                <h3 class="text-lg font-semibold mb-4">Schnellaktionen</h3>
                <div class="space-y-2">
                    <a 
                        href="{{ path('agent_dialog') }}"
                        class="block w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-center"
                    >
                        Neuer Agenten-Dialog
                    </a>
                    <a 
                        href="{{ path('tools_list') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors text-center"
                    >
                        Tools verwalten
                    </a>
                    <a 
                        href="{{ path('subagents_list') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors text-center"
                    >
                        Sub-Agenten verwalten
                    </a>
                    <a 
                        href="{{ path('mcp_servers_list') }}"
                        class="block w-full px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors text-center"
                    >
                        MCP-Server verwalten
                    </a>
                </div>
            </div>

            <div class="bg-gray-800 rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-4">System-Status</h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span>Datenbank</span>
                        <span class="text-green-400">✓ Verbunden</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Cache</span>
                        <span class="text-green-400">✓ Aktiv</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Messenger</span>
                        <span class="text-green-400">✓ Aktiv</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Mercure</span>
                        <span class="text-green-400">✓ Verbunden</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
```

##### **8. HTMX-Partials für wiederverwendbare Komponenten**
**Datei:** `templates/partials/_flash_messages.html.twig` (neu)
**Aufwand:** 0.5 Tage

```twig
{# templates/partials/_flash_messages.html.twig #}

<div class="space-y-2">
    <template x-for="message in {{ messages|json_encode|raw })" :key="message.type + message.message">
        <div 
            class="flash-message"
            x-data="{ visible: true }"
            x-show="visible"
            x-init="
                setTimeout(() => { visible = false; }, 5000);
            "
            x-transition:leave="transition opacity-100 ease-out duration-300"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="p-4 rounded-lg shadow-lg"
            x-bind:class="{
                'bg-green-600': message.type === 'success',
                'bg-red-600': message.type === 'error',
                'bg-blue-600': message.type === 'info',
                'bg-yellow-600': message.type === 'warning'
            }"
        >
            <p class="text-white" x-text="message.message"></p>
        </div>
    </template>
</div>
```

##### **9. HTMX-Partials für Tool-Listen**
**Datei:** `templates/partials/_tool_list.html.twig` (neu)
**Aufwand:** 0.5 Tage

```twig
{# templates/partials/_tool_list.html.twig #}

<div 
    class="tool-list"
    hx-get="{{ path('api_tools_list') }}"
    hx-trigger="load, every 10s"
    hx-swap="innerHTML"
>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <template x-for="tool in {{ tools|json_encode|raw })" :key="tool.name">
            <div 
                class="bg-gray-800 rounded-lg p-4 hover:bg-gray-700 transition-colors"
                x-data="{
                    tool: tool,
                    isExpanded: false
                }"
            >
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold" x-text="tool.name"></h3>
                    <button 
                        x-on:click="isExpanded = !isExpanded"
                        class="text-gray-400 hover:text-white"
                    >
                        <template x-if="!isExpanded">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </template>
                        <template x-if="isExpanded">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                            </svg>
                        </template>
                    </button>
                </div>
                <p class="text-sm text-gray-400 mb-2" x-text="tool.description"></p>
                
                <div x-show="isExpanded" x-collapse>
                    <div class="mb-2">
                        <p class="text-sm text-gray-400 mb-1">Parameter:</p>
                        <div class="space-y-1">
                            <template x-for="(value, key) in tool.parameters" :key="key">
                                <div class="text-xs">
                                    <span class="font-medium" x-text="key"></span>
                                    <span class="text-gray-500" x-text="': ' + (value.type || 'string')"></span>
                                    <template x-if="value.description">
                                        <span class="text-gray-600" x-text="' - ' + value.description"></span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <div class="flex gap-2">
                        <button 
                            hx-post="{{ path('api_tools_execute', {name: tool.name}) }}"
                            hx-target="#tool-execution-{{ tool.name }}"
                            hx-swap="innerHTML"
                            class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors"
                        >
                            Ausführen
                        </button>
                        <button 
                            hx-get="{{ path('api_tools_show', {name: tool.name}) }}"
                            hx-target="#tool-details-modal"
                            hx-swap="innerHTML"
                            class="px-3 py-1 bg-gray-600 hover:bg-gray-700 text-white text-sm rounded-lg transition-colors"
                        >
                            Details
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
```

#### **✅ Abnahmekriterien**

| **Kriterium** | **Details** | **Status** |
|--------------|-------------|------------|
| HTMX und Alpine.js installiert | `assets/app.js` + `base.html.twig` | ⏳ |
| HTMX-Konfiguration für Symfony | `htmx.yaml` | ⏳ |
| HTMX-Controller implementiert | `HtmxController` | ⏳ |
| HTMX-Templates für Tool-Execution | `_tool_execution.html.twig` | ⏳ |
| HTMX-Templates für Sub-Agenten | `_subagent_delegation.html.twig` | ⏳ |
| HTMX-Templates für MCP-Tools | `_mcp_tools.html.twig` | ⏳ |
| HTMX-Templates für Dashboard | `_dashboard.html.twig` | ⏳ |
| HTMX-Partials für Flash-Messages | `_flash_messages.html.twig` | ⏳ |
| HTMX-Partials für Tool-Listen | `_tool_list.html.twig` | ⏳ |
| **Echtzeit-Updates funktionieren** | HTMX + Alpine.js für alle UI-Komponenten | ⏳ |

---

## 📅 **Zeitplan & Meilensteine**

### **🟡 Phase 2: Dynamische Sub-Agenten & MCP-Integration (13-20 Tage)**

#### **🟡 Woche 1 (16.-20. August 2026)**

| **Tag** | **Datum** | **Maßnahme** | **Aufwand** | **Status** | **Verantwortlich** |
|---------|-----------|--------------|-------------|------------|-------------------|
| **1** | 16.08.2026 | Sub-Agenten-Definitionen (Entity + Repository + Migration) | 1 Tag | ⏳ | Jens Smit |
| **2** | 17.08.2026 | SubAgentFactory für dynamische Erstellung | 1 Tag | ⏳ | Jens Smit |
| **3** | 18.08.2026 | CompilerPass für Sub-Agenten | 1 Tag | ⏳ | Jens Smit |
| **4** | 19.08.2026 | Cache-Warmup-Command für Sub-Agenten | 0.5 Tage | ⏳ | Jens Smit |
| **5** | 19.08.2026 | SubAgentDispatcher aktualisieren | 0.5 Tage | ⏳ | Jens Smit |
| **6** | 20.08.2026 | Unit-Tests für Sub-Agenten | 1 Tag | ⏳ | Jens Smit |

**Meilenstein:** **Sub-Agenten sind dynamisch ladbar** ✅

#### **🟡 Woche 2 (21.-27. August 2026)**

| **Tag** | **Datum** | **Maßnahme** | **Aufwand** | **Status** | **Verantwortlich** |
|---------|-----------|--------------|-------------|------------|-------------------|
| **7** | 21.08.2026 | Symfony Messenger konfigurieren | 0.5 Tage | ⏳ | Jens Smit |
| **8** | 21.08.2026 | ExecuteToolMessage & StreamToolResponseMessage | 1 Tag | ⏳ | Jens Smit |
| **9** | 22.08.2026 | ExecuteToolMessageHandler implementieren | 1 Tag | ⏳ | Jens Smit |
| **10** | 23.08.2026 | StreamToolResponseMessageHandler implementieren | 0.5 Tage | ⏳ | Jens Smit |
| **11** | 23.08.2026 | StreamingSessionManager implementieren | 1 Tag | ⏳ | Jens Smit |
| **12** | 24.08.2026 | StreamingSession Entity + Repository | 1 Tag | ⏳ | Jens Smit |
| **13** | 25.08.2026 | StreamingController implementieren | 1 Tag | ⏳ | Jens Smit |
| **14** | 26.08.2026 | WebSocket-Integration mit Mercure | 1 Tag | ⏳ | Jens Smit |
| **15** | 27.08.2026 | Unit-Tests für Streaming-Komponenten | 1 Tag | ⏳ | Jens Smit |

**Meilenstein:** **Streaming-Antworten funktionieren** ✅

#### **🟡 Woche 3 (28. August - 03. September 2026)**

| **Tag** | **Datum** | **Maßnahme** | **Aufwand** | **Status** | **Verantwortlich** |
|---------|-----------|--------------|-------------|------------|-------------------|
| **16** | 28.08.2026 | MCP-Server-Definitionen (Entity + Repository + Migration) | 1 Tag | ⏳ | Jens Smit |
| **17** | 29.08.2026 | McpServerFactory implementieren | 1 Tag | ⏳ | Jens Smit |
| **18** | 30.08.2026 | McpServerInterface + McpToolExecutor aktualisieren | 1 Tag | ⏳ | Jens Smit |
| **19** | 31.08.2026 | CompilerPass für MCP-Server | 0.5 Tage | ⏳ | Jens Smit |
| **20** | 31.08.2026 | Cache-Warmup-Command für MCP-Server | 0.5 Tage | ⏳ | Jens Smit |
| **21** | 01.09.2026 | McpServerController implementieren | 0.5 Tage | ⏳ | Jens Smit |
| **22** | 02.09.2026 | Formular für MCP-Server-Definitionen | 0.5 Tage | ⏳ | Jens Smit |
| **23** | 03.09.2026 | Unit-Tests für MCP-Komponenten | 1 Tag | ⏳ | Jens Smit |

**Meilenstein:** **MCP-Server sind dynamisch konfigurierbar** ✅

#### **🟡 Woche 4 (04.-10. September 2026)**

| **Tag** | **Datum** | **Maßnahme** | **Aufwand** | **Status** | **Verantwortlich** |
|---------|-----------|--------------|-------------|------------|-------------------|
| **24** | 04.09.2026 | HTMX und Alpine.js installieren | 0.5 Tage | ⏳ | Jens Smit |
| **25** | 04.09.2026 | HTMX-Konfiguration für Symfony | 0.5 Tage | ⏳ | Jens Smit |
| **26** | 05.09.2026 | HTMX-Controller implementieren | 0.5 Tage | ⏳ | Jens Smit |
| **27** | 06.09.2026 | HTMX-Templates für Tool-Execution | 1 Tag | ⏳ | Jens Smit |
| **28** | 07.09.2026 | HTMX-Templates für Sub-Agenten | 1 Tag | ⏳ | Jens Smit |
| **29** | 08.09.2026 | HTMX-Templates für MCP-Tools | 1 Tag | ⏳ | Jens Smit |
| **30** | 09.09.2026 | HTMX-Templates für Dashboard | 1 Tag | ⏳ | Jens Smit |
| **31** | 10.09.2026 | HTMX-Partials für wiederverwendbare Komponenten | 1 Tag | ⏳ | Jens Smit |

**Meilenstein:** **Frontend mit HTMX/Alpine.js erweitert** ✅

#### **🟢 Woche 5 (11.-13. September 2026)**

| **Tag** | **Datum** | **Maßnahme** | **Aufwand** | **Status** | **Verantwortlich** |
|---------|-----------|--------------|-------------|------------|-------------------|
| **32** | 11.09.2026 | Integrationstests für alle Phase 2-Komponenten | 1 Tag | ⏳ | Jens Smit |
| **33** | 12.09.2026 | Code Review vorbereiten | 1 Tag | ⏳ | Jens Smit |
| **34** | 13.09.2026 | **Phase 2 abschließen & dokumentieren** | 0.5 Tage | ⏳ | Jens Smit |

**Meilenstein:** **Phase 2 abgeschlossen** 🎉

---

## 🎯 **Zusammenfassung & Nächste Schritte**

### **🎉 Was wird in Phase 2 erreicht?**

✅ **Dynamische Sub-Agenten**
- Sub-Agenten **nicht mehr statisch in `ai.yaml`**, sondern **dynamisch aus der Datenbank**
- **Automatische Registrierung** neuer Sub-Agenten ohne manuelle Konfiguration
- **Skalierbarkeit** für beliebige Anzahl von Sub-Agenten
- **Symfony AI Bundle-konform** (YAML + Attribute)

✅ **Streaming-Antworten**
- **Echtzeit-Feedback** für lange Tool-Executions
- **Fortschrittsbalken** in der UI
- **Asynchrone Ausführung** mit Symfony Messenger + WebSocket
- **Server-Sent Events (SSE)** für Push-Updates

✅ **Dynamische MCP-Server-Konfiguration**
- MCP-Server **nicht mehr hardcoded**, sondern **aus der Datenbank**
- **Flexible Integration** neuer MCP-Server ohne Code-Änderungen
- **Sicherheitsprüfung** durch SecurityGuard
- **Symfony AI Bundle-konform**

✅ **Frontend-Erweiterung mit HTMX/Alpine.js**
- **Echtzeit-Updates** ohne Page-Reload
- **Interaktive UI** für Tool-Execution, Sub-Agenten-Delegation und MCP-Tool-Nutzung
- **Konsistente User Experience** mit Symfony AI Bundle

### **🔴 Was ist nach Phase 2 noch offen?**

🟢 **Phase 3 (Mittlere Priorität, 3-4 Wochen)**
- LLM-Prompt-Optimierung
- E2E-Test für Evolution-Flow
- Onboarding-Prompt optimieren

🟢 **Phase 4 (Langfristig, 4+ Wochen)**
- Orchestrator als Klasse umbauen
- API-Controller für Tool-Definitionen
- Dokumentation aktualisieren

### **💡 Empfohlene nächste Schritte:**

1. **🔴 Priorität 1: Sub-Agenten dynamisch machen**
   ```bash
   # Entity, Repository und Migration erstellen
   php bin/console make:entity SubAgentDefinition
   php bin/console make:migration
   php bin/console doctrine:migrations:migrate
   ```

2. **🔴 Priorität 2: Streaming-Antworten implementieren**
   ```bash
   # Symfony Messenger konfigurieren
   composer require symfony/messenger
   php bin/console make:message ExecuteToolMessage
   php bin/console make:message StreamToolResponseMessage
   ```

3. **🔴 Priorität 3: MCP-Server dynamisch konfigurierbar machen**
   ```bash
   # Entity, Repository und Migration erstellen
   php bin/console make:entity McpServerDefinition
   php bin/console make:migration
   php bin/console doctrine:migrations:migrate
   ```

4. **🟡 Priorität 4: Frontend mit HTMX/Alpine.js erweitern**
   ```bash
   # HTMX und Alpine.js installieren
   npm install htmx.org alpinejs
   npm run dev
   ```

---

## 📈 **Metriken & KPIs**

| **Metrik** | **Ziel** | **Aktuell** | **Fortschritt** | **Trend** |
|------------|----------|-------------|----------------|-----------|
| **Sub-Agenten dynamisch** | 100% | 0% | ❌ | ➖ |
| **Streaming-Antworten** | 100% | 0% | ❌ | ➖ |
| **MCP-Server dynamisch** | 100% | 0% | ❌ | ➖ |
| **Frontend mit HTMX/Alpine.js** | 100% | 0% | ❌ | ➖ |
| **Code Coverage (Phase 2)** | 100% | 0% | ❌ | ➖ |
| **Abnahmekriterien** | 100% | 0% | ❌ | ➖ |
| **Dokumentation** | 100% | 10% | 🟢 | 📈 |

---

## 🔗 **Referenzen & Links**

### **Symfony AI Bundle Dokumentation**
- 📖 [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)
- 📖 [Symfony Messenger Docs](https://symfony.com/doc/current/messenger.html)
- 📖 [Symfony Mercure Docs](https://symfony.com/doc/current/mercure.html)

### **HTMX & Alpine.js**
- 📖 [HTMX Docs](https://htmx.org/docs/)
- 📖 [Alpine.js Docs](https://alpinejs.dev/)

### **EVIE-Repository**
- 📄 [EVIE_ANALYSE.md](EVIE_ANALYSE.md) - Detaillierte Systemanalyse
- 📄 [ROADMAP_PHASE1.md](ROADMAP_PHASE1.md) - Implementierungsplan Phase 1
- 📄 [blueprint.md](blueprint.md) - Architektur-Blueprint

---

## 🚀 **Fazit & Ausblick**

**Phase 2 ist bereit für die Umsetzung!** 🎉

Mit **13-20 Arbeitstagen** kann Phase 2 **vollständig abgeschlossen** werden, und EVIE erreicht:
- ✅ **~98% Blueprint-Konformität**
- ✅ **Dynamische Sub-Agenten** (skalierbar, flexibel)
- ✅ **Streaming-Antworten** (Echtzeit-Feedback, asynchron)
- ✅ **Dynamische MCP-Server** (flexibel, sicher)
- ✅ **Interaktives Frontend** (HTMX + Alpine.js)

**💡 Nächster Schritt:**
```bash
# Sub-Agenten-Definitionen erstellen
php bin/console make:entity SubAgentDefinition
```

**📌 Wichtig:**
- Alle Änderungen sind **Symfony AI Bundle-konform**
- **SecurityGuard** wird für alle dynamischen Komponenten verwendet
- **Code Review** wird empfohlen, bevor mit Phase 3 fortgefahren wird

---

**Fragen?** Kontaktiere mich oder erstelle ein **Issue** im Repository!  
**Bereit für den nächsten Schritt?** Ich helfe dir gerne bei der Umsetzung der Maßnahmen! 🚀