# ADR-004: Tenant-Isolation via UserContext

## Context
EVIE soll Multi-Tenant-fähig sein.

## Problem
Ohne Tenant-Filter kann Tenant A Daten von Tenant B sehen.

## Decision
`UserContext`-Service extrahiert den User-Identifier aus dem Request.
Repository-Queries und Store-Abfragen filtern server-seitig.
`StoreRetrieverAdapter` injiziert `user_identifier` in Metadata.

## Consequences
- Jede Datenabfrage muss user_identifier-gefiltert sein
- RAG-Kontext ist tenant-spezifisch
- TenantIsolationTest verifiziert Isolation
