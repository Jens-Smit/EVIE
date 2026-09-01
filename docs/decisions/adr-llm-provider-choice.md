# ADR: LLM Provider Choice Architecture

## Status
Accepted

## Context

EVIE is designed as an enterprise-level AI agent platform that must support multiple LLM providers (Mistral, Gemini, etc.) to accommodate different tenant requirements:

- **Data Privacy**: EU-based tenants may prefer Mistral for data sovereignty
- **Performance**: Different providers excel at different tasks
- **Cost**: Pricing varies significantly between providers
- **Model Capabilities**: Different models have different strengths

Previously, the platform was hardcoded to use Mistral (`ai.platform.mistral`) with a fixed model (`mistral-small-latest`) for all agents and all tenants. This prevented tenants from:
- Selecting their preferred provider
- Choosing different models for different use cases
- Complying with organizational data governance policies

## Decision

Implement a **per-tenant LLM provider and model selection system** with the following architecture:

### 1. UserProfile Extension
- Add `llm_provider` and `llm_model` fields to `UserProfile.preferences` JSON field
- Store tenant preferences persistently

### 2. PlatformResolver Service
- Service that resolves the appropriate `PlatformInterface` based on tenant preferences
- Supports Mistral and Gemini as initial providers
- Falls back to Mistral if no preference is set
- Uses Symfony's dependency injection with explicit platform service references

### 3. ModelResolver Service
- Resolves the appropriate model string for a given tenant and agent role
- Supports role-specific model defaults (orchestrator, tool_generator, etc.)
- Provides methods to update tenant preferences

### 4. Agent Configuration
- Agents continue to use YAML configuration but with dynamic platform resolution
- Platform and model are resolved at runtime based on the authenticated user
- Maintains backward compatibility with existing agent definitions

### 5. UI Integration
- Add LLM preferences section to `/settings` page
- Dropdown selectors for provider and model
- Immediate effect on next agent request

## Architecture Diagram

```
User Request
    ↓
Authentication (UserIdentifier)
    ↓
UserProfileRepository.findByUserIdentifier()
    ↓
PlatformResolver.resolvePlatform(userIdentifier)
    ↓
Returns: MistralPlatform or GeminiPlatform
    ↓
Agent uses resolved platform for LLM calls
```

## Consequences

### Positive
- ✅ Tenants can choose their preferred LLM provider
- ✅ Supports data sovereignty requirements
- ✅ Enables cost optimization per tenant
- ✅ Maintains backward compatibility
- ✅ Extensible for new providers

### Negative
- ⚠️ Slightly increased complexity in agent initialization
- ⚠️ Need to handle provider API key availability
- ⚠️ Model compatibility differences between providers

## Provider Support

### Current Implementation
- **Mistral AI**: Full support
- **Google Gemini**: Full support

### Future Extensions
- Anthropic Claude
- OpenAI
- Local/On-premise models (Ollama, vLLM)

## Security Considerations

1. **API Key Management**: API keys are configured at the application level, not per-tenant
2. **Tenant Isolation**: Each tenant's requests are processed with their chosen provider
3. **Fallback Strategy**: If a provider fails, clear error messages are shown
4. **Audit Logging**: Provider/model changes are logged in the audit trail

## Testing

- Unit tests for PlatformResolver and ModelResolver
- Integration tests for tenant isolation
- E2E tests for UI preference changes

## Related Issues
- Closes #11 (P0: LLM-/Anbieter-Choice pro Tenant)
- Related to #10 (Secrets-Store for API key management)
