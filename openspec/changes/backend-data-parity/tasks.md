# Tasks

## 0. Sonder le serveur avant d'écrire le moindre invariant

La règle de la maison, et elle a déjà corrigé six hypothèses fausses. Les quatre points ci-dessous
sont **supposés**, pas vérifiés, et `design.md` les liste comme tels. Rien de la §1 ne commence
avant que cette section soit close.

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

- [ ] 0.1 **Un `run_id` vide résout-il le run courant ?** Démarrer une exécution, appeler
      `DescribeWorkflowExecution` et `GetWorkflowExecutionHistory` avec `runId: ''`, comparer au
      `runId` explicite. Toute la recherche en un aller-retour tient là-dessus, et le composant ne
      l'a jamais éprouvé : `TemporalWorkflowRunCatalog::readHistory()` garde `'' === $run->runId` et
      rend `[]`. Si c'est faux, la recherche coûte un `ListWorkflowExecutions` de plus — un coût,
      pas un blocage.
- [ ] 0.2 **`ListWorkflowExecutions` remplit-il `memo` ?** Le proto porte le champ ; que le magasin
      de visibilité le remplisse est une autre affirmation. **C'est la question qui décide de la
      forme du change** : si la réponse est non, `listRuns()` ne peut pas remplir `executionId` sans
      un `DescribeWorkflowExecution` par ligne, ce qui est inacceptable pour une page de liste, et
      il faut alors promouvoir l'id d'exécution en **attribut de recherche** plutôt qu'en memo —
      donc modifier `WorkflowClient` et rapatrier ce basculement dans ce change.
- [ ] 0.3 **Le memo est-il interrogeable ?** Tenter une requête de visibilité dessus. S'il l'est, le
      détour par `workflowId()` disparaît et le design se simplifie.
- [ ] 0.4 **Le memo survit-il au continue-as-new ?** Enchaîner une continuation et relire le memo
      sur le nouveau run. S'il ne survit pas, décider : le client le repose, ou l'identité se lit
      sur le premier run de la chaîne.
- [ ] 0.5 Consigner les quatre réponses dans `design.md`, à la place des hypothèses — en gardant
      trace de ce qui a été supposé, pour que le prochain lecteur sache ce qui a été mesuré.

## 1. L'identité entre dans le modèle

- [ ] 1.1 **RED.** `WorkflowRunCatalogConformanceTestCase` gagne le cas d'identité : démarrer,
      relister, vérifier que `executionId` est le nom donné. Il échoue sur les quatre backends,
      puisque le champ n'existe pas. C'est la démonstration que le trou est commun.
- [ ] 1.2 `WorkflowRunDescription` gagne `executionId` en premier paramètre. Les trois backends
      journal le remplissent avec ce qu'ils mettent déjà dans `runId` ; l'appel positionnel de
      `InMemoryWorkflowRunCatalog:107` passe en arguments nommés au passage — le prochain paramètre
      ajouté ne doit plus casser un site d'appel.
- [ ] 1.3 Temporal le remplit depuis le memo, dans `describe()`, à la ligne où il lit déjà le
      workflow id. Zéro RPC supplémentaire **si 0.2 a répondu oui** ; sinon, appliquer la décision
      de 0.2 avant cette tâche.
- [ ] 1.4 `RunDashboard::pick()` compare `executionId` et non plus `runId`. C'est le seul
      consommateur d'identité du cœur, et c'est lui qui rendait le `?run=` de l'admin ambigu.

## 2. Ouvrir un run par son identifiant

- [ ] 2.1 **RED.** Trois cas dans la suite de conformité : un identifiant connu rend son run, un
      identifiant plus vieux que la première page le rend aussi *sans pagination*, un identifiant
      inconnu se distingue d'un run sans historique.
- [ ] 2.2 `findRun(string $executionId): ?WorkflowRunDescription` au port, et ses quatre
      implémentations. Mémoire : une lecture de tableau. DBAL et Illuminate : un `WHERE
      execution_id = ?` — clé primaire, aucun index à ajouter. Temporal : un
      `DescribeWorkflowExecution` selon 0.1.
- [ ] 2.3 Le cas « sans pagination » se prouve : la suite écrit plus de runs qu'une page n'en tient,
      puis compte les appels de lecture. Un `findRun` qui pagine en douce passerait sinon.

## 3. Le curseur et l'historique vide

- [ ] 3.1 **RED.** La propriété de pagination dans la suite : écrire pendant qu'on lit, vérifier que
      chaque run préexistant apparaît une fois et une seule. Attendu : le backend mémoire échoue —
      son curseur est le runId nu, repris par `array_search`.
- [ ] 3.2 Le curseur mémoire devient une position encodée, comme les trois autres. Aucune forme
      commune n'est imposée : c'est la propriété qui est le contrat, pas l'encodage.
- [ ] 3.3 **RED.** Un cas qui prouve que toute description rendue par un catalogue est relisable par
      ce même catalogue. Attendu : Temporal échoue quand `groupId` manque, puisqu'il rend `[]`.
- [ ] 3.4 `readHistory()` cesse de rendre `[]` pour une description inutilisable. Une description que
      le catalogue a produite porte ce qu'il faut ; si elle ne le porte pas, c'est le catalogue qui
      est en faute et il doit le dire.

## 4. La collision d'identifiants héritée

- [ ] 4.1 **RED.** `order/17` et `order-17` démarrés successivement : aujourd'hui ils partagent un
      workflow id Temporal. Le cas doit refuser le second, pas le confondre avec le premier.
- [ ] 4.2 `startAsync()` refuse un id d'exécution que la sanitisation modifierait, avec un message
      qui nomme le caractère fautif. Pas de suffixe de hachage sur `workflowId()` : ce serait changer
      l'identifiant de toutes les exécutions en vol pour un cas que l'appelant peut éviter.
- [ ] 4.3 Le refus est documenté là où l'on nomme une exécution — la page de démarrage du guide et le
      README du pont.

## 5. Les quatre cases vides de la grille

- [ ] 5.1 Les quatre sous-classes Temporal manquantes : `EventStoreReplayConformanceTestCase`,
      `WorkflowMetadataStoreConformanceTestCase`, `WorkflowRunCatalogConformanceTestCase`,
      `ChildWorkflowParentLinkStoreConformanceTestCase`. Elles vont dans la testsuite `integration`,
      gated sur `DURABLE_TEMPORAL_ADDRESS` — une case gated est une case déclarée, pas une absence.
- [ ] 5.2 Ce que ces quatre suites font tomber est **le résultat du chantier**, pas un contretemps :
      chaque échec est une divergence que personne ne mesurait. Les consigner une par une avant de
      les corriger, et arbitrer pour chacune qui a raison — le port ou le backend.
- [ ] 5.3 Un job CI qui rend la grille lisible : quatre ports × quatre backends, chaque case
      *passante*, *gated* ou **absente**. Une case absente échoue le job. C'est ce qui empêche la
      grille de se retrouver à 12/16 une seconde fois.

## 6. Le dire

- [ ] 6.1 **DUR041 est repris** : il se déclare « implemented for all four ports », ce qui était faux
      au moment où on l'a écrit. Il dit désormais l'état réel et ce qui le tient — le job de 5.3.
- [ ] 6.2 Le docblock du cœur qui affirme que les deux stores Temporal étendent la suite est corrigé
      ou devient vrai. Il a menti à au moins un lecteur, qui est l'audit.
- [ ] 6.3 Un ADR pour l'identité : pourquoi le nom donné par l'appelant, pourquoi ni `runId`, ni
      `groupId`, ni la paire, et pourquoi le memo plutôt qu'une dérivation du workflow id.
- [ ] 6.4 `UPGRADE.md` : la rupture ne touche que qui **implémente** un port de stockage. Une règle
      Rector réécrit les constructions positionnelles de `WorkflowRunDescription` et pose
      `executionId: $runId` là où `groupId` est absent — le seul cas où l'outil peut déduire. Un
      backend tiers doit dire quelle est son identité d'exécution, ce qu'aucun outil n'invente : la
      note le dit en toutes lettres.
