## Why

Le mode d'échec le plus silencieux de Nexus est un nom de paramètre. La charge est clée **par nom**
aux deux bouts — `NexusStub::argumentsToPayload()` l'écrit depuis la signature du contrat,
`WorkflowDefinitionLoader::mapInputToArguments()` la relit dans le workflow qui remplit l'opération.
Un paramètre renommé d'un seul côté ne casse rien à l'écriture, ne lève rien à l'exécution, et
arrive à `null` : le workflow démarre, s'exécute, et rend un résultat calculé sur du vide.

Le refus existe. Il est dans `NexusHandlerPass`, **en privé**, donc il n'existe que pour les
applications Symfony. `change/demo-nexus-laravel` vient de mettre un second hôte servant en
production de démonstration — `gplanchat/durable-laravel`, qui déclare ses gestionnaires dans
`config/durable.php` — et a dû écrire l'avertissement à cinq endroits pour dire que là, personne ne
vérifiait. Cinq avertissements sont ce qu'on écrit quand on ne peut pas encore corriger.

## What Changes

Le contrôle descend au cœur, en une classe :
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`. Deux réflexions et une lecture de
`#[AsWorkflowMethod]` — rien de tout cela n'appartenait à un framework.

- `NexusHandlerPass` l'appelle et perd sa copie privée. Son message ne change pas, et ses tests non
  plus : c'est le filet de la migration.
- `DeclaredNexusOperations` l'appelle au moment où il enregistre un remplissement, donc **à
  l'enregistrement** et non à la première tâche.
- Le préfixe du message est fourni par l'hôte — `durable.nexus_handler` pour la balise Symfony,
  `durable.nexus.handlers` pour la clé Laravel : le lecteur doit trouver ce qu'il doit corriger, pas
  la classe qui refuse.

Un paramètre **facultatif** passe, ici comme là-bas : donner une valeur par défaut à un paramètre que
le contrat ne porte pas est une décision, l'absence de défaut est une attente déçue.

## Impact

- `src/Durable/` : une classe de plus, sans dépendance.
- `src/DurableBundle/` : un appel remplace une méthode privée. Aucun changement de comportement.
- `src/DurableLaravel/` : **un refus qui n'existait pas**. Documenté dans `UPGRADE.md` — aucune
  application dont les opérations Nexus fonctionnent n'est concernée, puisque le refus ne frappe que
  des configurations qui rendaient déjà `null` en silence.
- Les cinq avertissements de `change/demo-nexus-laravel` qui annonçaient l'absence du contrôle sont
  remplacés par ce qu'ils décrivent maintenant.
