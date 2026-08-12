# 🚀 EVIE Phase 1 Implementierungsplan: Kritische Lücken schließen

**Erstellt am:** 12. August 2026  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Referenz:** [EVIE_ANALYSE.md](EVIE_ANALYSE.md)  
**Ziel:** Behebung der **kritischen Sicherheits- und Ausführbarkeitslücken** gemäss Symfony AI Bundle Best Practices.

---

## 📋 Übersicht Phase 1

| **Maßnahme** | **Priorität** | **Aufwand** | **Impact** | **Status** |
|--------------|--------------|-------------|------------|------------|
| **1. SecurityGuard mit Whitelist erweitern** | 🔴 **Kritisch** | 1 Tag | 🔴 **Kritisch** (Sicherheitsrisiko) | ⏳ **Geplant** |
| **2. DynamicSkillRegistry mit CompilerPass erweitern** | 🔴 **Kritisch** | 2-3 Tage | 🔴 **Kritisch** (Tools nicht ausführbar) | ⏳ **Geplant** |
| **3. Unit-Tests für kritische Komponenten erstellen** | 🔴 **Kritisch** | 3-5 Tage | 🔴 **Kritisch** (Keine Validierung) | ⏳ **Geplant** |

**Gesamtaufwand Phase 1:** **6-9 Arbeitstage**  
**Ziel:** EVIE auf **~95% Blueprint-Konformität** bringen und **Sicherheitsrisiken eliminieren**.

---

## 📚 Referenz: Symfony AI Bundle Best Practices

### **1. Tool-Registrierung (offizielle Dokumentation)**
Laut [Symfony AI Bundle Docs](https://symfony.com/doc/current/ai/bundles/ai-bundle.html#register-tools) gibt es **3 Methoden**, Tools zu registrieren:

#### **Methode A: Attribut-basiert (`#[AsTool]`)**
```php
// src/AI/Skills/Tool/MyCustomTool.php
namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Tool\AsTool;

#[AsTool(name: 'my_tool', description: 'Beschreibung des Tools')]
final readonly class MyCustomTool
{
    public function __invoke(string $parameter): string
    {
        return 'Ergebnis';
    }
}
```
**Vorteile:**
- Automatische Registrierung via **Autoconfiguration**
- Kein manueller Service-Eintrag nötig
- Ideal für **statische Tools**

#### **Methode B: Service-Konfiguration in `ai.yaml`**
```yaml
# config/packages/ai.yaml
ai:
    agent:
        my_agent:
            tools:
                # Referenz auf Service mit #[AsTool]
                - 'App\AI\Skills\Tool\MyCustomTool'
                
                # Manuelle Service-Konfiguration
                - service: 'App\AI\Skills\Tool\AnotherTool'
                  name: 'another_tool'
                  description: 'Beschreibung'
                  method: '__invoke'  # Optional, Default: '__invoke'
```
**Vorteile:**
- Flexible Konfiguration
- Unterstützung für **dynamische Tools**

#### **Methode C: CompilerPass für dynamische Registrierung**
```php
// src/DependencyInjection/Compiler/AiToolsCompilerPass.php
namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\AI\Agent\Tool\ToolRegistry;

final class AiToolsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ToolRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ToolRegistry::class);
        
        // Dynamische Tools aus der Datenbank laden
        $toolDefinitions = $this->loadToolDefinitionsFromDatabase();
        
        foreach ($toolDefinitions as $definition) {
            $registry->addMethodCall('registerTool', [
                new Reference($this->createToolServiceId($definition)),
                $definition->getName(),
                $definition->getDescription()
            ]);
        }
    }
}
```
**Vorteile:**
- **Dynamische Tools** können zur **Compile-Time** registriert werden
- **Performance:** Keine Laufzeit-Overheads
- **Symfony-Standard** für dynamische Service-Registrierung

---

## 🔧 Maßnahme 1: SecurityGuard mit Whitelist erweitern

### **📌 Problemstellung**
- **Aktueller Zustand:** `SecurityGuard.php` existiert, aber **keine Konfiguration**, welche Basis-Services erlaubt sind
- **Risiko:** Dynamisch generierte Tools könnten **unsichere APIs** (z. B. `exec()`, `file_put_contents()`) aufrufen
- **Blueprint-Anforderung:** `SecurityGuard` soll **harte Sandbox-Grenzen** definieren

### **📋 Lösungskonzept (Symfony AI Bundle kompatibel)**

#### **Schritt 1: Whitelist-Konfiguration in `services.yaml`**
```yaml
# config/services.yaml
parameters:
    # Whitelist für erlaubte Basis-Services
    evie.security.allowed_services:
        - 'App\AI\Skills\Tool\GenericApiExecutor'
        - 'App\AI\Skills\Tool\FileSystemReadExecutor'
        - 'App\AI\Skills\Tool\FileSystemWriteExecutor'
        - 'App\AI\Skills\Tool\DatabaseQueryExecutor'
        - 'App\AI\Skills\Tool\HttpClientExecutor'
        - 'Symfony\AI\Agent\Bridge\Wikipedia\Wikipedia'
        - 'Symfony\AI\Agent\Bridge\Firecrawl\Firecrawl'
        - 'Symfony\AI\Agent\Bridge\Tavily\Tavily'
```

#### **Schritt 2: SecurityGuard erweitern**
```php
// src/AI/Security/SecurityGuard.php
namespace App\AI\Security;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class SecurityGuard
{
    public function __construct(
        private ParameterBagInterface $params,
        private ServiceProviderInterface $serviceProvider,
    ) {
    }

    /**
     * Prüft, ob ein Service in der Whitelist enthalten ist.
     */
    public function isServiceAllowed(string $serviceClass): bool
    {
        $allowedServices = $this->params->get('evie.security.allowed_services');
        
        // 1. Direkte Übereinstimmung
        if (in_array($serviceClass, $allowedServices, true)) {
            return true;
        }

        // 2. Prüfe, ob der Service ein erlaubter Typ ist
        foreach ($allowedServices as $allowedService) {
            if (is_a($serviceClass, $allowedService, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft, ob ein Tool einen erlaubten Service verwendet.
     */
    public function isToolAllowed(array $toolSchema): bool
    {
        // Prüfe, ob das Tool einen Service referenziert
        if (isset($toolSchema['service'])) {
            return $this->isServiceAllowed($toolSchema['service']);
        }

        // Prüfe, ob das Tool eine erlaubte Klasse ist
        if (isset($toolSchema['class'])) {
            return $this->isServiceAllowed($toolSchema['class']);
        }

        // Standardmäßig nicht erlaubt
        return false;
    }

    /**
     * Wirft eine Exception, wenn ein Tool nicht erlaubt ist.
     */
    public function assertToolAllowed(array $toolSchema, string $toolName): void
    {
        if (!$this->isToolAllowed($toolSchema)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist nicht in der SecurityGuard-Whitelist enthalten. ' .
                'Erlaubte Services: %s',
                $toolName,
                implode(', ', $this->params->get('evie.security.allowed_services'))
            ));
        }
    }
}
```

#### **Schritt 3: Integration in HitlInterceptor**
```php
// src/AI/Security/HitlInterceptor.php
namespace App\AI\Security;

use App\Entity\ToolDefinition;
use Symfony\AI\Agent\Tool\ToolInterface;

final readonly class HitlInterceptor implements ToolInterface
{
    public function __construct(
        private SecurityGuard $securityGuard,
        private ToolInterface $decoratedTool,
    ) {
    }

    public function __invoke(...$arguments): mixed
    {
        // 1. Prüfe, ob das Tool genehmigt ist
        if (!$this->decoratedTool instanceof ToolDefinition || 
            !$this->decoratedTool->isApproved()) {
            throw new \RuntimeException(
                'Tool muss zuerst genehmigt werden (HITL).'
            );
        }

        // 2. Prüfe SecurityGuard-Whitelist
        $toolSchema = $this->decoratedTool->getSchema();
        $this->securityGuard->assertToolAllowed($toolSchema, $this->decoratedTool->getName());

        // 3. Führe das Tool aus
        return ($this->decoratedTool)(...$arguments);
    }

    public function getName(): string
    {
        return $this->decoratedTool->getName();
    }

    public function getDescription(): string
    {
        return $this->decoratedTool->getDescription();
    }
}
```

#### **Schritt 4: Service-Konfiguration**
```yaml
# config/services.yaml
services:
    App\AI\Security\SecurityGuard:
        arguments:
            $params: '@parameter_bag'
            $serviceProvider: '@service_container'

    App\AI\Security\HitlInterceptor:
        arguments:
            $securityGuard: '@App\AI\Security\SecurityGuard'
        tags:
            - { name: 'container.hot_path' }
```

#### **Schritt 5: Tool-Definition mit SecurityGuard validieren**
```php
// src/AI/Skills/DynamicSkillRegistry.php
namespace App\AI\Skills;

use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;

final readonly class DynamicSkillRegistry
{
    public function __construct(
        private SecurityGuard $securityGuard,
    ) {
    }

    public function registerTool(ToolDefinition $toolDefinition): void
    {
        // Validierung der SecurityGuard-Whitelist
        $schema = $toolDefinition->getSchema();
        $this->securityGuard->assertToolAllowed($schema, $toolDefinition->getName());

        // Registriere das Tool im Symfony AI Bundle
        // ...
    }
}
```

---

### **✅ Validierung gegen Symfony AI Bundle**
| **Kriterium** | **Symfony AI Bundle Standard** | **EVIE Implementierung** | **Kompatibel?** |
|--------------|--------------------------------|--------------------------|----------------|
| Tool-Validation | Tools werden via `ToolRegistry` registriert | SecurityGuard prüft vor Registrierung | ✅ Ja |
| Service-Referenzierung | Tools können als Services referenziert werden | Whitelist prüft Service-Klassen | ✅ Ja |
| Decorator-Pattern | Tools können decoriert werden | HitlInterceptor als Decorator | ✅ Ja |
| Exception-Handling | RuntimeExceptions für ungültige Tools | RuntimeException bei Whitelist-Verstoß | ✅ Ja |

---

### **📝 Test-Cases für SecurityGuard**
```php
// tests/Unit/AI/Security/SecurityGuardTest.php
namespace App\Tests\Unit\AI\Security;

use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class SecurityGuardTest extends TestCase
{
    private SecurityGuard $securityGuard;

    protected function setUp(): void
    {
        $params = new ParameterBag([
            'evie.security.allowed_services' => [
                'App\AI\Skills\Tool\GenericApiExecutor',
                'App\AI\Skills\Tool\FileSystemReadExecutor',
            ],
        ]);

        $this->securityGuard = new SecurityGuard($params, $this->createMock(ServiceProviderInterface::class));
    }

    public function testIsServiceAllowedWithDirectMatch(): void
    {
        $this->assertTrue(
            $this->securityGuard->isServiceAllowed('App\AI\Skills\Tool\GenericApiExecutor')
        );
    }

    public function testIsServiceAllowedWithInheritance(): void
    {
        $this->assertTrue(
            $this->securityGuard->isServiceAllowed('App\AI\Skills\Tool\FileSystemReadExecutor')
        );
    }

    public function testIsServiceAllowedWithNonAllowedService(): void
    {
        $this->assertFalse(
            $this->securityGuard->isServiceAllowed('App\AI\Skills\Tool\DangerousExecutor')
        );
    }

    public function testAssertToolAllowedWithValidTool(): void
    {
        $toolSchema = ['service' => 'App\AI\Skills\Tool\GenericApiExecutor'];
        
        // Sollte keine Exception werfen
        $this->securityGuard->assertToolAllowed($toolSchema, 'test_tool');
        $this->assertTrue(true);
    }

    public function testAssertToolAllowedWithInvalidTool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist enthalten');
        
        $toolSchema = ['service' => 'App\AI\Skills\Tool\DangerousExecutor'];
        $this->securityGuard->assertToolAllowed($toolSchema, 'dangerous_tool');
    }
}
```

---

## 🔧 Maßnahme 2: DynamicSkillRegistry mit CompilerPass erweitern

### **📌 Problemstellung**
- **Aktueller Zustand:** `DynamicSkillRegistry` lädt `ToolDefinition`-Entities aus der DB, aber **keine Umwandlung in `ToolInterface`**
- **Risiko:** Dynamisch generierte Tools sind **nicht ausführbar**
- **Blueprint-Anforderung:** `DynamicSkillRegistry` soll JSON-Schemata in instanziierbare Klassen umwandeln

### **📋 Lösungskonzept (Symfony AI Bundle kompatibel)**

#### **Schritt 1: DynamicTool-Klasse erstellen**
```php
// src/AI/Skills/Tool/DynamicTool.php
namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use Symfony\AI\Agent\Tool\ToolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTool;

#[AsTool]
final readonly class DynamicTool implements ToolInterface
{
    public function __construct(
        private ToolDefinition $toolDefinition,
        private \Closure $executor,
    ) {
    }

    public function __invoke(...$arguments): mixed
    {
        return ($this->executor)(...$arguments);
    }

    public function getName(): string
    {
        return $this->toolDefinition->getName();
    }

    public function getDescription(): string
    {
        return $this->toolDefinition->getDescription();
    }

    public function getSchema(): array
    {
        return $this->toolDefinition->getSchema();
    }
}
```

#### **Schritt 2: DynamicToolFactory erstellen**
```php
// src/AI/Skills/Tool/DynamicToolFactory.php
namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

final readonly class DynamicToolFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * Erstellt ein DynamicTool aus einer ToolDefinition.
     */
    public function createTool(ToolDefinition $toolDefinition): DynamicTool
    {
        // 1. Erstelle den Executor basierend auf dem Tool-Typ
        $executor = $this->createExecutor($toolDefinition);

        // 2. Erstelle und registriere das Tool
        return new DynamicTool($toolDefinition, $executor);
    }

    /**
     * Erstellt den passenden Executor für das Tool.
     */
    private function createExecutor(ToolDefinition $toolDefinition): \Closure
    {
        $schema = $toolDefinition->getSchema();
        
        // Falls das Tool einen spezifischen Service referenziert
        if (isset($schema['service'])) {
            $service = $this->container->get($schema['service']);
            return fn(...$args) => $service->__invoke(...$args);
        }

        // Fallback: Generischer Executor
        return function (...$arguments) use ($toolDefinition) {
            // Hier könnte die tatsächliche Ausführung logik stehen
            // z.B. Aufruf eines generischen API-Executors
            return $this->executeGenericTool($toolDefinition, $arguments);
        };
    }

    /**
     * Führt ein generisches Tool aus.
     */
    private function executeGenericTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        // Implementierung der generischen Tool-Ausführung
        // z.B. Aufruf einer externen API oder Datenbankabfrage
        return 'Tool "' . $toolDefinition->getName() . '" wurde ausgeführt.';
    }
}
```

#### **Schritt 3: CompilerPass für dynamische Tool-Registrierung**
```php
// src/DependencyInjection/Compiler/AiDynamicToolsCompilerPass.php
namespace App\DependencyInjection\Compiler;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AiDynamicToolsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // 1. Prüfe, ob ToolDefinitionRepository existiert
        if (!$container->has(ToolDefinitionRepository::class)) {
            return;
        }

        // 2. Prüfe, ob DynamicToolFactory existiert
        if (!$container->has('App\AI\Skills\Tool\DynamicToolFactory')) {
            return;
        }

        // 3. Lade alle genehmigten Tool-Definitionen
        $toolDefinitions = $this->loadApprovedToolDefinitions($container);

        // 4. Registriere jedes Tool als Service
        foreach ($toolDefinitions as $toolDefinition) {
            $this->registerDynamicTool($container, $toolDefinition);
        }
    }

    /**
     * Lädt alle genehmigten Tool-Definitionen aus der Datenbank.
     */
    private function loadApprovedToolDefinitions(ContainerBuilder $container): array
    {
        // In einer echten Implementierung würde hier die DB abgefragt werden
        // Für den CompilerPass müssen wir die Tools zur Compile-Time kennen
        // => Wir verwenden eine Parameter-Konfiguration
        
        if (!$container->hasParameter('evie.dynamic_tools.approved')) {
            return [];
        }

        $approvedToolIds = $container->getParameter('evie.dynamic_tools.approved');
        
        // In einer echten Anwendung: ToolDefinitionRepository abfragen
        // Für den CompilerPass: Wir simulieren die Tools
        $toolDefinitions = [];
        foreach ($approvedToolIds as $toolId) {
            // Hier würde das echte ToolDefinition-Objekt geladen werden
            // Für den CompilerPass: Wir erstellen ein Mock-Objekt
            $toolDefinitions[] = $this->createMockToolDefinition($toolId);
        }

        return $toolDefinitions;
    }

    /**
     * Erstellt ein Mock ToolDefinition für den CompilerPass.
     * In einer echten Implementierung: ToolDefinitionRepository abfragen
     */
    private function createMockToolDefinition(int $toolId): ToolDefinition
    {
        // Dies ist ein Platzhalter - in der Praxis würde hier die echte Entity geladen werden
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setId($toolId);
        $toolDefinition->setName('dynamic_tool_' . $toolId);
        $toolDefinition->setDescription('Dynamisch generiertes Tool ' . $toolId);
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'input' => ['type' => 'string'],
            ],
        ]);

        return $toolDefinition;
    }

    /**
     * Registriert ein dynamisches Tool als Service.
     */
    private function registerDynamicTool(ContainerBuilder $container, ToolDefinition $toolDefinition): void
    {
        $serviceId = 'ai.tool.dynamic.' . $toolDefinition->getId();

        // 1. Erstelle die Service-Definition
        $definition = new Definition(DynamicTool::class);
        $definition->setArguments([
            new Reference('App\Entity\ToolDefinition'), // Wird zur Laufzeit injiziert
            new Reference('App\AI\Skills\Tool\DynamicToolFactory'),
        ]);

        // 2. Füge das Tool zum ToolRegistry hinzu
        $definition->addTag('ai.tool', [
            'name' => $toolDefinition->getName(),
            'description' => $toolDefinition->getDescription(),
        ]);

        // 3. Registriere den Service
        $container->setDefinition($serviceId, $definition);

        // 4. Füge den Service zur ToolRegistry hinzu
        if ($container->has('ai.agent.tool_registry')) {
            $registry = $container->findDefinition('ai.agent.tool_registry');
            $registry->addMethodCall('registerTool', [
                new Reference($serviceId),
                $toolDefinition->getName(),
                $toolDefinition->getDescription(),
            ]);
        }
    }
}
```

#### **Schritt 4: CompilerPass in services.yaml registrieren**
```yaml
# config/services.yaml
services:
    # DynamicToolFactory
    App\AI\Skills\Tool\DynamicToolFactory:
        arguments:
            $container: '@service_container'

    # CompilerPass registrieren
    App\DependencyInjection\Compiler\AiDynamicToolsCompilerPass:
        tags:
            - { name: 'container.compiler_pass' }
```

#### **Schritt 5: Parameter-Konfiguration für genehmigte Tools**
```yaml
# config/services.yaml
parameters:
    # Liste der genehmigten Tool-IDs (wird zur Compile-Time geladen)
    evie.dynamic_tools.approved: []
```

**Hinweis:** Da der CompilerPass zur **Compile-Time** läuft, können wir nicht direkt auf die Datenbank zugreifen. Stattdessen gibt es **2 Ansätze**:

##### **Ansatz A: Cache-basierte Lösung (empfohlen)**
1. **Cache die genehmigten Tools** in einem File-Cache
2. **Wärme den Cache** beim Deployment oder via Command
3. **CompilerPass liest aus dem Cache**

```php
// src/Command/WarmupDynamicToolsCacheCommand.php
namespace App\Command;

use App\Repository\ToolDefinitionRepository;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WarmupDynamicToolsCacheCommand extends Command
{
    protected static $defaultName = 'evie:tools:warmup-cache';

    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = new FilesystemAdapter('evie_dynamic_tools');
        
        // Lade alle genehmigten Tools
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => ['approved', 'pending_approval'],
        ]);

        // Speichere im Cache
        $cacheItem = $cache->getItem('approved_tools');
        $cacheItem->set($approvedTools);
        $cacheItem->expiresAfter(3600); // 1 Stunde Cache
        $cache->save($cacheItem);

        $output->writeln(sprintf('✅ Cache für %d Tools gewärmt.', count($approvedTools)));
        return Command::SUCCESS;
    }
}
```

##### **Ansatz B: Lazy Loading (alternativ)**
Falls CompilerPass nicht praktikabel ist, können Tools **zur Laufzeit** registriert werden:

```php
// src/AI/Skills/DynamicSkillRegistry.php
namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\Tool\ToolRegistry;

final class DynamicSkillRegistry
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private ToolRegistry $toolRegistry,
        private DynamicToolFactory $toolFactory,
    ) {
    }

    /**
     * Registriert alle genehmigten Tools zur Laufzeit.
     * Wird beim Container-Build oder ersten Aufruf ausgeführt.
     */
    public function registerApprovedTools(): void
    {
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => ['approved'],
        ]);

        foreach ($approvedTools as $toolDefinition) {
            $tool = $this->toolFactory->createTool($toolDefinition);
            $this->toolRegistry->registerTool(
                $tool,
                $toolDefinition->getName(),
                $toolDefinition->getDescription()
            );
        }
    }

    /**
     * Registriert ein einzelnes Tool zur Laufzeit.
     */
    public function registerTool(ToolDefinition $toolDefinition): void
    {
        $tool = $this->toolFactory->createTool($toolDefinition);
        $this->toolRegistry->registerTool(
            $tool,
            $toolDefinition->getName(),
            $toolDefinition->getDescription()
        );
    }
}
```

---

### **✅ Validierung gegen Symfony AI Bundle**
| **Kriterium** | **Symfony AI Bundle Standard** | **EVIE Implementierung** | **Kompatibel?** |
|--------------|--------------------------------|--------------------------|----------------|
| Tool-Registrierung | Tools werden via `ToolRegistry` registriert | DynamicTool wird im ToolRegistry registriert | ✅ Ja |
| Service-Tags | Tools können via `#[AsTool]` oder Tags registriert werden | DynamicTool nutzt `#[AsTool]` | ✅ Ja |
| CompilerPass | CompilerPasses sind der Symfony-Standard | AiDynamicToolsCompilerPass implementiert CompilerPassInterface | ✅ Ja |
| Laufzeit-Registrierung | Tools können zur Laufzeit registriert werden | DynamicSkillRegistry unterstützt Lazy Loading | ✅ Ja |

---

### **📝 Test-Cases für DynamicSkillRegistry**
```php
// tests/Unit/AI/Skills/DynamicSkillRegistryTest.php
namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Tool\ToolRegistry;

final class DynamicSkillRegistryTest extends TestCase
{
    private DynamicSkillRegistry $registry;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private ToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        $this->toolDefinitionRepo = $this->createMock(ToolDefinitionRepository::class);
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
        $toolFactory = $this->createMock(DynamicToolFactory::class);

        $this->registry = new DynamicSkillRegistry(
            $this->toolDefinitionRepo,
            $this->toolRegistry,
            $toolFactory
        );
    }

    public function testRegisterApprovedTools(): void
    {
        // Mock Tool-Definitionen
        $tool1 = new ToolDefinition();
        $tool1->setName('test_tool_1');
        $tool1->setDescription('Test Tool 1');
        $tool1->setStatus('approved');

        $tool2 = new ToolDefinition();
        $tool2->setName('test_tool_2');
        $tool2->setDescription('Test Tool 2');
        $tool2->setStatus('pending'); // Sollte nicht registriert werden

        $this->toolDefinitionRepo
            ->method('findBy')
            ->with(['status' => ['approved']])
            ->willReturn([$tool1]);

        $this->toolRegistry
            ->expects($this->once())
            ->method('registerTool');

        $this->registry->registerApprovedTools();
    }

    public function testRegisterTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('new_tool');
        $toolDefinition->setDescription('New Tool');

        $this->toolRegistry
            ->expects($this->once())
            ->method('registerTool')
            ->with(
                $this->anything(),
                'new_tool',
                'New Tool'
            );

        $this->registry->registerTool($toolDefinition);
    }
}
```

---

## 🔧 Maßnahme 3: Unit-Tests für kritische Komponenten

### **📌 Problemstellung**
- **Aktueller Zustand:** **Keine Tests** für kritische Komponenten
- **Risiko:** Keine Validierung der **Sicherheitsmechanismen** und **Funktionalität**
- **Blueprint-Anforderung:** Unit-Tests für `DynamicSkillRegistry`, `HitlInterceptor`, `SecurityGuard`

### **📋 Lösungskonzept: Test-Framework für Symfony AI Bundle**

#### **Schritt 1: Test-Abhängigkeiten installieren**
```bash
composer require --dev phpunit/phpunit symfony/test-pack
```

#### **Schritt 2: PHPUnit-Konfiguration aktualisieren**
```xml
<!-- phpunit.xml.dist -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects"
         requireCoverageMetadata="false"
         beStrictAboutCoverageMetadata="false"
         beStrictAboutOutputDuringTests="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="EVIE AI Tests">
            <directory>tests/Unit/AI</directory>
            <directory>tests/Integration/AI</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src/AI</directory>
        </include>
        <exclude>
            <file>src/Kernel.php</file>
        </exclude>
    </source>
</phpunit>
```

#### **Schritt 3: Test-Bootstrap erstellen**
```php
// tests/bootstrap.php
<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
}

// Setzen der Umgebungsvariablen für Tests
putenv('APP_ENV=test');
putenv('MISTRAL_API_KEY=test_api_key');
```

#### **Schritt 4: Test-Klassen erstellen**

##### **Test für HitlInterceptor**
```php
// tests/Unit/AI/Security/HitlInterceptorTest.php
namespace App\Tests\Unit\AI\Security;

use App\AI\Security\HitlInterceptor;
use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Tool\ToolInterface;

final class HitlInterceptorTest extends TestCase
{
    private HitlInterceptor $interceptor;
    private SecurityGuard $securityGuard;

    protected function setUp(): void
    {
        $this->securityGuard = $this->createMock(SecurityGuard::class);
        $decoratedTool = $this->createMock(ToolInterface::class);

        $this->interceptor = new HitlInterceptor($this->securityGuard, $decoratedTool);
    }

    public function testInvokeWithApprovedTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setStatus('approved');

        $decoratedTool = $this->createMock(ToolInterface::class);
        $decoratedTool->method('isApproved')->willReturn(true);
        $decoratedTool->method('getSchema')->willReturn(['service' => 'App\AI\Skills\Tool\GenericApiExecutor']);
        $decoratedTool->method('getName')->willReturn('test_tool');
        $decoratedTool->method('__invoke')->willReturn('Ergebnis');

        $this->securityGuard
            ->method('assertToolAllowed')
            ->with(['service' => 'App\AI\Skills\Tool\GenericApiExecutor'], 'test_tool')
            ->willReturn(null);

        $result = ($this->interceptor)('test');
        $this->assertEquals('Ergebnis', $result);
    }

    public function testInvokeWithPendingTool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool muss zuerst genehmigt werden');

        $toolDefinition = new ToolDefinition();
        $toolDefinition->setStatus('pending');

        $decoratedTool = $this->createMock(ToolInterface::class);
        $decoratedTool->method('isApproved')->willReturn(false);

        ($this->interceptor)('test');
    }

    public function testInvokeWithDisallowedTool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist enthalten');

        $toolDefinition = new ToolDefinition();
        $toolDefinition->setStatus('approved');

        $decoratedTool = $this->createMock(ToolInterface::class);
        $decoratedTool->method('isApproved')->willReturn(true);
        $decoratedTool->method('getSchema')->willReturn(['service' => 'App\AI\Skills\Tool\DangerousExecutor']);
        $decoratedTool->method('getName')->willReturn('dangerous_tool');

        $this->securityGuard
            ->method('assertToolAllowed')
            ->willThrowException(new \RuntimeException('ist nicht in der SecurityGuard-Whitelist enthalten'));

        ($this->interceptor)('test');
    }
}
```

##### **Test für DynamicToolFactory**
```php
// tests/Unit/AI/Skills/Tool/DynamicToolFactoryTest.php
namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DynamicToolFactoryTest extends TestCase
{
    private DynamicToolFactory $factory;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->factory = new DynamicToolFactory($this->container);
    }

    public function testCreateToolWithServiceReference(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setSchema([
            'service' => 'App\AI\Skills\Tool\GenericApiExecutor',
        ]);

        $executorService = $this->createMock(\stdClass::class);
        $executorService->method('__invoke')->willReturn('Test-Ergebnis');

        $this->container
            ->method('get')
            ->with('App\AI\Skills\Tool\GenericApiExecutor')
            ->willReturn($executorService);

        $tool = $this->factory->createTool($toolDefinition);

        $result = $tool('test');
        $this->assertEquals('Test-Ergebnis', $result);
    }

    public function testCreateToolWithGenericExecutor(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setSchema([]); // Kein spezifischer Service

        $tool = $this->factory->createTool($toolDefinition);

        $result = $tool('test');
        $this->assertStringContainsString('wurde ausgeführt', $result);
    }

    public function testGetName(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('test_tool');

        $tool = $this->factory->createTool($toolDefinition);

        $this->assertEquals('test_tool', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setDescription('Test-Beschreibung');

        $tool = $this->factory->createTool($toolDefinition);

        $this->assertEquals('Test-Beschreibung', $tool->getDescription());
    }
}
```

##### **Integrationstest für DynamicSkillRegistry**
```php
// tests/Integration/AI/Skills/DynamicSkillRegistryIntegrationTest.php
namespace App\Tests\Integration\AI\Skills;

use App\AI\Skills\DynamicSkillRegistry;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\AI\Agent\Tool\ToolRegistry;

final class DynamicSkillRegistryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private ToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->toolDefinitionRepo = self::getContainer()->get(ToolDefinitionRepository::class);
        $this->toolRegistry = self::getContainer()->get(ToolRegistry::class);
    }

    public function testRegisterApprovedToolsFromDatabase(): void
    {
        // Erstelle Test-Tool-Definitionen
        $tool1 = new ToolDefinition();
        $tool1->setName('test_tool_1');
        $tool1->setDescription('Test Tool 1');
        $tool1->setStatus('approved');
        $tool1->setSchema([
            'type' => 'object',
            'properties' => [
                'input' => ['type' => 'string'],
            ],
        ]);

        $tool2 = new ToolDefinition();
        $tool2->setName('test_tool_2');
        $tool2->setDescription('Test Tool 2');
        $tool2->setStatus('pending'); // Sollte nicht registriert werden

        $this->entityManager->persist($tool1);
        $this->entityManager->persist($tool2);
        $this->entityManager->flush();

        // Registriere genehmigte Tools
        $registry = new DynamicSkillRegistry(
            $this->toolDefinitionRepo,
            $this->toolRegistry,
            self::getContainer()->get('App\AI\Skills\Tool\DynamicToolFactory')
        );
        $registry->registerApprovedTools();

        // Prüfe, ob nur das genehmigte Tool registriert wurde
        $registeredTools = $this->toolRegistry->getTools();
        $this->assertCount(1, $registeredTools);
        $this->assertArrayHasKey('test_tool_1', $registeredTools);
        $this->assertArrayNotHasKey('test_tool_2', $registeredTools);
    }

    public function testRegisterSingleTool(): void
    {
        $tool = new ToolDefinition();
        $tool->setName('single_tool');
        $tool->setDescription('Single Tool');
        $tool->setStatus('approved');
        $tool->setSchema([
            'type' => 'object',
            'properties' => [
                'input' => ['type' => 'string'],
            ],
        ]);

        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        $registry = new DynamicSkillRegistry(
            $this->toolDefinitionRepo,
            $this->toolRegistry,
            self::getContainer()->get('App\AI\Skills\Tool\DynamicToolFactory')
        );
        $registry->registerTool($tool);

        $registeredTools = $this->toolRegistry->getTools();
        $this->assertArrayHasKey('single_tool', $registeredTools);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
```

---

## 📅 Zeitplan Phase 1

### **Tag 1: SecurityGuard mit Whitelist**
| **Zeit** | **Aufgabe** | **Verantwortlich** | **Status** |
|----------|-------------|-------------------|------------|
| 09:00-10:00 | Whitelist-Konfiguration in `services.yaml` erstellen | Dev | ⏳ |
| 10:00-11:30 | `SecurityGuard` Klasse erweitern | Dev | ⏳ |
| 11:30-12:30 | Integration in `HitlInterceptor` | Dev | ⏳ |
| 13:30-15:00 | Service-Konfiguration anpassen | Dev | ⏳ |
| 15:00-16:30 | Unit-Tests für SecurityGuard erstellen | Dev | ⏳ |
| 16:30-17:00 | **Review & Merge** | Team | ⏳ |

### **Tag 2-3: DynamicSkillRegistry mit CompilerPass**
| **Zeit** | **Aufgabe** | **Verantwortlich** | **Status** |
|----------|-------------|-------------------|------------|
| 09:00-10:30 | `DynamicTool` Klasse erstellen | Dev | ⏳ |
| 10:30-12:00 | `DynamicToolFactory` erstellen | Dev | ⏳ |
| 13:00-15:00 | `AiDynamicToolsCompilerPass` implementieren | Dev | ⏳ |
| 15:00-16:30 | CompilerPass in `services.yaml` registrieren | Dev | ⏳ |
| 16:30-17:00 | Cache-Warmup-Command erstellen | Dev | ⏳ |
| 09:00-10:30 | Lazy-Loading-Alternative implementieren | Dev | ⏳ |
| 10:30-12:00 | Unit-Tests für DynamicToolFactory erstellen | Dev | ⏳ |
| 13:00-15:00 | Integrationstests erstellen | Dev | ⏳ |
| 15:00-16:30 | **Review & Merge** | Team | ⏳ |

### **Tag 4-5: Unit-Tests für kritische Komponenten**
| **Zeit** | **Aufgabe** | **Verantwortlich** | **Status** |
|----------|-------------|-------------------|------------|
| 09:00-10:30 | PHPUnit-Konfiguration aktualisieren | Dev | ⏳ |
| 10:30-12:00 | Test-Bootstrap erstellen | Dev | ⏳ |
| 13:00-15:00 | Unit-Tests für HitlInterceptor erstellen | Dev | ⏳ |
| 15:00-16:30 | Unit-Tests für DynamicSkillRegistry erstellen | Dev | ⏳ |
| 09:00-10:30 | Integrationstests erstellen | Dev | ⏳ |
| 10:30-12:00 | Tests ausführen und Debuggen | Dev | ⏳ |
| 12:00-13:00 | **Review & Merge** | Team | ⏳ |
| 13:00-15:00 | **Alle Tests durchführen** | Dev | ⏳ |
| 15:00-16:30 | **Phase 1 abschließen** | Team | ⏳ |

---

## ✅ Abnahmekriterien Phase 1

### **Maßnahme 1: SecurityGuard mit Whitelist**
- [ ] `SecurityGuard` prüft Tools gegen Whitelist
- [ ] Whitelist-Konfiguration in `services.yaml`
- [ ] Integration in `HitlInterceptor`
- [ ] Unit-Tests für SecurityGuard (100% Coverage)
- [ ] **Keine unsicheren Tools** können ausgeführt werden

### **Maßnahme 2: DynamicSkillRegistry mit CompilerPass**
- [ ] `DynamicTool` Klasse implementiert
- [ ] `DynamicToolFactory` implementiert
- [ ] `AiDynamicToolsCompilerPass` implementiert
- [ ] CompilerPass in `services.yaml` registriert
- [ ] Cache-Warmup-Command für Tools
- [ ] Lazy-Loading-Alternative implementiert
- [ ] Unit-Tests für DynamicToolFactory (100% Coverage)
- [ ] Integrationstests für DynamicSkillRegistry
- [ ] **Dynamisch generierte Tools sind ausführbar**

### **Maßnahme 3: Unit-Tests für kritische Komponenten**
- [ ] PHPUnit-Konfiguration aktualisiert
- [ ] Test-Bootstrap erstellt
- [ ] Unit-Tests für HitlInterceptor
- [ ] Unit-Tests für DynamicSkillRegistry
- [ ] Unit-Tests für SecurityGuard
- [ ] **Alle Tests bestehen** (100% Pass-Rate)

---

## 🔗 Referenzen

### **Symfony AI Bundle Dokumentation**
- [AI Bundle Configuration](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)
- [Register Tools](https://symfony.com/doc/current/ai/bundles/ai-bundle.html#register-tools)
- [CompilerPass Integration](https://symfony.com/doc/current/components/dependency_injection/compiler_passes.html)
- [Testing Agents](https://symfony.com/doc/current/ai/bundles/ai-bundle.html#testing-agents)

### **EVIE-spezifische Dokumentation**
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md) (Detaillierte Analyse)
- [blueprint.md](blueprint.md) (Architektur-Blueprint)

---

## 📌 Zusammenfassung

Mit der Umsetzung von **Phase 1** werden die **kritischsten Lücken** in EVIE geschlossen:

1. **Sicherheit:** `SecurityGuard` verhindert unsichere Tool-Ausführungen
2. **Ausführbarkeit:** Dynamisch generierte Tools werden via CompilerPass registriert
3. **Qualitätssicherung:** Unit-Tests validieren alle kritischen Komponenten

**Ergebnis:** EVIE erreicht **~95% Blueprint-Konformität** und ist bereit für die nächsten Phasen.

---

**💡 Nächste Schritte:**
- [ ] **Phase 1 umsetzen** (6-9 Tage)
- [ ] **Code Review durchführen**
- [ ] **Phase 2 planen** (Sub-Agenten dynamisch machen, Streaming, MCP-Server)

**Fragen?** Kontaktiere das Team oder erstelle ein Issue im Repository! 🚀