# Architektur-Blueprint: EVIE v0.9.5 - Multi-LLM & Extended Features

**Version:** 0.9.5  
**Datum:** 19.08.2025  
**Basierend auf:** Symfony AI v0.12, Mistral LLM  
**Erweitert um:** Multi-LLM-Unterstützung, Dynamische User Secrets, Asynchrone Agenten, Erweiterte Chat-Funktionen

---

## Inhaltsverzeichnis

1. [Zielarchitektur v0.9.5](#1-zielarchitektur-v095)
2. [Kernprinzipien v0.9.5](#2-kernprinzipien-v095)
3. [Verzeichnisstruktur v0.9.5](#3-verzeichnisstruktur-v095)
4. [Komponenten-Design](#4-komponenten-design)
5. [Workflows v0.9.5](#5-workflows-v095)
6. [Symfony AI Native Integration](#6-symfony-ai-native-integration)
7. [Konfiguration](#7-konfiguration)
8. [Sicherheitskonzept](#8-sicherheitskonzept)
9. [Erfolgskriterien](#9-erfolgskriterien)

---

## 1. Zielarchitektur v0.9.5

Die Architektur von EVIE v0.9.5 erweitert den bestehenden Blueprint um folgende Kernfunktionen:

- **Multi-LLM-Unterstützung**: User können zwischen mehreren LLM-Anbietern wählen
- **Dynamische User Secrets**: Sichere Speicherung von API-Keys und Tokens
- **Asynchrone Agenten-Ausführung**: Sub-Agenten arbeiten im Hintergrund
- **Erweiterte Chat-Funktionen**: Natürliche Unterhaltungen und geplante Aufgaben

Alle Erweiterungen folgen den Symfony AI Best Practices und bleiben 100% kompatibel mit dem bestehenden System.

---

## 2. Kernprinzipien v0.9.5

### 2.1 Multi-LLM-Unterstützung
- Provider-Abstraktion über LLMProviderInterface
- User-Konfiguration pro User
- Fallback auf Default-Keys aus .env
- Symfony-AI-kompatibel

### 2.2 Dynamische User Secrets
- AES-256-CBC Verschlüsselung
- LLM sieht niemals die tatsächlichen Werte
- Tool-Integration über SecretAwareToolInterface
- User-spezifische Secrets

### 2.3 Asynchrone Agenten-Ausführung
- Symfony Messenger Integration
- Main-Agent läuft synchron weiter
- Ergebnisse über Notifications

### 2.4 Erweiterte Chat-Funktionen
- Conversation-Kontext
- Geplante Aufgaben (Cron-Jobs)
- Natürliche Sprachverarbeitung für Task-Erstellung

---

## 3. Verzeichnisstruktur v0.9.5

```
src/
├── AI/
│   ├── LLM/                          # NEU: Multi-LLM
│   │   ├── LLMProviderInterface.php
│   │   ├── Provider/
│   │   │   ├── MistralProvider.php
│   │   │   ├── OpenAIProvider.php
│   │   │   ├── GoogleProvider.php
│   │   │   ├── AnthropicProvider.php
│   │   │   └── CustomProvider.php
│   │   ├── LLMProviderFactory.php
│   │   ├── LLMPlatformResolver.php
│   │   └── OrchestratorAgentDecorator.php
│   │
│   ├── Secrets/                      # NEU: User Secrets
│   │   ├── EncryptionService.php
│   │   ├── UserSecretManager.php
│   │   └── SecretRequestService.php
│   │
│   ├── Tasks/                       # NEU: Scheduled Tasks
│   │   ├── ScheduledTaskManager.php
│   │   └── TaskScheduler.php
│   │
│   ├── Chat/                        # NEU: Conversation
│   │   └── ConversationManager.php
│   │
│   ├── Agent/
│   │   ├── EvieToolboxFactory.php
│   │   ├── SubAgentFactory.php
│   │   └── OrchestratorDialogService.php  # ERWEITERT
│   │
│   └── Skills/
│       ├── SecretAwareToolInterface.php  # NEU
│       └── Tool/
│           └── ...
│
├── Entity/
│   ├── LLMConfiguration.php          # NEU
│   ├── UserSecret.php                # NEU
│   ├── SecretRequest.php             # NEU
│   ├── ScheduledTask.php             # NEU
│   └── AgentHistory.php              # ERWEITERT
│
├── Message/                         # NEU
│   ├── ExecuteSubAgentMessage.php
│   └── SubAgentResultMessage.php
│
├── MessageHandler/                   # NEU/ERWEITERT
│   ├── ExecuteSubAgentMessageHandler.php
│   └── SubAgentResultMessageHandler.php
│
config/
├── packages/
│   ├── ai.yaml                       # ERWEITERT
│   └── messenger.yaml                # NEU
└── prompts/
    └── ...

docs/
├── IMPLEMENTIERUNGSPLAN_V0.9.5.md
└── blueprint_v0.9.5.md
```

---

## 4. Komponenten-Design

### 4.1 Multi-LLM Provider System

#### LLMProviderInterface
```php
interface LLMProviderInterface
{
    public function getName(): string;
    public function getModels(): array;
    public function getDefaultModel(): string;
    public function createPlatform(string $apiKey): PlatformInterface;
}
```

#### LLMProviderFactory
- Verwaltet alle verfügbaren Provider
- Lädt User-spezifische Konfigurationen
- Fallback auf Default-Werte

#### LLMPlatformResolver
- Löst die passende Platform für einen User auf
- Berücksichtigt User-API-Keys oder .env-Keys

### 4.2 User Secrets System

#### EncryptionService
- AES-256-CBC Verschlüsselung
- Sichere Speicherung von Secrets

#### UserSecretManager
- Verwaltung von User-Secrets
- Prüft auf fehlende Secrets
- Gibt Secrets an Tools weiter

#### SecretRequestService
- Erstellt Anfragen für fehlende Secrets
- Generiert User-freundliche Nachrichten

#### SecretAwareToolInterface
```php
interface SecretAwareToolInterface
{
    public static function getRequiredSecrets(): array;
}
```

### 4.3 Asynchrone Ausführung

#### ExecuteSubAgentMessage
- Enthält alle Informationen für die asynchrone Ausführung
- UserIdentifier, SubAgentName, Task, Context

#### SubAgentResultMessage
- Enthält das Ergebnis der asynchronen Ausführung
- Erfolg/Fehler-Status

#### Message Handler
- ExecuteSubAgentMessageHandler: Führt den Sub-Agenten aus
- SubAgentResultMessageHandler: Verarbeitet das Ergebnis

### 4.4 Geplante Aufgaben

#### ScheduledTaskManager
- Erstellt und verwaltet geplante Aufgaben
- Führt Aufgaben aus
- Berechnet nächste Ausführungszeiten

#### ConversationManager
- Verwaltet Chat-Verläufe
- Speichert Nachrichten in Conversations
- Stellt Kontext für den Agenten bereit

---

## 5. Workflows v0.9.5

### 5.1 Multi-LLM Workflow
1. User wählt Provider und Modell in Settings
2. Konfiguration wird gespeichert
3. LLMPlatformResolver löst die passende Platform auf
4. Agent verwendet User-spezifische Konfiguration

### 5.2 User Secrets Workflow
1. Tool prüft benötigte Secrets
2. Falls fehlend: User wird aufgefordert, Secrets hinterlegen
3. User hinterlegt Secrets in Settings
4. Tool kann auf Secrets zugreifen

### 5.3 Asynchrone Sub-Agenten Workflow
1. Orchestrator delegiert an Sub-Agenten
2. ExecuteSubAgentMessage wird erstellt
3. Worker verarbeitet die Message asynchron
4. Ergebnis wird dem User zugestellt

### 5.4 Geplante Aufgaben Workflow
1. User sagt: "Prüfe um 9:00 meine Mails"
2. ScheduledTask wird erstellt
3. Cron-Job führt die Aufgabe aus
4. Ergebnis wird dem User zugestellt

---

## 6. Symfony AI Native Integration

Alle Erweiterungen nutzen die native Symfony AI Architektur:
- Dynamic Toolbox für dynamische Tools
- HITL über ToolCallRequested-Event
- Subagents als native Tools
- Platform-Interface für LLM-Integration

---

## 7. Konfiguration

### 7.1 ai.yaml
```yaml
ai:
    platform:
        mistral: { api_key: '%env(MISTRAL_API_KEY)%' }
        openai: { api_key: '%env(OPENAI_API_KEY)%' }
        google: { api_key: '%env(GOOGLE_API_KEY)%' }
        anthropic: { api_key: '%env(ANTHROPIC_API_KEY)%' }
```

### 7.2 messenger.yaml
```yaml
framework:
    messenger:
        transports: { async: '%env(MESSENGER_TRANSPORT_DSN)%' }
        routing:
            'App\Message\ExecuteSubAgentMessage': async
            'App\Message\SubAgentResultMessage': sync
```

---

## 8. Sicherheitskonzept

### 8.1 API-Key-Sicherheit
- AES-256-CBC Verschlüsselung
- User-spezifische Keys
- Keine Keys in Logs

### 8.2 Berechtigungen
- User können nur ihre eigenen Daten verwalten
- Admin kann alle Daten sehen
- SecurityGuard prüft alle Tool-Aufrufe

---

## 9. Erfolgskriterien v0.9.5

- [ ] User kann zwischen mindestens 5 LLM-Anbietern wählen
- [ ] User kann eigene API-Keys hinterlegen
- [ ] User kann Secrets für Tools hinterlegen
- [ ] Sub-Agenten arbeiten asynchron
- [ ] User kann geplante Aufgaben erstellen
- [ ] User kann natürliche Unterhaltungen führen
- [ ] Alle Funktionen sind Symfony-AI-kompatibel
- [ ] 100% Testabdeckung für neue Funktionen

---

*Blueprint v0.9.5 erstellt am 19.08.2025*
*Detaillierte Implementierung siehe: IMPLEMENTIERUNGSPLAN_V0.9.5.md*
