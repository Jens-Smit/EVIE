# ADR-005: RAG als nativer InputProcessor

## Context
RAG-Kontext muss bei jedem Agent-Call in den MessageBag injiziert werden.

## Problem
Eigenbau-Decorator erstellen parallele Logik neben Symfony AI.

## Decision
`ContextInjector` implementiert `Symfony\AI\Agent\InputProcessorInterface`
mit `#[AsInputProcessor]`. `processInput(Input)` ruft den Retriever und fügt
Kontext als `Message::forSystem()` in den MessageBag ein.

## Consequences
- Native Registrierung im Agent-Loop
- StoreRetrieverAdapter bridgt zur Symfony\AI\Store\RetrieverInterface
- RAG-Kontext erhält nie gleiche Vertrauensstufe wie System-Instructions
