# EVIE v0.9.5 - Implementierungsplan

## Übersicht

Dieser Implementierungsplan beschreibt die Erweiterungen für EVIE Version 0.9.5 mit folgenden Hauptfunktionen:

1. **Multi-LLM-Unterstützung** - Wahl zwischen mehreren LLM-Anbietern
2. **Dynamische User Secrets** - Sichere Speicherung von API-Keys und Tokens
3. **Asynchrone Agenten-Ausführung** - Sub-Agenten arbeiten im Hintergrund
4. **Erweiterte Chat-Funktionen** - Natürliche Unterhaltungen und geplante Aufgaben

---

## 1. Multi-LLM-Unterstützung

### 1.1 Anforderungen
- User kann zwischen mehreren LLM-Anbietern wählen (Mistral, OpenAI, Google, Anthropic, Selbstgehostet)
- User kann sein bevorzugtes Modell pro Anbieter auswählen
- User kann eigene API-Keys hinterlegen (optional)
- Falls keine API-Keys hinterlegt sind, werden Default-Werte aus .env verwendet
- Default: Mistral Small (wie aktuell)
- Symfony-AI-kompatibel bleiben

### 1.2 Architektur

```
┌─────────────────────────────────────────────────────────────┐
│                    LLM Provider Service                        │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐ │
│  │  LLM Config      │  │  Provider        │  │  Model           │ │
│  │  Entity          │  │  Factory         │  │  Resolver        │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                    Supported Providers                         │
├─────────────────────────────────────────────────────────────┤
│  • Mistral (mistral-tiny, mistral-small, mistral-medium, etc.)│
│  • OpenAI (gpt-3.5-turbo, gpt-4, gpt-4-turbo)                  │
│  • Google (gemini-1.0-pro, gemini-1.5-pro, gemini-1.5-flash)   │
│  • Anthropic (claude-3-sonnet, claude-3-haiku, claude-3-opus) │
│  • Custom (selbstgehostete LLMs via OpenAI-kompatibler API)   │
└─────────────────────────────────────────────────────────────┘
```

### 1.3 Datenbank-Entitäten

#### LLMConfiguration Entity

```php
// src/Entity/LLMConfiguration.php
#[ORM\Entity]
#[ORM\Table(name: 'llm_configurations')]
class LLMConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $provider;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customProviderName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customApiUrl = null;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
}
```

### 1.4 Service-Klassen

#### LLMProviderInterface

```php
// src/AI/LLM/LLMProviderInterface.php
interface LLMProviderInterface
{
    public function getName(): string;
    public function getModels(): array;
    public function getDefaultModel(): string;
}
```

#### LLMProviderFactory

```php
// src/AI/LLM/LLMProviderFactory.php
class LLMProviderFactory
{
    public function __construct(
        private LLMConfigurationRepository $configRepo,
        private string $defaultProvider,
        private string $defaultModel
    ) {}

    public function getUserProvider(User $user): LLMProviderInterface
    {
        $config = $this->configRepo->findDefaultForUser($user);
        if ($config) {
            return $this->getProvider($config->getProvider());
        }
        return $this->getProvider($this->defaultProvider);
    }

    public function getUserModel(User $user): string
    {
        $config = $this->configRepo->findDefaultForUser($user);
        return $config?->getModel() ?? $this->defaultModel;
    }

    public function getUserApiKey(User $user): ?string
    {
        $config = $this->configRepo->findDefaultForUser($user);
        return $config?->getApiKey();
    }
}
```

---

## 2. Dynamische User Secrets

### 2.1 Anforderungen
- User kann API-Keys und Security-Tokens für Tools hinterlegen
- Secrets werden verschlüsselt in der Datenbank gespeichert
- Das LLM sieht die Secrets NICHT, kann aber anfordern, dass der User sie hinterlegt
- Integration in die Tool-Definition

### 2.2 Architektur

```
┌─────────────────────────────────────────────────────────────┐
│                    User Secret System                          │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐ │
│  │  UserSecret      │  │  SecretRequest   │  │  Encryption      │ │
│  │  Entity          │  │  Service         │  │  Service         │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 2.3 Datenbank-Entitäten

#### UserSecret Entity

```php
// src/Entity/UserSecret.php
#[ORM\Entity]
#[ORM\Table(name: 'user_secrets')]
class UserSecret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private string $key;

    #[ORM\Column(length: 255)]
    private string $encryptedValue;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $toolName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    public function getValue(EncryptionService $encryption): string
    {
        return $encryption->decrypt($this->encryptedValue);
    }

    public function setValue(string $value, EncryptionService $encryption): static
    {
        $this->encryptedValue = $encryption->encrypt($value);
        return $this;
    }
}
```

### 2.4 Service-Klassen

#### UserSecretManager

```php
// src/AI/Secrets/UserSecretManager.php
class UserSecretManager
{
    public function __construct(
        private UserSecretRepository $secretRepo,
        private EncryptionService $encryption
    ) {}

    public function hasSecret(User $user, string $secretKey): bool
    {
        return $this->secretRepo->findOneBy([
            'user' => $user,
            'key' => $secretKey,
            'isActive' => true
        ]) !== null;
    }

    public function getSecret(User $user, string $secretKey): ?string
    {
        $secret = $this->secretRepo->findOneBy([
            'user' => $user,
            'key' => $secretKey,
            'isActive' => true
        ]);
        return $secret?->getValue($this->encryption);
    }

    public function setSecret(User $user, string $key, string $value, ?string $description = null, ?string $toolName = null): UserSecret
    {
        // Speichert oder aktualisiert ein Secret
    }
}
```

---

## 3. Asynchrone Agenten-Ausführung

### 3.1 Anforderungen
- Sub-Agenten können asynchron im Hintergrund arbeiten
- Main-Agent läuft synchron weiter
- User kann den Status von Hintergrundaufgaben abfragen
- Ergebnisse werden dem User zugestellt

### 3.2 Architektur

```
┌─────────────────────────────────────────────────────────────┐
│  ┌─────────────────┐     ┌─────────────────┐     ┌─────────────┐ │
│  │  Main Agent      │────▶│  Message Bus     │────▶│ Sub-Agent   │ │
│  │  (Synchron)      │     │  (Symfony)       │     │ (Async)     │ │
│  └─────────────────┘     └─────────────────┘     └─────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 3.3 Message-Klassen

```php
// src/Message/ExecuteSubAgentMessage.php
class ExecuteSubAgentMessage
{
    public function __construct(
        private string $userIdentifier,
        private string $subAgentName,
        private string $task,
        private array $context = [],
        private ?int $parentMessageId = null
    ) {}
}

// src/Message/SubAgentResultMessage.php
class SubAgentResultMessage
{
    public function __construct(
        private string $userIdentifier,
        private string $subAgentName,
        private string $result,
        private bool $isSuccess = true,
        private ?string $errorMessage = null,
        private ?int $parentMessageId = null
    ) {}
}
```

### 3.4 Message Handler

```php
// src/MessageHandler/ExecuteSubAgentMessageHandler.php
#[AsMessageHandler]
class ExecuteSubAgentMessageHandler
{
    public function __invoke(ExecuteSubAgentMessage $message)
    {
        $subAgent = $this->subAgentFactory->createByName($message->getSubAgentName());
        $messages = new MessageBag(Message::ofUser($message->getTask()));
        $result = $subAgent->call($messages);
        
        $resultMessage = new SubAgentResultMessage(
            $message->getUserIdentifier(),
            $message->getSubAgentName(),
            $result->getContent(),
            true,
            null,
            $message->getParentMessageId()
        );
        
        $this->messageBus->dispatch($resultMessage);
    }
}
```

---

## 4. Erweiterte Chat-Funktionen

### 4.1 Anforderungen
- User kann geplante Aufgaben erstellen (z.B. "Prüfe um 9:00 meine Mails")
- User kann natürliche Unterhaltungen führen
- Geplante Aufgaben werden automatisch ausgeführt

### 4.2 Datenbank-Entitäten

#### ScheduledTask Entity

```php
// src/Entity/ScheduledTask.php
#[ORM\Entity]
#[ORM\Table(name: 'scheduled_tasks')]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'text')]
    private string $taskDescription;

    #[ORM\Column(length: 50)]
    private string $taskType;

    #[ORM\Column(type: 'json')]
    private array $parameters = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRecurring = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $recurrencePattern = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $recurrenceInterval = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;
}
```

### 4.3 Service-Klassen

#### ScheduledTaskManager

```php
// src/AI/Tasks/ScheduledTaskManager.php
class ScheduledTaskManager
{
    public function createTask(
        User $user,
        string $taskDescription,
        string $taskType,
        array $parameters = [],
        \DateTimeImmutable $scheduledAt = null,
        bool $isRecurring = false,
        ?string $recurrencePattern = null,
        ?int $recurrenceInterval = null
    ): ScheduledTask {
        // Erstellt eine neue geplante Aufgabe
    }

    public function executeTask(ScheduledTask $task): void
    {
        // Führt eine geplante Aufgabe aus
    }
}
```

---

## 5. Implementierungs-Roadmap

### Phase 1: Grundlagen (1-2 Wochen)
- [ ] Entity-Klassen erstellen
- [ ] Repository-Klassen erstellen
- [ ] Migrationen erstellen

### Phase 2: LLM-Provider (1 Woche)
- [ ] Provider-Interface und Implementierungen
- [ ] Factory und Resolver
- [ ] Symfony AI Integration

### Phase 3: User Secrets (1 Woche)
- [ ] Encryption Service
- [ ] Secret Manager
- [ ] Tool-Integration

### Phase 4: Asynchrone Ausführung (1 Woche)
- [ ] Message-Klassen
- [ ] Message Handler
- [ ] Messenger Konfiguration

### Phase 5: Erweiterte Chat-Funktionen (1-2 Wochen)
- [ ] Scheduled Task Manager
- [ ] Conversation Manager
- [ ] Orchestrator-Integration

### Phase 6: Frontend-Integration (2 Wochen)
- [ ] LLM Settings
- [ ] User Secrets Settings
- [ ] Scheduled Tasks Settings
- [ ] Chat UI Anpassungen

### Phase 7: Testing & Bugfixing (1-2 Wochen)
- [ ] Unit Tests
- [ ] Integration Tests
- [ ] End-to-End Tests

---

## 6. Symfony AI Kompatibilität

Alle Änderungen müssen mit dem Symfony AI Bundle kompatibel bleiben:
- Keine Mock-Daten
- Keine Platzhalter
- Keine inkompatiblen Bridges
- Keine Konstruktor-Injection für Tools

---

## 7. Sicherheitsaspekte

- API-Keys werden verschlüsselt gespeichert
- User können nur ihre eigenen Keys sehen/bearbeiten
- Starke Verschlüsselung (AES-256-CBC)
- Keine API-Keys in Logs

---

## 8. Zeitplan

| Phase | Dauer | Start | Ende |
|-------|-------|-------|-----|
| Phase 1: Grundlagen | 2 Wochen | 01.09.2025 | 14.09.2025 |
| Phase 2: LLM-Provider | 1 Woche | 15.09.2025 | 21.09.2025 |
| Phase 3: User Secrets | 1 Woche | 22.09.2025 | 28.09.2025 |
| Phase 4: Asynchrone Ausführung | 1 Woche | 29.09.2025 | 05.10.2025 |
| Phase 5: Erweiterte Chat-Funktionen | 2 Wochen | 06.10.2025 | 19.10.2025 |
| Phase 6: Frontend-Integration | 2 Wochen | 20.10.2025 | 02.11.2025 |
| Phase 7: Testing & Bugfixing | 2 Wochen | 03.11.2025 | 16.11.2025 |
| **Gesamt** | **10 Wochen** | **01.09.2025** | **16.11.2025** |

---

## 9. Erfolgskriterien

- User kann zwischen mindestens 5 LLM-Anbietern wählen
- User kann eigene API-Keys hinterlegen
- User kann Secrets für Tools hinterlegen
- Sub-Agenten arbeiten asynchron
- User kann geplante Aufgaben erstellen
- User kann natürliche Unterhaltungen führen
- Alle Funktionen sind Symfony-AI-kompatibel
- 100% Testabdeckung für neue Funktionen

---

*Dokument erstellt am 19.08.2025*
