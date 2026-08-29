# docs/archiver-magento-module

Le geste de clôture de `change/magento-module`, passé à 34/34 avec la #226.

Trois choses :

1. `openspec/specs/magento-host/spec.md` — capacité neuve. Le delta n'avait pas
   de `## Purpose`, comme celui de `laravel-host` ; il en fallait un.
2. `openspec/specs/workflow-run-observation/spec.md` — une exigence **remplacée**
   et non ajoutée : « Reading a run's recorded history » passe de 3 à 10
   scénarios, l'écran de détail ayant appris à distinguer une action d'un
   événement et une attente d'un travail.
3. `openspec/changes/magento-module/` → `archive/2026-08-29-magento-module/`,
   et **la prise du chantier tombe dans le même commit**. Elle n'est pas
   optionnelle : sans tâche ouverte et sans PR, `bin/prises-check.sh` la
   déclarerait périmée et ferait rougir toutes les PR ouvertes.

Aucun code touché.
