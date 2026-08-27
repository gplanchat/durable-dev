# Prises en cours

Plusieurs sessions partagent cette copie. Une tranche se prend en écrivant sa ligne ici **avant**
de commencer, et la ligne se retire à la fusion. Son absence est ce qui a permis à deux sessions
de se croiser sur `workflow-conditions-and-handler-dispatch` le 2026-08-26.

**Une prise se pousse sur `main` avant de commencer, pas dans sa propre branche.** Une ligne écrite
dans un worktree n'existe que là : personne ne la voit avant la fusion, c'est-à-dire une fois le
travail fait. Le 2026-08-26, neuf tranches ont été construites en double pour cette raison — le
bloc 4 entier de `temporal-nexus-support` deux fois, la même classe sous le même nom, la même garde
écrite à l'identique. Le registre n'empêche une collision que s'il est lu **et écrit** sur `main`.

Une ligne : `<branche> — <chantier> <entrées> — <état>`

| Branche | Chantier | Entrées | État |
|---|---|---|---|
| `docs/adr-dur035` | workflow-conditions-and-handler-dispatch | 8.3 (ADR) | en relecture |
| `feat/nexus-operation-failure` | temporal-nexus-support | 3.6 (echec type + classification) | en cours |
| `feat/nexus-cancel-read-and-guard` | temporal-nexus-support | 3.5 | en relecture |
| `docs/nexus-adr-index` | temporal-nexus-support | 7.2 | en relecture |
| `chore/archive-nexus-support` | temporal-nexus-support | archivage + change de suite pour les en-têtes | en cours |
