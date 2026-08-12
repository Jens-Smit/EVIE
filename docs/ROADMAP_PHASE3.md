# EVIE Roadmap: Phase 3 – Implementierungsplan

**Erstellt am:** 12. August 2026  
**Letzte Aktualisierung:** 12. August 2026  
**Repository:** [Jens-Smit/EVIE](https://github.com/Jens-Smit/EVIE)  
**Status:** 🟡 **In Bearbeitung (Maßnahme 8 gestartet)**  
**Ziel:** Umsetzung der Maßnahmen aus der [EVIE_ANALYSE.md](EVIE_ANALYSE.md) unter Berücksichtigung der **Symfony AI Bundle-Dokumentation** (v0.12.0, Stand August 2026).

---

## 🎯 **Zusammenfassung der Phase 3**

Phase 3 konzentriert sich auf die **Optimierung der LLM-Prompts**, die **Erstellung von E2E-Tests für den Evolution-Flow** und die **Verbesserung des Onboarding-Prozesses**. Diese Maßnahmen sind als **mittlere Priorität** eingestuft und sollen die **Qualität der Tool-Generierung, die Benutzerfreundlichkeit und die Zuverlässigkeit des Systems** erhöhen.

**Dauer:** 3–4 Wochen  
**Aufwand:** ~7–8 Tage  
**Verantwortlich:** Jens Smit  

---

## 📌 **Aktueller Status der Maßnahmen**

| **Maßnahme** | **Priorität** | **Aufwand** | **Status** | **Fortschritt** | **Startdatum** |
|--------------|---------------|-------------|------------|-----------------|----------------|
| **8. LLM-Prompt-Optimierung** | Mittel | 2–3 Tage | 🟡 **IN BEARBEITUNG** | **20%** | 12.08.2026 |
| 9. E2E-Test für Evolution-Flow | Mittel | 2–3 Tage | ⏳ Geplant | 0% | - |
| 10. Onboarding-Prompt optimieren | Mittel | 1–2 Tage | ⏳ Geplant | 0% | - |

---

## 📋 **Maßnahmen im Detail**

---

## **🟡 Maßnahme 8: LLM-Prompt-Optimierung** *(**IN BEARBEITUNG – DOKUMENTATION AUSFÜHRLICH**)*

**Priorität:** Mittel | **Aufwand:** 2–3 Tage | **Status:** 🟡 **In Bearbeitung** | **Startdatum:** 12. August 2026 | **Fortschritt:** 20%

---

### **📌 1. Zielsetzung**

**Primäres Ziel:**
Optimierung der LLM-Prompts für **bessere Tool-Schemata** und **präzisere Antworten** durch Nutzung der **Symfony AI Bundle-Features** für System Prompts, File-Based Prompts und Message Templates.

**Sekundäre Ziele:**
- **Wiederverwendbarkeit:** Zentrale Verwaltung aller Prompts in Dateien
- **Wartbarkeit:** Einfache Anpassung und Versionierung von Prompts
- **Mehrsprachigkeit:** Optionale Übersetzung der Prompts
- **Struktur:** Klare Trennung von Prompt-Logik und Code

---

### **📌 2. Hintergrund & Problemstellung (aus EVIE_ANALYSE.md)**

#### **Aktuelle Probleme:**
1. **Generische Tool-Schemata:**
   - Tool-Definitionen werden ohne klare Struktur generiert
   - Fehlende Validierung der JSON-Schemata
   - Unvollständige oder fehlerhafte Property-Definitionen

2. **Fehlende LLM-Prompt-Optimierung für Onboarding:**
   - Onboarding-Prozess sammelt User-Daten unstrukturiert
   - Keine klare Anleitung für den LLM, welche Daten benötigt werden
   - Resultat: Unvollständige oder inkonsistente User-Profile

3. **Keine zentrale Prompt-Verwaltung:**
   - Prompts sind hardcoded in den Services
   - Schlechte Wartbarkeit und Versionierung
   - Keine Wiederverwendung von Prompts

4. **Fehlende Sicherheitshinweise in Prompts:**
   - Keine expliziten Anweisungen zur Validierung von Inputs
   - Keine Sicherheitsüberlegungen für Tool-Parameter

#### **Lösungsansatz:**
- **Strukturierte Prompts** mit klaren Anweisungen, Beispielen und Validierungsregeln
- **File-Based Prompts** für bessere Organisation und Versionierung
- **Nutzung von Symfony AI Bundle-Features** (`include_tools: true`, Translation Support)
- **Integration von Sicherheitshinweisen** in alle Prompts

---

### **📌 3. Symfony AI Bundle-Unterstützung**

Das [Symfony AI Bundle](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) bietet folgende **relevante Features** für die Prompt-Optimierung:

| **Feature** | **Beschreibung** | **Vorteile für EVIE** | **Beispiel** |
|-------------|------------------|------------------------|--------------|
| **System Prompt Configuration** | Einfache String- oder erweiterte Array-Syntax | Flexible Prompt-Gestaltung | `prompt: { text: '...', include_tools: true }` |
| **File-Based Prompts** | Externe Prompt-Dateien (.txt, .json, .md) | Bessere Organisation & Versionierung | `prompt: { file: '%kernel.project_dir%/config/prompts/tool.txt' }` |
| **Translation Support** | Übersetzte Prompts für mehrsprachige Anwendungen | Internationale Nutzung | `enable_translation: true` |
| **Message Template Support** | Strukturierte Prompts mit Variablen | Dynamische Prompts | Benötigt `symfony/expression-language` |
| **`include_tools: true`** | Automatische Einbindung von Tool-Definitionen | Konsistente Tool-Beschreibungen | `include_tools: true` |

---

### **📌 4. Umsetzungsschritte (Detailliert)**

#### **🟢 Schritt 1: Verzeichnisstruktur erstellen** *(✅ ABGESCHLOSSEN – 12.08.2026)*

**Aufwand:** 0.5 Tage | **Status:** ✅ **100% Erledigt** | **Verantwortlich:** Jens Smit

**Beschreibung:**
Erstelle das Verzeichnis `config/prompts/` für die zentrale Ablage aller Prompt-Dateien. Dieses Verzeichnis wird alle Prompts für verschiedene Use-Cases enthalten.

**Umsetzung:**
```bash
# Verzeichnis erstellen
mkdir -p config/prompts

# .gitkeep hinzufügen (optional, um leere Verzeichnisse zu versionieren)
touch config/prompts/.gitkeep
```

**Ergebnis:**
✅ Verzeichnis `config/prompts/` existiert
✅ Bereit für die Ablage von Prompt-Dateien
✅ Versioniert in Git

**Validierung:**
```bash
ls -la config/prompts/
# Ausgabe sollte zeigen: .gitkeep
```

---

#### **🟡 Schritt 2: Prompt-Dateien erstellen** *(🟡 IN BEARBEITUNG – 50% FERTIG)*

**Aufwand:** 1 Tag | **Status:** 🟡 **50% Erledigt** | **Verantwortlich:** Jens Smit

**Beschreibung:**
Erstelle strukturierte Prompt-Dateien für verschiedene Use-Cases in EVIE. Jeder Prompt sollte klar definiert sein mit:
- Rolle und Ziel des LLMs
- Klare Anweisungen
- Beispiele
- Validierungsregeln
- Sicherheitshinweise

**Erstellte Prompts:**

---

##### **📄 2.1 Tool-Schema-Optimierer (`config/prompts/tool_schema_optimizer.txt`)** *(✅ ERSTELLT & GETESTET)*

**Zweck:**
Generiert präzise JSON-Schemata für neue Tools basierend auf Benutzeranfragen. Dieser Prompt wird von `ToolDefinitionGenerator` verwendet.

**Inhalt:**
```text
# EVIE Tool Schema Generator Prompt
# Version: 1.0
# Letzte Aktualisierung: 12.08.2026
# Verantwortlich: Jens Smit

---

## 🎯 PRIMARY ROLE
You are an expert tool schema generator for the EVIE multi-agent system.
Your sole purpose is to create precise, well-structured, and executable JSON schemas
for AI tools based on user requests.

---

## 📋 CORE PRINCIPLES
1. ALWAYS generate valid JSON Schema (Draft 2020-12)
2. Every schema MUST be executable as a Symfony AI ToolInterface
3. Include comprehensive security considerations
4. Validate all inputs and outputs
5. Provide clear, actionable error messages

---

## 📦 REQUIRED SCHEMA STRUCTURE

```json
{
  "metadata": {
    "name": "tool_name_in_snake_case",
    "description": "Clear, concise description of the tool's purpose",
    "category": "tool_category",
    "version": "1.0",
    "author": "EVIE System",
    "created_at": "YYYY-MM-DD"
  },
  "schema": {
    "type": "object",
    "properties": {
      "property_name": {
        "type": "string|number|boolean|array|object|integer",
        "description": "Detailed description of this property",
        "example": "example_value",
        "enum": ["value1", "value2"], // if applicable
        "minimum": 0,            // for numbers
        "maximum": 100,         // for numbers
        "minLength": 1,        // for strings
        "maxLength": 255,      // for strings
        "pattern": "^[a-z]+$", // regex pattern
        "format": "uri|email|date-time", // for strings
        "items": {             // for arrays
          "type": "string"
        },
        "additionalProperties": false // to prevent unknown properties
      }
    },
    "required": ["required_property1", "required_property2"],
    "additionalProperties": false
  },
  "security": {
    "considerations": [
      "Consideration 1: Validate all user inputs",
      "Consideration 2: Implement rate limiting",
      "Consideration 3: Sanitize outputs"
    ],
    "required_permissions": ["permission1", "permission2"],
    "sensitive_data": ["api_key", "password"]
  },
  "examples": [
    {
      "description": "Example 1 description",
      "input": {"property1": "value1"},
      "output": {"result": "expected_result"}
    }
  ]
}
```

---

## 🎯 TOOL CATEGORIES
Use these categories for classification:

| Category | Description | Example Tools |
|----------|-------------|---------------|
| `data_processing` | Data analysis, transformation, cleaning | csv_analyzer, data_cleaner |
| `file_operations` | File system operations | file_reader, file_writer |
| `web_scraping` | Web content extraction | website_scraper, html_parser |
| `api_integration` | External API calls | rest_client, graphql_client |
| `code_generation` | Code creation and manipulation | code_generator, code_reviewer |
| `search` | Information retrieval | semantic_search, knowledge_base |
| `analysis` | Data/statistical analysis | statistical_analyzer, trend_analyzer |
| `conversion` | Format conversion | json_to_csv, pdf_to_text |
| `validation` | Data validation | schema_validator, input_validator |
| `utility` | General purpose tools | calculator, date_formatter |
| `mcp` | Model Context Protocol tools | mcp_filesystem, mcp_github |

---

## 📝 GENERATION GUIDELINES

### 1. Naming Convention
- Use **snake_case** for tool names (e.g., `website_scraper`)
- Names should be **descriptive and concise**
- Avoid generic names like `tool1` or `processor`

### 2. Description Requirements
- **Clear:** Describe what the tool does
- **Concise:** 1-2 sentences maximum
- **Actionable:** Start with a verb (e.g., "Scrapes content", "Analyzes data")
- **Specific:** Include key functionality

### 3. Property Definition
- **Type:** Always specify the correct JSON Schema type
- **Description:** Explain the property's purpose and expected format
- **Example:** Provide a realistic example value
- **Validation:** Include all relevant constraints (min/max, pattern, etc.)

### 4. Security Considerations
- **Input Validation:** Always validate user inputs
- **Rate Limiting:** Consider rate limits for external calls
- **Data Sanitization:** Sanitize all outputs
- **Permission Checks:** Verify required permissions
- **Sensitive Data:** Mask sensitive information in logs

### 5. Error Handling
- Include clear error messages for invalid inputs
- Specify expected error formats
- Provide examples of error responses

---

## 📚 EXAMPLES

### Example 1: Website Scraper
**User Request:** "Create a tool to scrape websites and extract specific content"

**Generated Schema:**
```json
{
  "metadata": {
    "name": "website_scraper",
    "description": "Scrapes content from a given URL and extracts specified elements using CSS selectors",
    "category": "web_scraping",
    "version": "1.0",
    "author": "EVIE System",
    "created_at": "2026-08-12"
  },
  "schema": {
    "type": "object",
    "properties": {
      "url": {
        "type": "string",
        "format": "uri",
        "description": "The URL to scrape (must be HTTP/HTTPS)",
        "example": "https://example.com/products",
        "pattern": "^https?://"
      },
      "selectors": {
        "type": "object",
        "description": "CSS selectors for content to extract",
        "additionalProperties": {
          "type": "string",
          "description": "CSS selector for specific content element"
        },
        "example": {
          "title": "h1",
          "price": ".price",
          "description": ".product-description"
        }
      },
      "max_depth": {
        "type": "integer",
        "minimum": 1,
        "maximum": 5,
        "default": 1,
        "description": "How many levels deep to follow links (1 = current page only)"
      },
      "timeout": {
        "type": "integer",
        "minimum": 5,
        "maximum": 120,
        "default": 30,
        "description": "Request timeout in seconds"
      },
      "user_agent": {
        "type": "string",
        "default": "EVIE-Bot/1.0 (+https://evie.example.com)",
        "description": "User agent string for HTTP requests"
      },
      "respect_robots_txt": {
        "type": "boolean",
        "default": true,
        "description": "Whether to respect robots.txt directives"
      }
    },
    "required": ["url"],
    "additionalProperties": false
  },
  "security": {
    "considerations": [
      "Validate URL format before processing",
      "Implement rate limiting (max 10 requests per minute)",
      "Respect robots.txt directives",
      "Sanitize extracted content to prevent XSS",
      "Limit response size to prevent memory issues"
    ],
    "required_permissions": ["web_access", "network_access"],
    "sensitive_data": ["url"]
  },
  "examples": [
    {
      "description": "Extract product information from an e-commerce site",
      "input": {
        "url": "https://example-shop.com/product/123",
        "selectors": {
          "title": "h1.product-title",
          "price": ".product-price",
          "description": ".product-description"
        }
      },
      "output": {
        "title": "Example Product",
        "price": "€99.99",
        "description": "This is an example product description."
      }
    }
  ]
}
```

### Example 2: CSV Data Analyzer
**User Request:** "Create a tool to analyze CSV files and provide statistics"

**Generated Schema:**
```json
{
  "metadata": {
    "name": "csv_analyzer",
    "description": "Analyzes CSV files and provides comprehensive statistics and insights",
    "category": "data_processing",
    "version": "1.0",
    "author": "EVIE System",
    "created_at": "2026-08-12"
  },
  "schema": {
    "type": "object",
    "properties": {
      "file_path": {
        "type": "string",
        "description": "Path to the CSV file (local or remote URL)",
        "example": "/data/sales_2026.csv"
      },
      "delimiter": {
        "type": "string",
        "default": ",",
        "enum": [",", ";", "\t", "|"],
        "description": "CSV field delimiter"
      },
      "header_row": {
        "type": "boolean",
        "default": true,
        "description": "Whether the first row contains headers"
      },
      "analyze": {
        "type": "array",
        "items": {
          "type": "string",
          "enum": ["statistics", "missing_values", "data_types", "correlations", "outliers", "duplicates"]
        },
        "default": ["statistics", "missing_values"],
        "description": "Types of analysis to perform"
      },
      "chunk_size": {
        "type": "integer",
        "minimum": 100,
        "maximum": 10000,
        "default": 1000,
        "description": "Number of rows to process at once (for large files)"
      }
    },
    "required": ["file_path"],
    "additionalProperties": false
  },
  "security": {
    "considerations": [
      "Validate file path to prevent directory traversal attacks",
      "Limit file size to 100MB to prevent memory issues",
      "Sanitize file content before processing",
      "Use streaming for large files to avoid memory overload"
    ],
    "required_permissions": ["file_read", "data_processing"],
    "sensitive_data": []
  },
  "examples": [
    {
      "description": "Basic analysis of a sales CSV file",
      "input": {
        "file_path": "/data/sales.csv",
        "analyze": ["statistics", "missing_values"]
      },
      "output": {
        "row_count": 1000,
        "column_count": 5,
        "statistics": {
          "total_sales": 50000,
          "average_sale": 50.00
        },
        "missing_values": {
          "customer_email": 5,
          "product_category": 2
        }
      }
    }
  ]
}
```

### Example 3: API Request Tool
**User Request:** "Create a tool to make authenticated HTTP requests to REST APIs"

**Generated Schema:**
```json
{
  "metadata": {
    "name": "api_request",
    "description": "Makes authenticated HTTP requests to REST APIs with configurable parameters",
    "category": "api_integration",
    "version": "1.0",
    "author": "EVIE System",
    "created_at": "2026-08-12"
  },
  "schema": {
    "type": "object",
    "properties": {
      "method": {
        "type": "string",
        "enum": ["GET", "POST", "PUT", "PATCH", "DELETE"],
        "description": "HTTP method to use",
        "example": "GET"
      },
      "url": {
        "type": "string",
        "format": "uri",
        "description": "API endpoint URL",
        "example": "https://api.example.com/v1/data"
      },
      "headers": {
        "type": "object",
        "description": "HTTP headers to include in the request",
        "additionalProperties": {
          "type": "string"
        },
        "example": {
          "Content-Type": "application/json",
          "Accept": "application/json"
        }
      },
      "body": {
        "type": ["object", "string"],
        "description": "Request body for POST/PUT/PATCH methods",
        "example": {"key": "value"}
      },
      "query_params": {
        "type": "object",
        "description": "Query parameters to append to the URL",
        "additionalProperties": {
          "type": ["string", "number", "boolean"]
        },
        "example": {"page": 1, "limit": 10}
      },
      "auth": {
        "type": "object",
        "description": "Authentication configuration",
        "properties": {
          "type": {
            "type": "string",
            "enum": ["bearer", "basic", "api_key", "none"],
            "description": "Authentication type",
            "example": "bearer"
          },
          "token": {
            "type": "string",
            "description": "Authentication token (will be masked in logs)",
            "example": "sk_***REDACTED***"
          },
          "username": {
            "type": "string",
            "description": "Username for basic auth"
          },
          "password": {
            "type": "string",
            "description": "Password for basic auth (will be masked in logs)"
          }
        },
        "required": ["type"],
        "additionalProperties": false
      },
      "timeout": {
        "type": "integer",
        "minimum": 1,
        "maximum": 120,
        "default": 30,
        "description": "Request timeout in seconds"
      },
      "retry_attempts": {
        "type": "integer",
        "minimum": 0,
        "maximum": 5,
        "default": 2,
        "description": "Number of retry attempts on failure"
      },
      "retry_delay": {
        "type": "integer",
        "minimum": 100,
        "maximum": 10000,
        "default": 1000,
        "description": "Delay between retries in milliseconds"
      }
    },
    "required": ["method", "url"],
    "additionalProperties": false
  },
  "security": {
    "considerations": [
      "Validate URL to prevent SSRF attacks",
      "Mask sensitive headers (like Authorization) in logs",
      "Implement rate limiting per API endpoint",
      "Validate response size to prevent DoS attacks",
      "Use HTTPS for all requests",
      "Never log authentication tokens or passwords"
    ],
    "required_permissions": ["network_access", "api_integration"],
    "sensitive_data": ["token", "password", "headers.Authorization"]
  },
  "examples": [
    {
      "description": "GET request to a public API",
      "input": {
        "method": "GET",
        "url": "https://api.example.com/v1/users",
        "headers": {
          "Accept": "application/json"
        },
        "query_params": {
          "page": 1,
          "limit": 10
        }
      },
      "output": {
        "status": 200,
        "data": [
          {"id": 1, "name": "User 1"},
          {"id": 2, "name": "User 2"}
        ]
      }
    },
    {
      "description": "POST request with authentication",
      "input": {
        "method": "POST",
        "url": "https://api.example.com/v1/data",
        "headers": {
          "Content-Type": "application/json"
        },
        "body": {"name": "New Item", "value": 100},
        "auth": {
          "type": "bearer",
          "token": "sk_***REDACTED***"
        }
      },
      "output": {
        "status": 201,
        "data": {"id": 123, "name": "New Item", "value": 100}
      }
    }
  ]
}
```

---

## 🔍 CONTEXT INFORMATION
The following context is available for generating tool schemas:

- **Available tools:** {{ tools|join(', ') }}
- **User request:** {{ request }}
- **Agent type:** {{ agent_type }}
- **Current date:** {{ current_date }}
- **EVIE version:** {{ evie_version }}

---

## 📝 INSTRUCTIONS
1. **Analyze the user request carefully** - Understand the exact requirement
2. **Determine the most appropriate tool category** - Choose from the predefined categories
3. **Generate a complete JSON schema** - Include all required fields and validations
4. **Add comprehensive security considerations** - Think about potential vulnerabilities
5. **Provide clear examples** - Show realistic input/output examples
6. **Validate your schema** - Ensure it's valid JSON Schema (Draft 2020-12)
7. **Consider edge cases** - Handle potential errors and special cases

**Generate the most appropriate tool schema for the user's request.**
```

**Status:** ✅ **Erstellt und getestet**
**Verwendung:** Wird in `ToolDefinitionGenerator` integriert
**Test-Ergebnis:**
- ✅ JSON-Schema-Validierung erfolgreich
- ✅ Beispiele sind klar und nachvollziehbar
- ✅ Sicherheitshinweise sind enthalten

---

##### **📄 2.2 Agenten-Konfigurierer (`config/prompts/agent_configurator.md`)** *(✅ ERSTELLT & GETESTET)*

**Zweck:**
Hilft bei der Konfiguration neuer Agenten für das EVIE-System. Dieser Prompt wird für die interne Agenten-Verwaltung verwendet.

**Inhalt:**
```markdown
# EVIE Agent Configuration Assistant
# Version: 1.0
# Letzte Aktualisierung: 12.08.2026

---

## 🎯 YOUR ROLE
You are an expert in configuring AI agents for the EVIE multi-agent system.
Your task is to help users and developers create optimal agent configurations
that integrate seamlessly with the Symfony AI Bundle.

---

## 📋 AGENT CONFIGURATION STRUCTURE

Every EVIE agent must follow this structure in the `config/packages/ai.yaml` file:

```yaml
ai:
  agent:
    agent_name:
      # Platform Configuration
      platform: 'ai.platform.PLATFORM_NAME'  # Required
      model: 'MODEL_NAME'                    # Required
      
      # Prompt Configuration (choose one)
      prompt: 'Simple system prompt text'      # Option 1: Simple string
      # OR
      prompt:                              # Option 2: Advanced configuration
        text: 'System prompt text'          # Required
        file: '%kernel.project_dir%/config/prompts/AGENT_PROMPT.txt'  # Alternative
        include_tools: true                # Include tool definitions in prompt
        enable_translation: false            # Enable translation support
        translation_domain: 'ai_prompts'    # Translation domain
      
      # Memory Configuration (optional)
      memory: 'Static memory content'       # Option 1: Simple string
      # OR
      memory:                             # Option 2: Service-based
        service: 'memory_provider_service'
      
      # Tool Configuration
      tools: true                          # Use all registered tools
      # OR
      tools:                              # Specific tools
        - 'Symfony\AI\Agent\Bridge\SimilaritySearch\SimilaritySearch'
        # OR reference another agent as a tool
        - agent: 'research_agent'
          name: 'wikipedia_research'
          description: 'Can research on Wikipedia'
      
      # Execution Settings
      max_tool_calls: 50                    # Maximum tool calls per conversation
      fault_tolerant_toolbox: true          # Enable fault-tolerant tool execution
      exclude_tool_messages: false          # Include tool messages in history
```

---

## 🎯 PLATFORM SELECTION GUIDE

Choose the appropriate platform based on your requirements:

| Platform | Best For | Models | Notes |
|----------|----------|--------|-------|
| `ai.platform.mistral` | General purpose, coding, reasoning | mistral-small, mistral-large, codestral | Good balance of speed and quality |
| `ai.platform.anthropic` | Complex reasoning, analysis | claude-3-haiku, claude-3-sonnet, claude-3-opus | Best for complex tasks |
| `ai.platform.openai` | Maximum compatibility, features | gpt-4o-mini, gpt-4o, gpt-3.5-turbo | Most features available |
| `ai.platform.gemini` | Multi-modal, creative tasks | gemini-1.5-flash, gemini-1.5-pro | Good for creative work |
| `ai.platform.ollama` | Local/private deployments | llama3:70b, mistral:7b | Run your own models |
| `ai.platform.generic` | Custom OpenAI-compatible APIs | Any | For custom endpoints |

---

## 📊 MODEL SELECTION GUIDE

### Mistral Models
- **mistral-small**: Fast, cost-effective, good for simple tasks
- **mistral-large**: More capable, better for complex tasks
- **codestral**: Optimized for coding tasks

### Anthropic Models
- **claude-3-haiku**: Fast, good for simple tasks
- **claude-3-sonnet**: Balanced, good for most tasks (recommended)
- **claude-3-opus**: Most capable, for complex tasks

### OpenAI Models
- **gpt-4o-mini**: Fast, cost-effective
- **gpt-4o**: Most capable, best for complex tasks
- **gpt-3.5-turbo**: Good balance, widely used

### Local Models (Ollama)
- **llama3:70b**: Large, capable model
- **mistral:7b**: Smaller, faster model
- **phi3:3.8b**: Lightweight, good for testing

---

## 🛠 PROMPT CONFIGURATION BEST PRACTICES

### 1. Simple vs. Advanced Prompts

**Use Simple Prompts for:**
- Short, straightforward system messages
- Quick prototyping
- Simple agents with basic functionality

```yaml
prompt: 'You are a helpful assistant that answers questions about EVIE.'
```

**Use Advanced Prompts for:**
- Complex system messages with multiple sections
- Prompts that need to be versioned or reused
- Multi-language support
- Prompts with examples or special formatting

```yaml
prompt:
  text: 'You are a helpful assistant...'
  include_tools: true
  enable_translation: true
  translation_domain: 'ai_prompts'
```

### 2. File-Based Prompts

**Benefits:**
- Better organization and version control
- Reusability across multiple agents
- Easier to maintain and update
- Can be very long and detailed

**Example:**
```yaml
prompt:
  file: '%kernel.project_dir%/config/prompts/detailed_agent_prompt.txt'
```

**File Content Example (`config/prompts/detailed_agent_prompt.txt`):**
```text
You are an expert EVIE agent with the following capabilities:

## Primary Responsibilities
1. [Responsibility 1]
2. [Responsibility 2]

## Guidelines
- Guideline 1
- Guideline 2

## Examples
### Example 1
User: [Input]
Assistant: [Expected Response]

## Security Considerations
- Consideration 1
- Consideration 2
```

### 3. Translation Support

**Requirements:**
- Install `symfony/translation` component
- Create translation files

**Configuration:**
```yaml
prompt:
  text: 'agent.system_prompt'  # Translation key
  enable_translation: true
  translation_domain: 'ai_prompts'  # Optional
```

**Translation File Example (`translations/ai_prompts.de.yaml`):**
```yaml
agent:
  system_prompt: |
    Du bist ein hilfreicher Assistent für das EVIE-System.
    Deine Aufgabe ist es, präzise und hilfreiche Antworten zu geben.
```

---

## 🔧 TOOL CONFIGURATION BEST PRACTICES

### 1. Tool Selection Strategies

**Option 1: Use All Tools (`tools: true`)**
- Gives the agent access to all registered tools
- Good for general-purpose agents
- May include unnecessary tools

```yaml
tools: true
```

**Option 2: Specific Tools**
- Explicitly list the tools the agent can use
- More control over agent capabilities
- Better for specialized agents

```yaml
tools:
  - 'Symfony\AI\Agent\Bridge\SimilaritySearch\SimilaritySearch'
  - 'App\AI\Agent\Tool\CustomTool'
```

**Option 3: Service-Based Tools**
- Reference tools by service ID
- Can customize name and description

```yaml
tools:
  - service: 'App\AI\Agent\Tool\CustomTool'
    name: 'custom_tool'
    description: 'A custom tool for specific tasks'
    method: '__invoke'  # Optional, defaults to '__invoke'
```

### 2. Agent-as-Tool Pattern

Use another agent as a tool for complex tasks:

```yaml
tools:
  - agent: 'research_agent'
    name: 'wikipedia_research'
    description: 'Can research information on Wikipedia'
```

**When to Use:**
- When you need specialized capabilities
- When the task is complex and requires delegation
- When you want to maintain a clear separation of concerns

### 3. Tool Execution Settings

```yaml
max_tool_calls: 50          # Limit tool calls to prevent infinite loops
fault_tolerant_toolbox: true # Continue if a tool fails (recommended)
exclude_tool_messages: false # Include tool messages in conversation history
```

---

## 💡 MEMORY CONFIGURATION

### 1. Static Memory
Simple memory content that's always available:

```yaml
memory: 'You have access to conversation history and user preferences.'
```

### 2. Dynamic Memory
Use a service to provide dynamic memory:

```yaml
memory:
  service: 'App\AI\Agent\Memory\UserMemoryProvider'
```

**Memory Provider Interface:**
```php
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;

class UserMemoryProvider implements MemoryProviderInterface
{
    public function load(Input $input): array
    {
        return [
            new Memory('User ID: ' . $input->getUserId()),
            new Memory('User preferences: ' . json_encode($this->getPreferences($input))),
        ];
    }
}
```

---

## 📝 CONFIGURATION EXAMPLES

### Example 1: Research Agent
```yaml
ai:
  agent:
    researcher:
      platform: 'ai.platform.anthropic'
      model: 'claude-3-7-sonnet-20250219'
      prompt:
        text: 'You are a research assistant. Provide detailed, well-sourced information.'
        include_tools: true
      tools:
        - 'Symfony\AI\Agent\Bridge\Wikipedia\Wikipedia'
        - service: 'App\AI\Agent\Tool\WebSearch'
          name: 'web_search'
          description: 'Search the web for information'
      max_tool_calls: 25
      fault_tolerant_toolbox: true
```

### Example 2: Code Generation Agent
```yaml
ai:
  agent:
    code_generator:
      platform: 'ai.platform.mistral'
      model: 'codestral-latest'
      prompt:
        file: '%kernel.project_dir%/config/prompts/code_generator.txt'
        include_tools: true
      tools:
        - service: 'App\AI\Agent\Tool\CodeFormatter'
        - service: 'App\AI\Agent\Tool\TestGenerator'
      max_tool_calls: 15
```

### Example 3: Multi-Language Agent
```yaml
ai:
  agent:
    multilingual_assistant:
      platform: 'ai.platform.openai'
      model: 'gpt-4o'
      prompt:
        text: 'agent.multilingual_prompt'
        enable_translation: true
        translation_domain: 'ai_prompts'
      memory:
        service: 'App\AI\Agent\Memory\UserMemoryProvider'
      tools: true
```

---

## 🔍 CURRENT CONTEXT
- **User request:** {{ user_request }}
- **Available platforms:** {{ available_platforms|join(', ') }}
- **Available models:** {{ available_models|join(', ') }}
- **Existing agents:** {{ existing_agents|join(', ') }}
- **EVIE version:** {{ evie_version }}

---

## 📝 INSTRUCTIONS
1. **Understand the user's requirements** - What does the user want the agent to do?
2. **Select the appropriate platform** - Which AI platform is most suitable?
3. **Choose the right model** - Which model offers the best capabilities for the task?
4. **Configure the prompt** - What system instructions does the agent need?
5. **Select tools** - Which tools should the agent have access to?
6. **Set execution limits** - What are appropriate limits for this agent?
7. **Consider memory** - Does the agent need access to conversation history or user data?

**Generate an optimal agent configuration based on the user's requirements.**
```

**Status:** ✅ **Erstellt und getestet**
**Verwendung:** Wird für interne Agenten-Konfiguration verwendet

---

##### **📄 2.3 Onboarding-Prompt (`config/prompts/onboarding_prompt.json`)** *(🟡 IN ARBEIT – 30% FERTIG)*

**Zweck:**
Strukturiertes Onboarding für neue Benutzer. Sammelt präzise User-Informationen für eine personalisierte EVIE-Erfahrung.

**Inhalt (Aktueller Stand):**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "EVIE Onboarding Prompt",
  "description": "Structured prompt for collecting user information during EVIE onboarding",
  "version": "1.0",
  "created_at": "2026-08-12",
  "author": "Jens Smit",
  
  "metadata": {
    "role": "You are an expert onboarding assistant for the EVIE multi-agent system.",
    "goal": "Collect precise user information to personalize the AI agent experience and optimize tool recommendations.",
    "personality": "Professional, helpful, and patient. Adapt to the user's technical level.",
    "conversation_style": "Natural, engaging, and efficient. Ask one question at a time."
  },
  
  "instructions": [
    "Greet the user warmly and introduce yourself as EVIE",
    "Explain the purpose of the onboarding process (2-3 sentences)",
    "Ask one question at a time - do not overwhelm the user",
    "Validate user responses before proceeding to the next question",
    "If a response is unclear or incomplete, ask for clarification",
    "Store all collected information in the provided memory context",
    "Provide examples when the user seems unsure",
    "Keep the conversation flowing naturally",
    "After collecting all information, provide a summary and ask for confirmation",
    "If the user wants to skip a question, note it as 'not specified' and continue"
  ],
  
  "flow": {
    "type": "sequential",
    "steps": [
      {
        "id": "greeting",
        "type": "message",
        "content": "Hello! I'm EVIE, your AI assistant. I'll help you get started with our multi-agent system. This will only take a few minutes. What is your full name?"
      },
      {
        "id": "name",
        "type": "collect",
        "field": "user_profile.name",
        "question": "What is your full name?",
        "validation": {
          "type": "string",
          "minLength": 2,
          "maxLength": 100,
          "pattern": "^[a-zA-Z\\s\\-\\.]+$",
          "error_message": "Please enter a valid name (2-100 characters, letters, spaces, hyphens, and periods only)"
        },
        "example": "John Doe"
      },
      {
        "id": "email",
        "type": "collect",
        "field": "user_profile.email",
        "question": "What is your email address?",
        "validation": {
          "type": "string",
          "format": "email",
          "error_message": "Please enter a valid email address"
        },
        "example": "john.doe@example.com"
      },
      {
        "id": "primary_role",
        "type": "collect",
        "field": "user_profile.primary_role",
        "question": "What is your primary role?",
        "options": [
          {"value": "Developer", "description": "I write code and develop software"},
          {"value": "DevOps Engineer", "description": "I manage infrastructure and deployments"},
          {"value": "Data Scientist", "description": "I analyze data and build models"},
          {"value": "Business Analyst", "description": "I analyze business processes and requirements"},
          {"value": "Project Manager", "description": "I manage projects and teams"},
          {"value": "Student", "description": "I'm learning about AI and development"},
          {"value": "Other", "description": "My role isn't listed above"}
        ],
        "validation": {
          "type": "string",
          "enum": ["Developer", "DevOps Engineer", "Data Scientist", "Business Analyst", "Project Manager", "Student", "Other"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "technical_skills",
        "type": "collect",
        "field": "user_profile.technical_skills",
        "question": "What are your technical skills? (Select all that apply, or type your own)",
        "options": [
          "PHP", "Symfony", "JavaScript", "TypeScript", "Python", "Java", "C#", ".NET",
          "Go", "Rust", "DevOps", "Docker", "Kubernetes", "AWS", "Azure", "GCP",
          "AI/ML", "Data Analysis", "Database Design", "UI/UX Design", "None"
        ],
        "multiple": true,
        "allow_custom": true,
        "validation": {
          "type": "array",
          "items": {"type": "string"},
          "minItems": 1,
          "error_message": "Please select at least one skill or add your own"
        }
      },
      {
        "id": "experience_level",
        "type": "collect",
        "field": "user_profile.experience_level",
        "question": "What is your experience level with AI/ML technologies?",
        "options": [
          {"value": "Beginner", "description": "I'm just starting to learn about AI"},
          {"value": "Intermediate", "description": "I have some experience with AI tools"},
          {"value": "Advanced", "description": "I regularly use AI in my work"},
          {"value": "Expert", "description": "I'm an AI specialist"}
        ],
        "validation": {
          "type": "string",
          "enum": ["Beginner", "Intermediate", "Advanced", "Expert"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "primary_use_case",
        "type": "collect",
        "field": "user_profile.primary_use_case",
        "question": "What is your primary use case for EVIE?",
        "options": [
          {"value": "Code Generation", "description": "Generate and improve code"},
          {"value": "Business Process Automation", "description": "Automate repetitive business tasks"},
          {"value": "Data Analysis", "description": "Analyze and visualize data"},
          {"value": "Document Processing", "description": "Process and extract information from documents"},
          {"value": "Research Assistance", "description": "Find and summarize information"},
          {"value": "Customer Support", "description": "Provide AI-powered customer support"},
          {"value": "Content Creation", "description": "Create articles, reports, or other content"},
          {"value": "Other", "description": "My use case isn't listed above"}
        ],
        "validation": {
          "type": "string",
          "enum": ["Code Generation", "Business Process Automation", "Data Analysis", "Document Processing", "Research Assistance", "Customer Support", "Content Creation", "Other"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "specific_needs",
        "type": "collect",
        "field": "user_profile.specific_needs",
        "question": "Describe your specific needs or problems you want to solve with EVIE",
        "validation": {
          "type": "string",
          "minLength": 10,
          "maxLength": 500,
          "error_message": "Please provide a more detailed description (10-500 characters)"
        },
        "example": "I need to automate our invoice processing workflow to save time and reduce errors."
      },
      {
        "id": "response_style",
        "type": "collect",
        "field": "user_profile.preferences.response_style",
        "question": "Do you prefer concise or detailed responses?",
        "options": [
          {"value": "Concise", "description": "Short, direct answers"},
          {"value": "Detailed", "description": "Comprehensive, thorough explanations"},
          {"value": "Context-dependent", "description": "Adjust based on the situation"}
        ],
        "validation": {
          "type": "string",
          "enum": ["Concise", "Detailed", "Context-dependent"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "technical_depth",
        "type": "collect",
        "field": "user_profile.preferences.technical_depth",
        "question": "How technical should the responses be?",
        "options": [
          {"value": "Non-technical", "description": "Explain in simple, non-technical terms"},
          {"value": "Somewhat technical", "description": "Include some technical details"},
          {"value": "Very technical", "description": "Use technical language and concepts"}
        ],
        "validation": {
          "type": "string",
          "enum": ["Non-technical", "Somewhat technical", "Very technical"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "language",
        "type": "collect",
        "field": "user_profile.preferences.language",
        "question": "What language do you prefer for interactions?",
        "options": [
          {"value": "German", "description": "Deutsch"},
          {"value": "English", "description": "English"},
          {"value": "French", "description": "Français"},
          {"value": "Spanish", "description": "Español"},
          {"value": "Other", "description": "Another language"}
        ],
        "validation": {
          "type": "string",
          "enum": ["German", "English", "French", "Spanish", "Other"],
          "error_message": "Please select one of the provided options"
        }
      },
      {
        "id": "summary",
        "type": "message",
        "content": "Thank you! Let me summarize your information to make sure I have everything correct.\n\n[SUMMARY_WILL_BE_INSERTED_HERE]\n\nIs this information correct? (yes/no)"
      },
      {
        "id": "confirmation",
        "type": "confirm",
        "field": "user_profile.confirmed",
        "question": "Is the summarized information correct?",
        "validation": {
          "type": "boolean",
          "error_message": "Please answer with 'yes' or 'no'"
        },
        "on_yes": "Thank you! Your onboarding is complete. You can now start using EVIE.",
        "on_no": "Let's correct the information. Which part would you like to change?"
      }
    ]
  },
  
  "memory_structure": {
    "type": "object",
    "properties": {
      "user_profile": {
        "type": "object",
        "properties": {
          "name": {
            "type": "string",
            "description": "User's full name"
          },
          "email": {
            "type": "string",
            "format": "email",
            "description": "User's email address"
          },
          "primary_role": {
            "type": "string",
            "description": "User's primary professional role"
          },
          "technical_skills": {
            "type": "array",
            "items": {"type": "string"},
            "description": "User's technical skills"
          },
          "experience_level": {
            "type": "string",
            "description": "User's experience level with AI/ML"
          },
          "primary_use_case": {
            "type": "string",
            "description": "Primary intended use case for EVIE"
          },
          "specific_needs": {
            "type": "string",
            "description": "Detailed description of user's needs"
          },
          "preferences": {
            "type": "object",
            "properties": {
              "response_style": {
                "type": "string",
                "description": "Preferred response style"
              },
              "technical_depth": {
                "type": "string",
                "description": "Preferred technical depth"
              },
              "language": {
                "type": "string",
                "description": "Preferred interaction language"
              }
            }
          },
          "confirmed": {
            "type": "boolean",
            "description": "Whether the user confirmed their information"
          }
        },
        "required": ["name", "email", "primary_role", "primary_use_case"]
      }
    }
  },
  
  "validation_rules": {
    "required_fields": ["name", "email", "primary_role", "primary_use_case"],
    "conditional_requirements": {
      "if": {"primary_role": "Developer"},
      "then": {"required": ["technical_skills"]}
    }
  },
  
  "error_handling": {
    "on_validation_error": "Please provide a valid response. {error_message}",
    "on_skip": "Noted. We'll move to the next question.",
    "on_timeout": "It seems you're taking a while. Would you like to continue or skip this question?"
  },
  
  "success_message": "Excellent! Your onboarding is complete.\n\nBased on your information, I've personalized your EVIE experience:\n\n- **Recommended agents**: [AGENT_LIST_WILL_BE_INSERTED]\n- **Recommended tools**: [TOOL_LIST_WILL_BE_INSERTED]\n- **Language**: {language}\n- **Response style**: {response_style}\n\nYou can now start using EVIE. Type 'help' at any time to see available commands.",
  
  "context_variables": {
    "available_agents": ["researcher", "code_generator", "data_analyst", "document_processor", "general_assistant"],
    "available_tools": ["website_scraper", "csv_analyzer", "api_request", "file_reader", "code_formatter"],
    "evie_version": "1.0.0"
  }
}
```

**Status:** 🟡 **In Arbeit – Grundgerüst erstellt, Feinabstimmung nötig**
**Verwendung:** Wird in `OnboardingFlowManager` integriert
**Nächste Schritte:**
- [ ] Validierungslogik vervollständigen
- [ ] Beispielkonversationen hinzufügen
- [ ] Sicherheitshinweise ergänzen

---

#### **📌 5. `ai.yaml` für File-Based Prompts anpassen** *(⏳ GEPLANT)*

**Aufwand:** 0.5 Tage | **Status:** ⏳ **Geplant** | **Startdatum:** 13.08.2026

**Beschreibung:**
Anpassung der `config/packages/ai.yaml` für die Nutzung der File-Based Prompts und Integration der neuen Agenten.

**Geplante Konfiguration:**

```yaml
# config/packages/ai.yaml
ai:
    # Plattform-Konfigurationen
    platform:
        mistral:
            api_key: '%env(MISTRAL_API_KEY)%'
            base_url: '%env(MISTRAL_BASE_URL)%'  # Optional für selbstgehostete Instanzen
        
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
        
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
        
        # Für lokale Entwicklung
        ollama:
            endpoint: '%env(OLLAMA_HOST_URL)%'

    # Agenten-Konfigurationen
    agent:
        # ============================================
        # Tool Generator Agent
        # Generiert Tool-Schemata basierend auf Benutzeranfragen
        # ============================================
        tool_generator:
            platform: 'ai.platform.mistral'
            model: 'mistral-large-latest'
            prompt:
                file: '%kernel.project_dir%/config/prompts/tool_schema_optimizer.txt'
                include_tools: true  # Fügt Tool-Definitionen automatisch hinzu
            tools: false  # Keine Tools nötig, da nur Schema-Generierung
            max_tool_calls: 0
            fault_tolerant_toolbox: false
            exclude_tool_messages: true

        # ============================================
        # Onboarding Agent
        # Führt neue Benutzer durch den Onboarding-Prozess
        # ============================================
        onboarding:
            platform: 'ai.platform.mistral'
            model: 'mistral-large-latest'
            prompt:
                file: '%kernel.project_dir%/config/prompts/onboarding_prompt.json'
                include_tools: false
            memory:
                service: 'App\AI\Onboarding\ContextMemoryProvider'
            tools: false
            max_tool_calls: 0

        # ============================================
        # Agent Configurator (Intern)
        # Hilft bei der Erstellung von Agenten-Konfigurationen
        # ============================================
        agent_configurator:
            platform: 'ai.platform.anthropic'
            model: 'claude-3-7-sonnet-20250219'
            prompt:
                file: '%kernel.project_dir%/config/prompts/agent_configurator.md'
                include_tools: true
            tools:
                - service: 'App\AI\Agent\AgentConfigurator'
                  name: 'agent_configurator'
                  description: 'Helps configure new agents'
            max_tool_calls: 10

        # ============================================
        # Orchestrator (Bestehend, Anpassung)
        # Haupt-Agent, der Aufgaben an Sub-Agenten delegiert
        # ============================================
        orchestrator:
            platform: 'ai.platform.mistral'
            model: 'mistral-large-latest'
            prompt:
                text: |
                    You are the main orchestrator for the EVIE multi-agent system.
                    Your task is to analyze user requests and delegate them to the most appropriate sub-agent.
                    
                    Guidelines:
                    - Analyze the user's intent carefully
                    - Select the most appropriate sub-agent for the task
                    - Provide clear instructions to the sub-agent
                    - If no sub-agent is suitable, handle the request yourself
                    - Always include relevant context in your delegation
                include_tools: true
            tools: true  # Zugriff auf alle registrierten Tools
            max_tool_calls: 50
            fault_tolerant_toolbox: true
            exclude_tool_messages: false

    # ============================================
    # Store-Konfigurationen (bestehend, unverändert)
    # ============================================
    store:
        chromadb:
            default:
                collection: 'evie_documents'
        memory:
            default:
                strategy: 'cosine'

    # ============================================
    # Vectorizer-Konfigurationen (bestehend, unverändert)
    # ============================================
    vectorizer:
        mistral_embeddings:
            platform: 'ai.platform.mistral'
            model: 'mistral-embed'

    # ============================================
    # Indexer-Konfigurationen (bestehend, unverändert)
    # ============================================
    indexer:
        default:
            vectorizer: 'ai.vectorizer.mistral_embeddings'
            store: 'ai.store.chromadb.default'
```

**Abhängigkeiten:**
- `symfony/translation` (optional für Translation Support)
- `symfony/expression-language` (optional für Message Templates)

**Validierung:**
```bash
# Konfiguration testen
php bin/console config:dump-reference ai

# Agenten testen
php bin/console ai:agent:call tool_generator
php bin/console ai:agent:call onboarding
```

---

#### **📌 6. Integration in bestehehende Services** *(⏳ GEPLANT)*

**Aufwand:** 0.5 Tage | **Status:** ⏳ **Geplant** | **Startdatum:** 14.08.2026

**Beschreibung:**
Integration der neuen Prompts in die bestehenden Services:
- `ToolDefinitionGenerator` → `tool_schema_optimizer.txt`
- `OnboardingFlowManager` → `onboarding_prompt.json`

---

##### **6.1 Anpassung von ToolDefinitionGenerator**

**Datei:** `src/AI/Skills/ToolDefinitionGenerator.php`

**Geplante Änderungen:**
```php
<?php

namespace App\AI\Skills;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\AgentRegistry;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

class ToolDefinitionGenerator
{
    private AgentRegistry $agentRegistry;
    private string $projectDir;
    private array $availableTools;

    public function __construct(AgentRegistry $agentRegistry, string $projectDir)
    {
        $this->agentRegistry = $agentRegistry;
        $this->projectDir = $projectDir;
        $this->availableTools = $this->loadAvailableTools();
    }

    /**
     * Generiert ein Tool-Schema basierend auf einer Benutzeranfrage
     */
    public function generateToolSchema(string $userRequest, array $context = []): array
    {
        $agent = $this->agentRegistry->get('tool_generator');
        
        // Lade den Prompt aus der Datei
        $promptTemplate = file_get_contents(
            $this->projectDir . '/config/prompts/tool_schema_optimizer.txt'
        );
        
        // Ersetze Platzhalter im Prompt
        $prompt = str_replace(
            [
                '{{ tools }}',
                '{{ request }}',
                '{{ agent_type }}',
                '{{ current_date }}'
            ],
            [
                implode(', ', $this->availableTools),
                $userRequest,
                'tool_generator',
                date('Y-m-d')
            ],
            $promptTemplate
        );
        
        // Erstelle Message mit System-Prompt und User-Request
        $messages = new MessageBag(
            Message::ofSystem($prompt),
            Message::ofUser($userRequest)
        );
        
        // Führe den Agenten aus
        $response = $agent->call($messages);
        
        // Validierung und Parsing
        return $this->validateAndParseSchema($response->getContent());
    }

    /**
     * Validiert und parst das generierte Schema
     */
    private function validateAndParseSchema(string $content): array
    {
        // Extrahiere JSON aus der Antwort (falls in Markdown eingebettet)
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = $matches[1];
        }
        
        $schema = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Invalid JSON schema generated: ' . json_last_error_msg()
            );
        }
        
        // Validierung der Schema-Struktur
        $this->validateSchemaStructure($schema);
        
        return $schema;
    }

    /**
     * Validiert die Struktur des Schemas
     */
    private function validateSchemaStructure(array $schema): void
    {
        // Prüfe erforderliche Felder
        if (!isset($schema['metadata']['name'])) {
            throw new \RuntimeException('Missing required field: metadata.name');
        }
        
        if (!isset($schema['metadata']['description'])) {
            throw new \RuntimeException('Missing required field: metadata.description');
        }
        
        if (!isset($schema['schema']['type']) || $schema['schema']['type'] !== 'object') {
            throw new \RuntimeException('Schema type must be "object"');
        }
        
        if (!isset($schema['schema']['properties']) || !is_array($schema['schema']['properties'])) {
            throw new \RuntimeException('Schema must have properties');
        }
        
        // Prüfe Sicherheitshinweise
        if (!isset($schema['security']['considerations']) || 
            !is_array($schema['security']['considerations']) ||
            count($schema['security']['considerations']) === 0) {
            throw new \RuntimeException('Security considerations are required');
        }
    }

    /**
     * Lädt die verfügbaren Tools
     */
    private function loadAvailableTools(): array
    {
        // Hier würde die Logik stehen, um verfügbare Tools zu laden
        // (z. B. aus der Datenbank oder Container)
        return [
            'website_scraper',
            'csv_analyzer',
            'api_request',
            'file_reader',
            'code_formatter'
        ];
    }
}
```

---

##### **6.2 Anpassung von OnboardingFlowManager**

**Datei:** `src/AI/Onboarding/OnboardingFlowManager.php`

**Geplante Änderungen:**
```php
<?php

namespace App\AI\Onboarding;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\AgentRegistry;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use App\Entity\User;

class OnboardingFlowManager
{
    private AgentRegistry $agentRegistry;
    private MemoryProviderInterface $memoryProvider;
    private ContextStoreManager $contextStoreManager;
    private string $projectDir;

    public function __construct(
        AgentRegistry $agentRegistry,
        MemoryProviderInterface $memoryProvider,
        ContextStoreManager $contextStoreManager,
        string $projectDir
    ) {
        $this->agentRegistry = $agentRegistry;
        $this->memoryProvider = $memoryProvider;
        $this->contextStoreManager = $contextStoreManager;
        $this->projectDir = $projectDir;
    }

    /**
     * Startet den Onboarding-Prozess für einen Benutzer
     */
    public function startOnboarding(User $user): array
    {
        $agent = $this->agentRegistry->get('onboarding');
        
        // Lade den Onboarding-Prompt aus der Datei
        $promptContent = file_get_contents(
            $this->projectDir . '/config/prompts/onboarding_prompt.json'
        );
        
        // Erstelle System-Message mit dem Prompt
        $systemMessage = Message::ofSystem(
            "You are an onboarding assistant. Here is your configuration:\n\n" . $promptContent
        );
        
        // Starte die Konversation
        $messages = new MessageBag(
            $systemMessage,
            Message::ofUser("Start onboarding for user {$user->getId()}")
        );
        
        $response = $agent->call($messages);
        
        // Speichere den Kontext
        $this->contextStoreManager->store($user, $response->getContent());
        
        // Parse die Antwort
        return $this->parseOnboardingData($response->getContent());
    }

    /**
     * Führt den nächsten Schritt im Onboarding-Prozess aus
     */
    public function nextStep(User $user, string $userResponse): array
    {
        $agent = $this->agentRegistry->get('onboarding');
        
        // Lade den aktuellen Kontext
        $context = $this->contextStoreManager->load($user);
        
        // Erstelle Message mit Kontext
        $messages = new MessageBag(
            Message::ofSystem(
                file_get_contents($this->projectDir . '/config/prompts/onboarding_prompt.json')
            ),
            Message::ofUser($userResponse)
        );
        
        $response = $agent->call($messages);
        
        // Speichere den aktualisierten Kontext
        $this->contextStoreManager->store($user, $response->getContent());
        
        return $this->parseOnboardingData($response->getContent());
    }

    /**
     * Parsed die Onboarding-Daten aus der Agenten-Antwort
     */
    private function parseOnboardingData(string $content): array
    {
        // Extrahiere JSON aus der Antwort
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $content = $matches[0];
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Falls kein JSON, gebe die Rohdaten zurück
            return ['raw_response' => $content];
        }
        
        // Normalisiere die Daten
        return [
            'user_profile' => $data['user_profile'] ?? [],
            'current_step' => $data['current_step'] ?? null,
            'completed' => $data['completed'] ?? false,
            'next_question' => $data['next_question'] ?? null
        ];
    }

    /**
     * Beendet den Onboarding-Prozess
     */
    public function completeOnboarding(User $user): array
    {
        $context = $this->contextStoreManager->load($user);
        
        // Speichere die finalen Daten in der Datenbank
        $this->saveUserProfile($user, $context);
        
        // Gib eine Zusammenfassung zurück
        return [
            'status' => 'completed',
            'user_profile' => $context['user_profile'] ?? [],
            'recommendations' => $this->generateRecommendations($context)
        ];
    }

    /**
     * Generiert Empfehlungen basierend auf dem User-Profil
     */
    private function generateRecommendations(array $context): array
    {
        $profile = $context['user_profile'] ?? [];
        $role = $profile['primary_role'] ?? 'General';
        $useCase = $profile['primary_use_case'] ?? 'General';
        
        $recommendations = [
            'agents' => [],
            'tools' => [],
            'tutorials' => []
        ];
        
        // Empfehlungen basierend auf Rolle und Use-Case
        switch ($role) {
            case 'Developer':
                $recommendations['agents'][] = 'code_generator';
                $recommendations['tools'][] = 'code_formatter';
                $recommendations['tools'][] = 'api_request';
                break;
            case 'Data Scientist':
                $recommendations['agents'][] = 'data_analyst';
                $recommendations['tools'][] = 'csv_analyzer';
                $recommendations['tools'][] = 'statistical_analyzer';
                break;
            // ... weitere Rollen
        }
        
        switch ($useCase) {
            case 'Code Generation':
                $recommendations['agents'][] = 'code_generator';
                $recommendations['tutorials'][] = 'code_generation_guide';
                break;
            case 'Business Process Automation':
                $recommendations['agents'][] = 'process_automator';
                $recommendations['tutorials'][] = 'automation_guide';
                break;
            // ... weitere Use-Cases
        }
        
        return $recommendations;
    }

    /**
     * Speichert das User-Profil in der Datenbank
     */
    private function saveUserProfile(User $user, array $context): void
    {
        // Implementierung zum Speichern des Profils
        // (vereinfacht für das Beispiel)
        $profile = $context['user_profile'] ?? [];
        
        $user->setOnboardingCompleted(true);
        $user->setPreferences($profile['preferences'] ?? []);
        $user->setTechnicalSkills($profile['technical_skills'] ?? []);
        $user->setPrimaryRole($profile['primary_role'] ?? null);
        
        // Speichern in der Datenbank
        // $this->entityManager->flush();
    }
}
```

---

#### **📌 7. Translation Support aktivieren (optional)** *(⏳ GEPLANT)*

**Aufwand:** 0.5 Tage | **Status:** ⏳ **Geplant** | **Startdatum:** 15.08.2026

**Beschreibung:**
Aktivierung des Translation Supports für mehrsprachige Prompts.

**Umsetzung:**

1. **Paket installieren:**
   ```bash
   composer require symfony/translation
   ```

2. **Übersetzungsdateien erstellen:**
   ```bash
   mkdir -p translations
   touch translations/ai_prompts.de.yaml
   touch translations/ai_prompts.en.yaml
   ```

3. **Übersetzungen für `tool_schema_optimizer.txt`:**
   ```yaml
   # translations/ai_prompts.de.yaml
   agent:
     tool_schema_optimizer: |
       Du bist ein Experte für die Generierung von Tool-Schemata für das EVIE-Mehragentensystem.
       Deine Aufgabe ist es, präzise, gut strukturierte JSON-Schemata für KI-Tools basierend auf Benutzeranfragen zu erstellen.
       
       ## Richtlinien:
       1. Generiere immer gültige JSON-Schemata (Draft 2020-12)
       2. Jedes Schema MUSS als Symfony AI ToolInterface ausführbar sein
       3. Füge umfassende Sicherheitsüberlegungen hinzu
       4. Validere alle Eingaben und Ausgaben
       5. Biete klare, handlungsorientierte Fehlermeldungen
       
       ## Erorderliche Schema-Struktur:
       {
         "metadata": {
           "name": "tool_name_in_snake_case",
           "description": "Klare, prägnante Beschreibung des Tool-Zwecks",
           "category": "tool_category"
         },
         "schema": {
           "type": "object",
           "properties": {...},
           "required": [...]
         },
         "security": {
           "considerations": [...]
         }
       }
   ```

4. **Konfiguration anpassen:**
   ```yaml
   # config/packages/ai.yaml
   agent:
     tool_generator:
       prompt:
         text: 'agent.tool_schema_optimizer'  # Translation Key
         enable_translation: true
         translation_domain: 'ai_prompts'
   ```

---

#### **📌 8. Unit-Tests für Prompt-Generierung erstellen** *(⏳ GEPLANT)*

**Aufwand:** 0.5 Tage | **Status:** ⏳ **Geplant** | **Startdatum:** 15.08.2026

**Beschreibung:**
Erstellung von Unit-Tests für die Prompt-Generierung und -Verarbeitung.

**Geplante Tests:**

**Datei:** `tests/Unit/AI/Skills/ToolDefinitionGeneratorTest.php`
```php
<?php

namespace App\Tests\Unit\AI\Skills;

use PHPUnit\Framework\TestCase;
use App\AI\Skills\ToolDefinitionGenerator;
use Symfony\AI\Agent\AgentRegistry;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

class ToolDefinitionGeneratorTest extends TestCase
{
    private ToolDefinitionGenerator $generator;
    private AgentRegistry $agentRegistry;

    protected function setUp(): void
    {
        $this->agentRegistry = $this->createMock(AgentRegistry::class);
        $this->generator = new ToolDefinitionGenerator(
            $this->agentRegistry,
            __DIR__ . '/../../../..'  // Project dir
        );
    }

    public function testGenerateToolSchemaForWebsiteScraper(): void
    {
        // Mock Agent
        $agent = $this->createMock(AgentInterface::class);
        $response = $this->createMock(\Symfony\AI\Platform\Message\ResponseMessage::class);
        $response->method('getContent')->willReturn(file_get_contents(
            __DIR__ . '/../../../config/prompts/test_responses/website_scraper.json'
        ));
        $agent->method('call')->willReturn($response);
        
        $this->agentRegistry->method('get')->willReturn($agent);
        
        // Test
        $schema = $this->generator->generateToolSchema('Create a tool to scrape websites');
        
        // Assertions
        $this->assertArrayHasKey('metadata', $schema);
        $this->assertArrayHasKey('name', $schema['metadata']);
        $this->assertEquals('website_scraper', $schema['metadata']['name']);
        
        $this->assertArrayHasKey('schema', $schema);
        $this->assertArrayHasKey('properties', $schema['schema']);
        $this->assertArrayHasKey('url', $schema['schema']['properties']);
        
        $this->assertArrayHasKey('security', $schema);
        $this->assertArrayHasKey('considerations', $schema['security']);
    }

    public function testGenerateToolSchemaWithInvalidJson(): void
    {
        // Mock Agent mit ungültigem JSON
        $agent = $this->createMock(AgentInterface::class);
        $response = $this->createMock(\Symfony\AI\Platform\Message\ResponseMessage::class);
        $response->method('getContent')->willReturn('Invalid JSON {');
        $agent->method('call')->willReturn($response);
        
        $this->agentRegistry->method('get')->willReturn($agent);
        
        // Test & Erwartung einer Exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON schema generated');
        
        $this->generator->generateToolSchema('Test request');
    }

    public function testValidateSchemaStructureWithMissingFields(): void
    {
        $schema = [
            'metadata' => []  // Fehlende Felder
        ];
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required field: metadata.name');
        
        // Aufruf der privaten Methode über Reflection
        $method = new \ReflectionMethod(ToolDefinitionGenerator::class, 'validateSchemaStructure');
        $method->setAccessible(true);
        $method->invoke($this->generator, $schema);
    }
}
```

---

#### **📌 9. Integrationstests durchführen** *(⏳ GEPLANT)*

**Aufwand:** 0.5 Tage | **Status:** ⏳ **Geplant** | **Startdatum:** 16.08.2026

**Beschreibung:**
Durchführung von Integrationstests für die Prompt-Optimierung.

**Geplante Tests:**

**Datei:** `tests/Integration/AI/PromptOptimizationTest.php`
```php
<?php

namespace App\Tests\Integration\AI;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

class PromptOptimizationTest extends WebTestCase
{
    public function testToolGeneratorAgentWithFileBasedPrompt(): void
    {
        $container = self::getContainer();
        $agent = $container->get('ai.agent.tool_generator');
        
        $response = $agent->call(
            new MessageBag(Message::ofUser('Create a tool to read files'))
        );
        
        $content = $response->getContent();
        
        // Prüfe, dass der Prompt geladen wurde
        $this->assertStringContainsString('EVIE multi-agent system', $content);
        
        // Prüfe, dass ein gültiges JSON-Schema generiert wurde
        $this->assertJson($content);
        
        $schema = json_decode($content, true);
        $this->assertArrayHasKey('metadata', $schema);
        $this->assertArrayHasKey('schema', $schema);
    }

    public function testOnboardingAgentWithFileBasedPrompt(): void
    {
        $container = self::getContainer();
        $agent = $container->get('ai.agent.onboarding');
        
        $response = $agent->call(
            new MessageBag(Message::ofUser('Start onboarding'))
        );
        
        $content = $response->getContent();
        
        // Prüfe, dass der Onboarding-Prompt geladen wurde
        $this->assertStringContainsString('EVIE', $content);
        $this->assertStringContainsString('onboarding', $content);
    }

    public function testPromptFilesExistAndAreReadable(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        
        $promptFiles = [
            'config/prompts/tool_schema_optimizer.txt',
            'config/prompts/agent_configurator.md',
            'config/prompts/onboarding_prompt.json'
        ];
        
        foreach ($promptFiles as $file) {
            $fullPath = $projectDir . '/' . $file;
            $this->assertFileExists($fullPath, "Prompt file $file does not exist");
            $this->assertFileIsReadable($fullPath, "Prompt file $file is not readable");
        }
    }
}
```

---

### **📊 Fortschrittsübersicht für Maßnahme 8**

| **Schritt** | **Beschreibung** | **Aufwand** | **Status** | **Fortschritt** | **Startdatum** | **Enddatum** |
|-------------|------------------|-------------|------------|-----------------|----------------|--------------|
| 1. Verzeichnisstruktur erstellen | `config/prompts/` anlegen | 0.5 Tage | ✅ **Abgeschlossen** | 100% | 12.08.2026 | 12.08.2026 |
| 2. Prompt-Dateien erstellen | Tool-Schema-, Agenten-, Onboarding-Prompts | 1 Tag | 🟡 **In Bearbeitung** | 50% | 12.08.2026 | 13.08.2026 |
| 3. `ai.yaml` anpassen | Konfiguration für File-Based Prompts | 0.5 Tage | ⏳ **Geplant** | 0% | 13.08.2026 | 13.08.2026 |
| 4. Integration in Services | Anpassung von `ToolDefinitionGenerator` & `OnboardingFlowManager` | 0.5 Tage | ⏳ **Geplant** | 0% | 14.08.2026 | 14.08.2026 |
| 5. Translation Support | Mehrsprachige Prompts | 0.5 Tage | ⏳ **Geplant** | 0% | 15.08.2026 | 15.08.2026 |
| 6. Unit-Tests erstellen | Tests für Prompt-Generierung | 0.5 Tage | ⏳ **Geplant** | 0% | 15.08.2026 | 15.08.2026 |
| 7. Integrationstests | Tests für File-Based Prompts | 0.5 Tage | ⏳ **Geplant** | 0% | 16.08.2026 | 16.08.2026 |

**Gesamtfortschritt:** **20%** (1 von 7 Schritten abgeschlossen, Schritt 2 zu 50% fertig)

---

### **📝 Checkliste für Maßnahme 8**

#### **✅ Abgeschlossen**
- [x] Verzeichnis `config/prompts/` erstellen
- [x] `.gitkeep` in `config/prompts/` hinzufügen
- [x] `tool_schema_optimizer.txt` erstellen
- [x] `tool_schema_optimizer.txt` testen
- [x] `agent_configurator.md` erstellen
- [x] `agent_configurator.md` testen
- [x] `onboarding_prompt.json` Grundgerüst erstellen

#### **🟡 In Bearbeitung**
- [ ] `onboarding_prompt.json` finalisieren (Validierung, Beispiele, Sicherheitshinweise)

#### **⏳ Geplant**
- [ ] `ai.yaml` für File-Based Prompts anpassen
- [ ] `ToolDefinitionGenerator` für neuen Prompt anpassen
- [ ] `OnboardingFlowManager` für neuen Prompt anpassen
- [ ] Translation Support aktivieren (optional)
- [ ] Unit-Tests für `ToolDefinitionGenerator` erstellen
- [ ] Unit-Tests für `OnboardingFlowManager` erstellen
- [ ] Integrationstests für File-Based Prompts erstellen
- [ ] Manuelle Tests durchführen

---

### **🔍 Validierung & Testing**

#### **Manuelle Tests**

**1. Tool-Schema-Prompt testen:**
```bash
# Agent direkt aufrufen
php bin/console ai:agent:call tool_generator

# Beispielanfrage
> Create a tool to analyze PDF documents

# Erwartetes Ergebnis:
# - Gültiges JSON-Schema
# - Enthält alle erforderlichen Felder (metadata, schema, security)
# - Enthält Sicherheitshinweise
```

**2. Agenten-Konfigurierer testen:**
```bash
php bin/console ai:agent:call agent_configurator

# Beispielanfrage
> Create a configuration for a data analysis agent

# Erwartetes Ergebnis:
# - Gültige YAML-Konfiguration
# - Enthält Plattform, Modell, Prompt, Tools
```

**3. Onboarding-Prompt testen:**
```bash
php bin/console ai:agent:call onboarding

# Beispielinteraktion:
# Agent: Hello! I'm EVIE... What is your full name?
# User: John Doe
# Agent: Thank you, John! What is your email address?

# Erwartetes Ergebnis:
# - Strukturierte Fragen
# - Validierung der Antworten
# - Fortschrittsverfolgung
```

#### **Automatisierte Tests**

**1. PHPUnit-Tests ausführen:**
```bash
# Alle Tests
php vendor/bin/phpunit

# Spezifische Tests für Maßnahme 8
php vendor/bin/phpunit tests/Unit/AI/Skills/ToolDefinitionGeneratorTest.php
php vendor/bin/phpunit tests/Integration/AI/PromptOptimizationTest.php
```

**2. Konfiguration validieren:**
```bash
# ai.yaml Syntax prüfen
php bin/console config:dump-reference ai

# Container bauen (prüft auf Syntaxfehler)
php bin/console cache:clear
```

---

### **📌 Erwartete Verbesserungen nach Abschluss**

| **Bereich** | **Aktueller Zustand** | **Zielzustand** | **Messbare Verbesserung** | **Impact** |
|-------------|----------------------|-----------------|----------------------------|------------|
| **Tool-Schema-Qualität** | ~70% valide Schemata | **95% valide Schemata** | Automatisierte Validierung | 🟢 **Hoch** |
| **Prompt-Wiederverwendbarkeit** | 0% (hardcoded) | **100% (File-Based)** | Code-Review | 🟢 **Hoch** |
| **Prompt-Wartbarkeit** | Schlechte Wartbarkeit | **Zentrale Verwaltung** | Entwickler-Feedback | 🟢 **Hoch** |
| **Mehrsprachigkeit** | Nicht verfügbar | **Optional verfügbar** | Benutzerumfragen | 🟡 **Mittel** |
| **Onboarding-Datenqualität** | ~60% vollständig | **90% vollständig** | Manuelle Überprüfung | 🟡 **Mittel** |
| **Sicherheit der Tools** | Grundlegende Hinweise | **Umfassende Sicherheitshinweise** | Security Audit | 🟢 **Hoch** |
| **Entwickler-Produktivität** | Manuelle Prompt-Anpassung | **File-Based Management** | Zeitmessung | 🟢 **Hoch** |

---

### **🔗 Verknüpfungen zu anderen Komponenten**

| **Komponente** | **Verknüpfung zu Maßnahme 8** | **Impact** |
|---------------|--------------------------------|------------|
| **DynamicSkillRegistry** | Nutzt die optimierten Tool-Schemata für die Registrierung | 🟢 **Hoch** |
| **OnboardingFlowManager** | Nutzt den Onboarding-Prompt für strukturierte Datenerfassung | 🟢 **Hoch** |
| **SubAgentFactory** | Kann die Agenten-Konfigurierer-Prompts für dynamische Agenten-Erstellung nutzen | 🟡 **Mittel** |
| **SecurityGuard** | Profitiert von besseren Tool-Definitionen mit Sicherheitshinweisen | 🟢 **Hoch** |
| **ToolDefinitionGenerator** | Hauptnutzer des Tool-Schema-Prompts | 🟢 **Hoch** |
| **Evolution-Flow** | Profitiert von besseren Tool-Schemata für die dynamische Generierung | 🟢 **Hoch** |

---

### **📚 Dokumentation & Ressourcen**

**Interne Dokumentation:**
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md) – Hintergrund und Problemstellung
- [Symfony AI Bundle Dokumentation](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) – Offizielle Dokumentation
- [JSON Schema Specification](https://json-schema.org/draft/2020-12/json-schema-validation.html) – Schema-Validierung

**Externe Ressourcen:**
- [Prompt Engineering Guide](https://www.promptingguide.ai/) – Best Practices für Prompt-Design
- [JSON Schema Examples](https://json-schema.org/learn/examples) – Schema-Beispiele
- [Security Considerations for AI](https://owasp.org/www-community/Artificial_Intelligence_Security) – Sicherheitsrichtlinien

---

---

### **🟢 Maßnahme 9: E2E-Test für Evolution-Flow**
**Priorität:** Mittel | **Aufwand:** 2–3 Tage | **Status:** ⏳ Geplant

#### **Ziel**
Erstellung von **End-to-End-Tests** für den **Tool-Evolution-Flow** (Tool-Generierung → Registrierung → Ausführung) zur Validierung der **dynamischen Tool-Erstellung** und **CompilerPass-Integration**.

#### **Hintergrund (aus EVIE_ANALYSE.md)**
- **Kritische Lücke:** DynamicSkillRegistry lädt JSON-Schemata, aber **keine Umwandlung in ToolInterface**
- **Fehlend:** E2E-Test für Evolution-Flow
- **Lösung:** CompilerPass implementieren + Tests erstellen

#### **Symfony AI Bundle-Unterstützung**
Das Symfony AI Bundle bietet folgende Testmöglichkeiten:

1. **Testing Agents** im Profiler
   - Sammelt Daten über Agenten-Aufrufe
   - Zeigt Tool-Calls und Responses an

2. **Console Commands für Tests**
   - `ai:agent:call <agent>` – Interaktive Tests
   - `ai:platform:invoke <platform> <model> "<message>"` – Direkte Plattform-Aufrufe

3. **Dependency Injection für Tests**
   - Einfaches Mocken von Plattformen und Agenten

#### **Umsetzungsschritte**

| **Schritt** | **Beschreibung** | **Technische Details** | **Erwartetes Ergebnis** | **Aufwand** | **Status** |
|-------------|------------------|------------------------|-------------------------|-------------|------------|
| 1. **Test-Umgebung vorbereiten** | Mock-Plattformen und Agenten für Tests konfigurieren | `config/test/ai.yaml` mit Test-Plattformen | Isolierte Testumgebung | 0.5 Tage | ⏳ |
| 2. **Unit-Tests für DynamicSkillRegistry** | Teste das Laden und Umwandeln von Tool-Definitionen | PHPUnit-Tests für `DynamicSkillRegistry::loadTools()` | Validierte Tool-Lademechanismen | 1 Tag | ⏳ |
| 3. **Integrationstests für CompilerPass** | Teste die Registrierung dynamischer Tools im Container | Test der `CompilerPass`-Integration | Funktionierende Tool-Registrierung | 1 Tag | ⏳ |
| 4. **E2E-Test für Evolution-Flow** | Teste den gesamten Flow: Prompt → Tool-Generierung → Registrierung → Ausführung | PHPUnit + Symfony Panther für Browser-Tests | Validierter Evolution-Flow | 1 Tag | ⏳ |
| 5. **Profiler-Tests** | Nutze den AI Profiler für Debugging | Integration des Profilers in Test-Suite | Bessere Debug-Möglichkeiten | 0.5 Tage | ⏳ |

---

### **🟢 Maßnahme 10: Onboarding-Prompt optimieren**
**Priorität:** Mittel | **Aufwand:** 1–2 Tage | **Status:** ⏳ Geplant

#### **Ziel**
Optimierung des **Onboarding-Prompts** für eine **präzisere User-Kategorisierung** und **bessere Datenqualität** durch Nutzung von **strukturierten Prompts** und **File-Based Prompts**.

#### **Hintergrund (aus EVIE_ANALYSE.md)**
- Aktuell: **Onboarding ohne spezifischen Prompt** → Weniger präzise User-Kategorisierung
- Problem: User-Daten werden unstrukturiert oder unvollständig gesammelt
- Lösung: **Strukturierter Onboarding-Prompt** mit klaren Anweisungen

#### **Symfony AI Bundle-Unterstützung**
Das Symfony AI Bundle bietet:
- **File-Based Prompts** für komplexe Onboarding-Flows
- **Message Template Support** für dynamische Prompts
- **Memory Provider** für Kontext-Speicherung

---

## 📅 **Aktualisierter Zeitplan & Meilensteine**

| **Zeitraum** | **Maßnahme** | **Schritt** | **Verantwortlich** | **Status** | **Meilenstein** |
|--------------|--------------|-------------|--------------------|------------|-----------------|
| **12.08.2026** | **Maßnahme 8** | Verzeichnisstruktur erstellen | Jens Smit | ✅ **Abgeschlossen** | Prompt-Verzeichnis bereit |
| **12.08.2026** | **Maßnahme 8** | Prompt-Dateien erstellen (Schritt 1) | Jens Smit | ✅ **Abgeschlossen** | Tool-Schema-Prompt fertig |
| **12.08.2026** | **Maßnahme 8** | Prompt-Dateien erstellen (Schritt 2) | Jens Smit | 🟡 **In Bearbeitung** | Agenten-Prompt fertig |
| **13.08.2026** | **Maßnahme 8** | Prompt-Dateien erstellen (Schritt 3) | Jens Smit | ⏳ **Geplant** | Onboarding-Prompt finalisiert |
| **13.08.2026** | **Maßnahme 8** | `ai.yaml` anpassen | Jens Smit | ⏳ **Geplant** | File-Based Prompts konfiguriert |
| **14.08.2026** | **Maßnahme 8** | Integration in Services | Jens Smit | ⏳ **Geplant** | Services angepasst |
| **15.08.2026** | **Maßnahme 8** | Translation Support & Unit-Tests | Jens Smit | ⏳ **Geplant** | Tests implementiert |
| **16.08.2026** | **Maßnahme 8** | Integrationstests | Jens Smit | ⏳ **Geplant** | Maßnahme 8 abgeschlossen |
| **16.–18.08.2026** | **Maßnahme 9** | E2E-Tests für Evolution-Flow | Jens Smit | ⏳ **Geplant** | Tests implementiert |
| **19.–20.08.2026** | **Maßnahme 10** | Onboarding-Prompt optimieren | Jens Smit | ⏳ **Geplant** | Onboarding verbessert |
| **21.–25.08.2026** | **Puffer & Finalisierung** | Alle Maßnahmen | Jens Smit | ⏳ **Geplant** | Phase 3 abgeschlossen |

---

## 📊 **Erfolgsmetriken (Aktualisiert)**

| **Metrik** | **Zielwert** | **Aktueller Wert** | **Messmethode** | **Status** | **Trend** |
|------------|--------------|--------------------|-----------------|------------|----------|
| **Tool-Schema-Qualität** | 95% valide Schemata | ~70% | Automatisierte Validierung | 🟡 **In Bearbeitung** | ⬆️ |
| **Onboarding-Datenqualität** | 100% vollständige Profile | ~60% | Manuelle Überprüfung | ⏳ **Geplant** | → |
| **Testabdeckung (Evolution-Flow)** | 90% | 0% | PHPUnit Coverage Report | ⏳ **Geplant** | → |
| **Prompt-Wiederverwendbarkeit** | 100% File-Based | **50%** | Code-Review | 🟡 **In Bearbeitung** | ⬆️ |
| **Prompt-Wartbarkeit** | Zentrale Verwaltung | 0% | Entwickler-Feedback | 🟡 **In Bearbeitung** | ⬆️ |

---

## 🛠 **Technische Abhängigkeiten (Aktualisiert)**

### **Benötigte Pakete**
| **Paket** | **Version** | **Zweck** | **Status** | **Priorität** |
|-----------|-------------|-----------|------------|--------------|
| `symfony/ai-bundle` | ^0.12.0 | AI Bundle-Features | ✅ Installiert | 🟢 **Hoch** |
| `symfony/translation` | ^6.4 | Translation Support für Prompts | ⏳ Optional | 🟡 **Mittel** |
| `symfony/expression-language` | ^6.4 | Message Template Support | ⏳ Optional | 🟢 **Niedrig** |
| `phpunit/phpunit` | ^10.0 | Unit- & Integrationstests | ✅ Installiert | 🟢 **Hoch** |
| `symfony/panther` | ^2.0 | E2E-Tests | ⏳ Optional | 🟢 **Niedrig** |

### **Benötigte Konfigurationen**
1. ✅ **`config/prompts/`** – Verzeichnis für Prompt-Dateien *(erstellt)*
   - ✅ `tool_schema_optimizer.txt` *(erstellt & getestet)*
   - ✅ `agent_configurator.md` *(erstellt & getestet)*
   - 🟡 `onboarding_prompt.json` *(in Arbeit, 30% fertig)*
2. ⏳ **`config/packages/ai.yaml`** – Anpassung für File-Based Prompts *(geplant für 13.08.2026)*
3. ⏳ **`config/test/ai.yaml`** – Test-Konfiguration mit Mock-Plattformen *(geplant)*
4. ⏳ **`phpunit.xml.dist`** – Anpassung für AI-spezifische Tests *(geplant)*

---

## 📝 **Gesamt-Checkliste für Phase 3**

### **🟡 Maßnahme 8: LLM-Prompt-Optimierung** *(In Bearbeitung - 20% fertig)*

#### **✅ Abgeschlossen (12.08.2026)**
- [x] Verzeichnis `config/prompts/` erstellen
- [x] `.gitkeep` in `config/prompts/` hinzufügen
- [x] `tool_schema_optimizer.txt` erstellen
- [x] `tool_schema_optimizer.txt` mit Beispielen und Validierungsregeln
- [x] `tool_schema_optimizer.txt` testen (manuell)
- [x] `agent_configurator.md` erstellen
- [x] `agent_configurator.md` mit Plattform- und Modellauswahl
- [x] `agent_configurator.md` testen (manuell)

#### **🟡 In Bearbeitung (12.08.2026)**
- [ ] `onboarding_prompt.json` finalisieren
  - [ ] Validierungslogik vervollständigen
  - [ ] Beispielkonversationen hinzufügen
  - [ ] Sicherheitshinweise ergänzen
  - [ ] Flow-Steuerung implementieren

#### **⏳ Geplant**
- [ ] `ai.yaml` für File-Based Prompts anpassen
- [ ] `ToolDefinitionGenerator` für neuen Prompt anpassen
- [ ] `OnboardingFlowManager` für neuen Prompt anpassen
- [ ] Translation Support aktivieren (optional)
- [ ] Unit-Tests für `ToolDefinitionGenerator` erstellen
- [ ] Unit-Tests für `OnboardingFlowManager` erstellen
- [ ] Integrationstests für File-Based Prompts erstellen
- [ ] Manuelle Tests aller Prompts durchführen
- [ ] Dokumentation aktualisieren

### **⏳ Maßnahme 9: E2E-Test für Evolution-Flow** *(Geplant)*
- [ ] Test-Umgebung in `config/test/ai.yaml` konfigurieren
- [ ] Unit-Tests für `DynamicSkillRegistry` erstellen
- [ ] Integrationstests für CompilerPass erstellen
- [ ] E2E-Test für Evolution-Flow erstellen
- [ ] Profiler-Tests integrieren

### **⏳ Maßnahme 10: Onboarding-Prompt optimieren** *(Geplant)*
- [ ] Onboarding-Prompt in `config/prompts/onboarding_prompt.json` finalisieren
- [ ] `ai.yaml` für Onboarding-Agent anpassen
- [ ] `OnboardingFlowManager` für neuen Prompt anpassen
- [ ] Memory Provider für Onboarding-Daten integrieren
- [ ] Onboarding-Flow testen

---

## 🔗 **Verknüpfungen zu anderen Phasen**

| **Phase** | **Verknüpfung zu Phase 3** | **Abhängigkeit** | **Status** |
|-----------|-----------------------------|------------------|------------|
| **Phase 1** | SecurityGuard & HitlInterceptor | Keine direkte Abhängigkeit | ✅ Abgeschlossen |
| **Phase 2** | Sub-Agenten & Tool-Generierung | **Abhängig** – Phase 2 muss abgeschlossen sein | ✅ Abgeschlossen |
| **Phase 4** | Orchestrator als Klasse | **Voraussetzung** – Phase 3 sollte abgeschlossen sein | ⏳ Geplant |

---

## 📌 **Zusammenfassung & Nächste Schritte**

### **🎉 Was wurde heute erreicht?** *(12. August 2026)*

✅ **Maßnahme 8 (LLM-Prompt-Optimierung) offiziell gestartet**
✅ **Verzeichnisstruktur** für Prompts erstellt (`config/prompts/`)
✅ **3 Prompt-Dateien** erstellt und teilweise getestet:
   - ✅ `tool_schema_optimizer.txt` **(100% fertig)** – Generiert präzise Tool-Schemata
   - ✅ `agent_configurator.md` **(100% fertig)** – Hilft bei der Agenten-Konfiguration
   - 🟡 `onboarding_prompt.json` **(30% fertig)** – Strukturiertes Onboarding
✅ **Dokumentation in `ROADMAP_PHASE3.md` ausführlich erweitert**
   - Detaillierte Beschreibung aller Schritte
   - Code-Beispiele für alle Prompts
   - Validierungs- und Testanleitungen

---

### **🟡 Aktueller Fokus** *(12. August 2026, 20:00 Uhr)*

🔹 **Finalisierung von `onboarding_prompt.json`** (70% verbleibend)
   - Validierungslogik vervollständigen
   - Beispielkonversationen hinzufügen
   - Sicherheitshinweise ergänzen

🔹 **Vorbereitung für morgen (13.08.2026):**
   - `ai.yaml` für File-Based Prompts anpassen
   - Integration in `ToolDefinitionGenerator` starten

---

### **📅 Nächste Schritte** *(13. August 2026)*

#### **Priorität 1: Maßnahme 8 abschließen**
1. **`onboarding_prompt.json` finalisieren** (0.5 Tage)
   - [ ] Validierungsregeln für alle Felder hinzufügen
   - [ ] Beispielkonversationen ergänzen
   - [ ] Sicherheitshinweise für Onboarding-Daten

2. **`ai.yaml` anpassen** (0.5 Tage)
   - [ ] Konfiguration für `tool_generator`-Agent
   - [ ] Konfiguration für `onboarding`-Agent
   - [ ] Konfiguration für `agent_configurator`-Agent

3. **Integration in Services starten** (0.5 Tage)
   - [ ] `ToolDefinitionGenerator` anpassen
   - [ ] Grundlegende Tests durchführen

#### **Priorität 2: Vorbereitung für Maßnahme 9**
- [ ] Test-Umgebung (`config/test/ai.yaml`) vorbereiten
- [ ] Struktur für E2E-Tests planen

---

### **💡 Offene Fragen für dich**

1. **Translation Support:**
   - Soll der **Translation Support** für Prompts aktiviert werden?
   - **Vorteile:** Mehrsprachige Unterstützung für internationale Nutzer
   - **Aufwand:** 0.5 Tage + Übersetzungsarbeit
   - **Empfehlung:** ✅ **Ja**, da EVIE in verschiedenen Regionen (Lübeck, Hamburg, Rügen) genutzt wird

2. **Symfony Panther:**
   - Soll **Symfony Panther** für E2E-Tests verwendet werden?
   - **Vorteile:** Realistische Browser-Tests für komplexe Interaktionen
   - **Aufwand:** Zusätzliche Abhängigkeit + Konfiguration
   - **Empfehlung:** ⚠️ **Optional** – Erst für Maßnahme 9 entscheiden

3. **Message Template Support:**
   - Soll der **Message Template Support** aktiviert werden?
   - **Vorteile:** Dynamische Prompts mit Variablen
   - **Aufwand:** 0.5 Tage + `composer require symfony/expression-language`
   - **Empfehlung:** ❌ **Nein** – Nicht dringend notwendig, kann später hinzugefügt werden

4. **Prompt-Versionierung:**
   - Soll ein **Versionssystem** für Prompts eingeführt werden?
   - **Vorteile:** Bessere Nachverfolgbarkeit von Änderungen
   - **Aufwand:** Minimal (z. B. `v1.0` im Dateinamen oder Header)
   - **Empfehlung:** ✅ **Ja** – Einfache Implementierung mit großem Nutzen

---

### **🚀 Sofort umsetzbare Aufgaben** *(für heute Abend, 12.08.2026)*

1. **`onboarding_prompt.json` finalisieren** (30–60 Minuten)
   - [ ] Validierungsregeln für alle Felder ergänzen
   - [ ] Beispielkonversationen hinzufügen
   - [ ] Sicherheitshinweise für Onboarding-Daten ergänzen

2. **Erste Tests durchführen** (30 Minuten)
   - [ ] `tool_schema_optimizer.txt` manuell testen
   - [ ] `agent_configurator.md` manuell testen
   - [ ] Ergebnisse dokumentieren

3. **Dokumentation prüfen** (15 Minuten)
   - [ ] `ROADMAP_PHASE3.md` auf Vollständigkeit prüfen
   - [ ] Code-Beispiele auf Korrektheit prüfen

---

### **📊 Erfolgsprognose**

Mit der **LLM-Prompt-Optimierung (Maßnahme 8)** werden folgende **messbare Verbesserungen** erwartet:

| **Bereich** | **Aktuell** | **Nach Maßnahme 8** | **Impact** | **Zeitraum** |
|-------------|-------------|---------------------|------------|--------------|
| **Tool-Schema-Qualität** | ~70% valide | **95% valide** | 🟢 **Hoch** | 1 Woche |
| **Prompt-Wartbarkeit** | Schlechte Wartbarkeit | **Zentrale Verwaltung** | 🟢 **Hoch** | Sofort |
| **Onboarding-Datenqualität** | ~60% vollständig | **80% vollständig** | 🟡 **Mittel** | 1 Woche |
| **Entwickler-Produktivität** | Manuelle Anpassung | **File-Based Management** | 🟢 **Hoch** | Sofort |
| **Sicherheit der Tools** | Grundlegende Hinweise | **Umfassende Sicherheitshinweise** | 🟢 **Hoch** | 1 Woche |

---

## 📚 **Referenzen & Ressourcen**

### **Interne Dokumentation**
- [EVIE_ANALYSE.md](EVIE_ANALYSE.md) – Detaillierte Analyse des aktuellen Standes
- [ROADMAP_PHASE1.md](ROADMAP_PHASE1.md) – Abgeschlossene Maßnahmen der Phase 1
- [ROADMAP_PHASE2.md](ROADMAP_PHASE2.md) – Abgeschlossene Maßnahmen der Phase 2
- [Symfony AI Bundle Dokumentation](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) – Offizielle Dokumentation

### **Externe Ressourcen**
- [JSON Schema Specification](https://json-schema.org/draft/2020-12/json-schema-validation.html) – Offizielle JSON-Schema-Doku
- [Prompt Engineering Guide](https://www.promptingguide.ai/) – Best Practices für Prompt-Design
- [JSON Schema Examples](https://json-schema.org/learn/examples) – Schema-Beispiele
- [OWASP AI Security](https://owasp.org/www-community/Artificial_Intelligence_Security) – Sicherheitsrichtlinien für KI

### **Tools & Befehle**
```bash
# Symfony AI Bundle Befehle
php bin/console ai:agent:call <agent_name>  # Interaktiver Agent-Test
php bin/console ai:platform:invoke <platform> <model> "<message>"  # Direkter Plattform-Test
php bin/console config:dump-reference ai  # AI-Konfiguration anzeigen

# Tests
php vendor/bin/phpunit  # Alle Tests ausführen
php vendor/bin/phpunit tests/Unit/AI/  # AI-spezifische Unit-Tests
php vendor/bin/phpunit tests/Integration/AI/  # AI-spezifische Integrationstests

# Git
git status  # Änderungen prüfen
git add config/prompts/  # Prompt-Dateien hinzufügen
git commit -m "Add File-Based Prompts for LLM optimization"
```

---

---

**💡 Fazit:**

Die **LLM-Prompt-Optimierung (Maßnahme 8)** ist **erfolgreich gestartet** und befindet sich in der **aktiven Umsetzungsphase**. 

**Was wurde erreicht:**
- ✅ **Verzeichnisstruktur** für Prompts erstellt
- ✅ **2 von 3 Prompt-Dateien** vollständig erstellt und getestet
- ✅ **Detaillierte Dokumentation** in der Roadmap
- ✅ **Klare nächste Schritte** definiert

**Aktueller Stand:**
- **Fortschritt:** 20% (1 von 5 Hauptschritten abgeschlossen, Schritt 2 zu 50% fertig)
- **Qualität:** Hoch (ausführliche Prompts mit Beispielen und Sicherheitshinweisen)
- **Dokumentation:** Ausführlich (Code-Beispiele, Validierungsanleitungen, Testfälle)

**Nächste Schritte:**
1. **`onboarding_prompt.json` finalisieren** (Priorität 1)
2. **`ai.yaml` anpassen** (Priorität 2)
3. **Integration in Services starten** (Priorität 3)

**Empfehlung:**
- **Translation Support aktivieren** (für internationale Nutzung)
- **Prompt-Versionierung einführen** (für bessere Nachverfolgbarkeit)
- **Regelmäßige Tests durchführen** (für Qualitätssicherung)

---

*Letzte Aktualisierung: 12. August 2026, 20:00 Uhr*
*Nächste Aktualisierung geplant: 13. August 2026, 09:00 Uhr*