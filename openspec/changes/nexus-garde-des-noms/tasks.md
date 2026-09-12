# Tasks

## 1. Le garde descend au cœur

- [x] 1.1 `NexusFulfilmentParameterNames::assertMatch()` dans `src/Durable/Nexus/Serving/`. Le corps
      est celui de `NexusHandlerPass::assertParameterNamesMatch()`, à un paramètre près : **qui
      refuse** est passé par l'appelant. Le lecteur d'un message d'erreur cherche ce qu'il doit
      corriger — une balise de service ou une clé de configuration —, pas la classe qui l'a levé.
- [x] 1.2 La passe délègue et perd sa méthode privée. Son message est inchangé, et
      `NexusHandlerPassTest` passe **sans être touché** : c'est le filet de l'extraction, pas une
      formalité. 12 tests, 29 assertions, verts avant comme après.

## 2. Le second hôte servant en hérite

- [x] 2.1 **RED d'abord.** `NexusOnLaravelTest::testAWorkflowWhoseParameterNamesDoNotMatchTheContractIsRefused`
      échoue avant la correction — *« Failed asserting that exception of type LogicException is
      thrown »* —, ce qui est la démonstration que le trou existait. Trois cas au total : le
      workflow qui couvre l'opération, celui dont `$ammount` diverge, et celui qui ajoute un
      paramètre facultatif.
      Les fixtures portent un contrat en **un seul morceau** dont le gestionnaire n'implémente
      qu'une opération : `DeclaredNexusOperations` lit par `method_exists()`, pas par la hiérarchie,
      et c'est ce chemin-là qu'il faut éprouver.
- [x] 2.2 `DeclaredNexusOperations` appelle le garde là où il enregistre un remplissement — donc à
      l'enregistrement, au démarrage de l'application, et pas à la première tâche Nexus, quand un
      appelant attend déjà une réponse.
- [x] 2.3 Vert : la suite `laravel` ne porte plus que les quatre erreurs d'environnement du poste
      (`illuminate/cache` n'est pas installé à la racine, la CI l'installe par sa matrice), et la
      suite `unit` complète passe — 1 070 tests, 2 704 assertions, zéro échec.

## 3. Le dire

- [x] 3.1 `UPGRADE.md` : la rupture, et pourquoi Rector ne peut rien pour elle — le bon nom est celui
      du contrat, et seul l'auteur sait de quel côté est la faute de frappe. Avec la phrase qui
      compte pour un exploitant : **aucune application dont les opérations Nexus fonctionnent n'est
      concernée**, le refus ne frappe que ce qui rendait déjà `null` en silence.
- [x] 3.2 Les **sept** endroits qui annonçaient l'absence du contrôle sont repris : le contrat
      `livraison`, le workflow `ExpedierWorkflow`, les README du banc Laravel et du paquet, le
      `demo/README.md`, et les deux langues de la page Nexus. Le §0.3 de
      `change/demo-nexus-laravel` garde sa phrase — elle était vraie quand elle a été écrite — et
      pointe désormais ce change.
