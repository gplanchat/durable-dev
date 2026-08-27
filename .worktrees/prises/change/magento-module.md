# change/magento-module

Reprise d'une prise froide (session 2f33a60c, arrêtée après le push de la PR #157
sans attendre la CI). Worktree déplacé de son scratchpad vers
`.claude/worktrees/magento`, arbre propre, HEAD = pointe poussée : rien perdu.

Deux temps :

1. **Clore la PR #157** — elle est rouge et `BEHIND`. Deux causes distinctes :
   php-cs-fixer sur les 4 fichiers de `src/DurableModule` (concaténation, corps
   de constructeur vide), et les tests d'intégration Temporal qui construisent
   `TemporalStartingEventStore`, classe retirée par DUR002/DUR019 — leurs deux
   fichiers ont été **supprimés sur `main`** par la PR #163, donc le merge de
   `main` les emporte. Ce n'est pas un défaut de la branche.
2. **Tranche 1.4** — mesurer si `LockManagerInterface` est partagé entre
   processus, à deux processus et non en lisant la classe. C'est le seul
   invariant du design, et il conditionne 4.3.
