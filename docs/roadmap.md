# Roadmap

## ✅ Erledigt (Runde 1–5)

- [x] Native Symfony AI v0.12 Architektur (Agent, Toolbox, Platform)
- [x] DynamicToolbox (ToolboxInterface-Decorator)
- [x] HitlListener (ToolCallRequested-Event)
- [x] SecurityGuard mit PolicyDecision (Allow/Deny/AskUser)
- [x] SubAgentDispatcher entfernt → native SubAgentFactory
- [x] ContextInjector als nativer InputProcessor (RAG)
- [x] StoreRetrieverAdapter (native RetrieverInterface)
- [x] Tenant-Isolation (UserContext)
- [x] Security-Hardening (SSRF/Filesystem/Command/Prompt-Injection)
- [x] Evolution-Flow-Tests (Revoke, invalid Executor, SSRF, HITL)
- [x] ObservabilityListener (Request-ID/Trace-ID)
- [x] Production Dockerfile (OPcache, Healthcheck)
- [x] CI als Release-Gate (composer validate/audit, PHPStan, alle Suiten)
- [x] MCP: Retry, Timeout, Audit-Logging, Server-Whitelist
- [x] Vollständige Dokumentation (Architektur, Security, Development, ADRs)

## ⚠️ Teilweise

- [ ] Production Docker: GHCR-Publishing, nginx-Config, Messenger-Worker, Redis
- [ ] MCP: Erweiterte Discovery, Authentifizierung, Netzwerkisolation
- [ ] Structured Output: Migration auf native `outputStructure` (Legacy-Pipeline noch aktiv)

## ⏳ Geplant

- [ ] Distributed Messenger Workers
- [ ] Advanced Scheduling (Cron-basierte Agent-Tasks)
- [ ] LLM-Latency/Token-Usage-Metriken (Observability-Erweiterung)
- [ ] GHCR Image Publishing CI-Step
- [ ] Docker Smoke Test in CI
- [ ] Version-Constraints in composer.json fixen (unbound `*`)
