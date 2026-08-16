# Production-Readiness Härtung

> **Stand:** August 2026 — alle kritischen Audit-Findings aus
> `docs/temp/audit.md` sind geschlossen. Dieses Dokument dokumentiert die
> Umsetzung der 5-Phasen-Roadmap (`docs/temp/roadmap.md`) und dient als
> Nachweis der Prod-Readiness.

## Übersicht: Audit-Findings und Behebung

Das Audit (`docs/temp/audit.md`) identifizierte 34 Findings in den
Kategorien Security (11), Performance (8) und Code Quality (15+). Die
kritischen und High-Priority-Findings wurden in den Phasen 1-3 geschlossen.

---

## Phase 1: Security (alle 11 Findings geschlossen)

| # | Finding | Behebung | Beweis |
|---|---------|----------|--------|
| 1 | SSRF-Bypass über DNS-Rebinding | `OutboundRequestPolicy::resolveAllowedIp()` für TOCTOU-IP-Pinning; `SecurityGuard::isUrlSafe()` nutzt Defense-in-Depth mit injizierter Policy | `src/AI/Security/OutboundRequestPolicy.php` |
| 2 | IPv6-Normalisierung unvollständig | `isPrivateIpv6()` nutzt `inet_pton`/`inet_ntop` zur Kanonisierung; erkennt komprimierte Formen (`::`, `fd00::1`), IPv4-mapped (`::ffff:127.0.0.1`) | `src/AI/Security/OutboundRequestPolicy.php` |
| 3 | Command Injection False Negatives | `SecurityGuard::containsShellMetacharacters()` blockt Command-Chaining/Substitution in String-Argumenten | `src/AI/Security/SecurityGuard.php` |
| 4 | Fehlende Rate Limiting | `DialogRateLimiter` (sliding window, 429+Retry-After) + `ToolCallLimitProcessor` (Hard-Limit 20 Tool-Calls/Request) | `src/EventListener/DialogRateLimiter.php`, `src/AI/Security/ToolCallLimitProcessor.php` |
| 5 | Keine Input-Validierung | Tool-Argumente werden über `SecurityGuard::decide()` auf URLs, Pfade und Shell-Metazeichen geprüft | `src/AI/Security/SecurityGuard.php` |
| 6 | Sensitive Data in Logs | `AgentDialogController` loggt nur noch Content-Type, nicht den Payload; `AuditLogger::redact()` redigiert Secrets in Audit-Logs | `src/Controller/AgentDialogController.php`, `src/AI/Security/AuditLogger.php` |
| 7 | Fehlender CSRF-Schutz | Framework-CSRF aktiviert (`csrf_protection: true`), `CsrfTokenBadge` im Login-Passport | `config/packages/framework.yaml`, `src/Security/Authenticator/LoginFormAuthenticator.php` |
| 8 | Session Fixation | Symfony `SessionAuthenticationStrategy::MIGRATE` (Default, regeneriert Session-ID) + `cookie_httponly: true` | `config/packages/framework.yaml` |
| 9 | Password-Reset-Token nicht single-use | `ResetPasswordTokenGenerator::consume()` entfernt Token nach Nutzung | `src/Security/ResetPasswordTokenGenerator.php` |
| 10 | Fehlende Security Headers | `SecurityHeadersListener`: nosniff, X-Frame-Options DENY, Referrer-Policy, Permissions-Policy, HSTS | `src/EventListener/SecurityHeadersListener.php` |
| 11 | Stack Traces in Produktion | `APP_DEBUG=0` in `docker-compose.prod.yml`; Symfony liefert keine Stack-Traces an Clients | `docker-compose.prod.yml` |

### Nicht-kanonische IP-Formate (zusätzlich zu #2)

`SecurityGuard::normalizeHost()` erkennt Dezimal (`2130706433`), Hex
(`0x7f000001`), Oktal (`0177.0.0.1`), kurze Form (`127.1`) und
IPv4-mapped IPv6 (`::ffff:127.0.0.1`). Getestet in `SsrfBypassTest`.

---

## Phase 2: Performance (alle 8 Findings adressiert)

| # | Finding | Behebung | Beweis |
|---|---------|----------|--------|
| 1 | N+1 Query Problem | `EmbeddingRepository::loadCandidates()` filtert Kandidaten per `WHERE contentType = ?` statt alle via `findBy()` zu laden | `src/Repository/EmbeddingRepository.php` |
| 2 | Kein Embedding-Cache | `VectorStore::findCachedByContentHash()` cached DB-Lookups in `cache.app`, mit Invalidation beim Speichern | `src/AI/Rag/VectorStore.php` |
| 3 | Keine Vektor-Such-Optimierung | Postgres: nativer JSON-Tenant-Filter (`metadata->>'user_identifier'`) reduziert Kandidatenmenge serverseitig | `src/Repository/EmbeddingRepository.php` |
| 4 | Kein Connection Pooling | Nginx-Upstream `keepalive 32` zum PHP-FPM + Doctrine `options.persistent: true` (Prod); PgBouncer empfohlen | `docker/nginx/prod.conf`, `config/packages/doctrine.yaml` |
| 5 | Keine HTTP/2 | Nginx `http2 on` | `docker/nginx/prod.conf` |
| 6 | Keine Compression | Gzip mit `gzip_vary`, `gzip_proxied`, `gzip_comp_level 5`, erweiterte MIME-Types | `docker/nginx/prod.conf` |
| 7 | Autowiring Overhead | Konfigurationskonsolidierung (Prod: `auto_generate_proxy_classes: false`, Cache-Pools) | `config/packages/doctrine.yaml` |
| 8 | Keine Asset-Optimierung | OPcache in Produktion (preload, `validate_timestamps=0`); Asset-Bundling ist Frontend-Aufgabe | `docker/php/Dockerfile.prod` |

---

## Phase 3: CI/CD & Testverbesserungen

| Maßnahme | Status | Beweis |
|----------|--------|--------|
| Security Scans | ✅ | `composer audit` (mit Dev) + `composer audit --no-dev` (Prod) in CI |
| Coverage-Reporting | ✅ | pcov + `--coverage-clover=coverage-unit.xml` + Artefakt-Upload |
| PHPStan als Gate | ✅ | Level 5, mit Baseline, kein `\|\| true`-Bypass |
| `composer validate --strict` | ✅ | In CI als blockierender Schritt |
| Postgres-Schema-Validierung | ✅ | Separater CI-Job gegen echte pgvector-Instanz |
| Test-Kategorisierung | ✅ | Unit/Security/Skills/Agent/Functional/Integration/E2E/Smoke |

---

## Verbleibende offene Punkte (nicht Prod-blockierend)

Diese Punkte sind dokumentiert, aber nicht Prod-blockierend und ausserhalb
des aktuellen Scopes:

- **LLM-Latency/Token-Usage-Metriken**: Observability-Erweiterung für
  KI-Aufrufe (separater Observability-Punkt).
- **Externes Log-Aggregation**: ELK/Loki-Integration (Infrastruktur).
- **GHCR Image Publishing + Docker Build in CI**: CI-Erweiterung für
  Container-Builds.
- **Content-Security-Policy**: muss mit dem HTMX-Frontend (Inline-Scripts)
  zusammen entwickelt werden.
- **pgvector-Typ-Migration**: `vector`-Feld von JSON auf echten pgvector-Typ
  mit `<=>`-Operator migrieren (grösserer Refactor).
- **Orchestrierungs-Konsolidierung**: mehrere parallele Schichten
  (`OrchestratorDialogService`, `WorkflowOrchestrator`, etc.) konsolidieren.

---

## Referenzen

- Audit: `docs/temp/audit.md`
- Roadmap: `docs/temp/roadmap.md`
- Fortschritts-Doku: `docs/temp/roadmap-progress.md`
- Production-Readiness-Checkliste: `docs/PRODUCTION_READINESS_CHECKLIST.md`
- Deployment: `docs/deployment/production.md`
- Security-Architektur: `docs/security/security-architecture.md`
