# Memory

EVIE nutzt das native Symfony AI `MemoryProviderInterface`.

## ContextMemoryProvider

Der `ContextMemoryProvider` implementiert
`Symfony\AI\Agent\Memory\MemoryProviderInterface` und lädt beim jedem Agent-Call
den Benutzerkontext aus dem `ContextStoreManager`:

```text
User → ContextMemoryProvider.load(Input)
  → ContextStoreManager.loadContext(userIdentifier)
  → Memory[] (User Type, Preferences)
  → Agent
```

Der `userIdentifier` wird aus den `Input::options` extrahiert und ermöglicht
tenant-spezifisches Memory.
