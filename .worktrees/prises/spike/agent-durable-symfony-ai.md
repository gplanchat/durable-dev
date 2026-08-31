# spike/agent-durable-symfony-ai

- **Chantier** : prototype d'agent durable — rendre `Runner::run()` de Symfony AI rejouable en le pilotant depuis du code workflow, via deux adaptateurs (`PlatformInterface`, `ToolExecutorInterface`). Vérifie les quatre questions du §7 de `documentation/journal/inbox/2026-08-31.md`.
- **Entrées** : `symfony/` (app d'exemple) uniquement — pas de package, pas de bundle, pas d'ADR.
- **État** : spike. **Branche non publiée**, pas de PR. `symfony/ai` épinglé v0.13.0 (`Runner` est `@internal`).
