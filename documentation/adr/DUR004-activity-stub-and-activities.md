# DUR004 — ActivityStub, activités et méthodes d’activité

## Statut

Accepté

## Contexte

Les **workflows** (DUR003) sont déterministes et sans I/O. Les **effets de bord** (bases de données, HTTP, fichiers, etc.) vivent dans des **activités** : classes fournies par l’utilisateur du composant, exécutées dans un environnement où I/O et non-déterminisme sont permis, sous les politiques de retry de l’orchestrateur.

Le workflow doit pouvoir **appeler** ces activités via une abstraction stable : l’**ActivityStub**, qui redirige les appels vers l’infrastructure Temporal (ou sa simulation In-Memory) tout en conservant des types PHP côté auteur de workflow.

## Décision

### Activités

- Les activités sont des **classes** écrites par l’utilisateur du composant.
- Elles **peuvent** accéder aux **I/O** et aux services injectés (DI), dans les limites fixées par l’hôte.
- Les **méthodes** exposées comme points d’entrée d’activité sont marquées avec un attribut du composant, par exemple **`#[ActivityMethod]`** (nom final aligné sur l’implémentation).
- Les **types des arguments** et des **valeurs de retour** doivent être **sérialisables** par le pipeline du composant vers Temporal (pas de ressources, closures non supportées, etc.). Le mécanisme retenu est décrit dans **DUR007** (composant **Serializer** de Symfony).

### ActivityStub

- Au sein du **workflow**, l’auteur n’instancie pas directement l’activité pour les effets durables : il utilise un **ActivityStub** (ou fabrique équivalente) qui :
  - **route** les appels de méthode vers l’**activité** correspondante côté worker / orchestration ;
  - garantit que l’appel est modélisé comme une étape durable (enregistrée dans l’historique, rejouable).

### Séparation workflow / activité

| Workflow | Activité |
|----------|----------|
| Déterministe, sans I/O direct | I/O et logique non déterministe possible |
| Contexte awaitables + fibers (DUR003) | Exécution « normale » côté worker |
| Idempotence logique du graphe d’orchestration | Idempotence opérationnelle recommandée pour les retries |

### Relation avec les stubs

- Un **stub** est lié à un **type d’activité** (interface ou classe) et permet d’invoquer les méthodes marquées comme des **commandes durables** depuis le workflow.
- La résolution du binding (nom d’activité Temporal, timeouts, retry) relève de la configuration du composant et des adaptateurs.

## Conséquences

- La sérialisation des payloads d’activité est un contrat public (voir **DUR007**) : toute évolution doit gérer la compatibilité ascendante ou des stratégies de migration.
- Les META documents peuvent détailler les patterns d’attributs, de noms et de registration sans diluer cette ADR.
