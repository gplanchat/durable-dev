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

- [ ] 5.1 Les **deux** paires manquantes, et deux seulement : `EventStoreConformanceTestCase` pour
      les deux stores Temporal — le palier port, celui qu'un adaptateur adossé à un serveur doit
      étendre — et `WorkflowRunCatalogConformanceTestCase` pour `TemporalWorkflowRunCatalog`. Elles
      vont dans la testsuite `integration`, gated sur `DURABLE_TEMPORAL_ADDRESS` : une case gated
      est une case déclarée, pas une absence.
      `WorkflowMetadataStore` et `ChildWorkflowParentLinkStore` n'ont **pas** d'implémentation
      Temporal — ce ne sont pas des trous, et leur inventer un adaptateur ne relève pas de ce
      change.
- [ ] 5.2 Ce que ces suites font tomber est **le résultat du chantier**, pas un contretemps :
      chaque échec est une divergence que personne ne mesurait. Les consigner une par une avant de
      les corriger, et arbitrer pour chacune qui a raison — le port ou le backend.
- [ ] 5.3 Un job CI qui rend la grille lisible : pour chaque port, chaque backend qui l'implémente,
      et l'état de la paire — *passante*, *gated*, ou **absente**. Une paire absente échoue le job,
      et une paire qui n'existe pas n'est pas comptée. C'est ce qui empêche la grille de se
      retrouver incomplète une seconde fois sans que personne ne le voie.

## 6. Le dire

- [x] 6.1 **DUR041 porte l'état réel** — fait hors de ce change, PR #270 : tableau de couverture,
      et les deux phrases au présent de l'indicatif conservées et annotées plutôt que réécrites. Le
      statut « implemented for all four ports » était exact *des ports* ; c'est la couverture des
      adaptateurs qu'il ne donnait pas.
- [x] 6.2 Le docbloc d'`EventStoreReplayConformanceTestCase`, qui reprenait le même énoncé faux, est
      corrigé dans la même PR #270 — sinon le lecteur qui vérifie l'ADR dans le code y retombe.
- [ ] 6.2b Quand 5.3 existe, DUR041 pointe le job plutôt que de porter un tableau daté.
- [ ] 6.3 Un ADR pour l'identité : pourquoi le nom donné par l'appelant, pourquoi ni `runId`, ni
      `groupId`, ni la paire, et pourquoi le memo plutôt qu'une dérivation du workflow id.
- [ ] 6.4 `UPGRADE.md` : la rupture ne touche que qui **implémente** un port de stockage. Une règle
      Rector réécrit les constructions positionnelles de `WorkflowRunDescription` et pose
      `executionId: $runId` là où `groupId` est absent — le seul cas où l'outil peut déduire. Un
      backend tiers doit dire quelle est son identité d'exécution, ce qu'aucun outil n'invente : la
      note le dit en toutes lettres.
