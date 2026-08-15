# Test-Strategie

## Pyramide

```text
         ┌─────────┐
         │   E2E    │  ← Auth, Navigation, Pages (3 Envs)
         ├─────────┤
         │Integration│  ← Evolution-Flow, Streaming
         ├─────────┤
         │ Security  │  ← SSRF, Filesystem, Command, Prompt-Injection
         ├─────────┤
         │   Unit    │  ← DynamicToolbox, HitlListener, SecurityGuard, …
         └─────────┘
```

## Suiten

| Suite | Tests | Zweck |
|-------|-------|------|
| E2E Tests | AuthFlow, NavigationPages | Vollständige App gegen test/dev/prod |
| EVIE AI Unit Tests | OrchestratorAgent, DynamicToolbox, ContextInjector, McpServerFactory, Streaming | Einzelne Klassen |
| EVIE AI Security Tests | SecurityGuard, SecurityGuardDecision, SecurityHardening, SsrfBypass, TenantIsolation, HitlListener | Angriffsvektoren |
| EVIE AI Skills Tests | DynamicToolExecutor | Tool-System |
| EVIE AI Agent Tests | OrchestratorAgent | Agent-Verhalten |
| EVIE AI Integration Tests | EvolutionFlow, Streaming | Komponenten zusammen |

## CI-Pipeline

```text
composer install
  → E2E (test env) → E2E (dev env) → E2E (prod env)
  → Unit → Security → Skills → Agent → Integration
  → composer validate → composer audit → PHPStan
```

Alle Suiten müssen grün sein, bevor ein Merge nach `main` erfolgt.

## Security-Test-Abdeckung

| Angriffsvektor | Test-Klasse | Test-Methode |
|----------------|------------|--------------|
| SSRF 127.0.0.1 | SecurityHardeningTest | testSsrfBlocksLoopback127 |
| SSRF localhost | SecurityHardeningTest | testSsrfBlocksLocalhost |
| SSRF 169.254.169.254 | SecurityHardeningTest | testSsrfBlocksLinkLocal169_254_169_254 |
| SSRF private IPv4 | SecurityHardeningTest | testSsrfBlocksPrivateRange10/192_168/172_16 |
| SSRF 0.0.0.0 | SecurityHardeningTest | testSsrfBlocksWildcardBind0_0_0_0 |
| SSRF IPv6 ::1 | SecurityHardeningTest | testSsrfBlocksIpv6Loopback |
| SSRF IPv6 fe80:: | SecurityHardeningTest | testSsrfBlocksIpv6LinkLocal |
| Filesystem /etc/passwd | SecurityHardeningTest | testFilesystemBlocksEtcPasswd |
| Filesystem docker.sock | SecurityHardeningTest | testFilesystemBlocksDockerSocket |
| Filesystem /proc, /sys, /dev | SecurityHardeningTest | testFilesystemBlocksProc/Sys/Dev |
| Command shell/bash | SecurityHardeningTest | testCommandExecutionDeniesShellExecutor |
| Prompt Injection | SecurityHardeningTest | testRagContextCannotBypassSsrfCheck |
| HITL high security | SecurityHardeningTest | testHighSecurityLevelTriggersAskUser |
