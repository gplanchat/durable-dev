# spike/agent-durable-symfony-ai

- **Chantier** : prototype d'agent durable — rendre `Runner::run()` de Symfony AI rejouable en le pilotant depuis du code workflow, via deux adaptateurs (`ModelClientInterface`, `ToolExecutorInterface` — la couture basse n'est pas `PlatformInterface`, cf. §8 du 2026-08-31). Vérifie les quatre questions du §7 de `documentation/journal/inbox/2026-08-31.md`.
- **Entrées** : `symfony/` (app d'exemple) uniquement — pas de package, pas de bundle, pas d'ADR.
- **État** : spike. **Branche non publiée**, pas de PR. `symfony/ai` épinglé v0.13.0 — qui est la
  dernière version publiée, pas un vieux pin. La raison est que `symfony/ai` est en 0.x, sans
  promesse de compatibilité entre mineures : les coutures utilisées (`ModelClientInterface`,
  `ResultConverterInterface`, `ToolExecutorInterface`, `ToolboxInterface`) sont toutes publiques,
  et `Runner` — qui est `@internal` — n'est jamais touché.
