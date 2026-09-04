# Audit de relecture — cœur, bundle Symfony, plugin Sylius

**3 septembre 2026**, sur `main` à `8fdc5ec4`.

Vingt relectures indépendantes, chacune conduite sur un axe distinct, puis consolidées.
Commencer par **[la synthèse](00-synthese.md)** : verdict, six bloquants, constats majeurs
dédupliqués, et ce qui a été vérifié comme sain.

## Méthode

Chaque axe a été relu séparément, sans que les relectures se voient les unes les autres. C'est ce
qui rend les recoupements exploitables : quand quatre axes atteignent le même défaut par quatre
chemins différents, le constat est mieux étayé qu'un constat isolé.

Trois règles communes :

- **Lecture seule.** Aucun fichier du dépôt n'a été modifié pendant l'audit.
- **Un `fichier:ligne` vérifié par constat.** Un constat sans ancre ne compte pas.
- **Une référence amont par règle.** Quand un constat s'appuie sur une convention de
  `symfony/symfony`, `Sylius/Sylius` ou `api-platform/core`, il cite la source ; à défaut il est
  marqué « non sourcé », et un constat non sourcé reste un constat, pas une preuve.

Chaque rapport plafonne à huit constats classés du plus grave au moins grave, et dit en une ligne
les axes sur lesquels le périmètre est sain plutôt que d'inventer un constat pour remplir.

## Périmètre

| Chemin | Contenu |
|---|---|
| `src/Durable/` | cœur — 207 fichiers, ~16 300 lignes |
| `src/DurableBundle/` | bundle Symfony — 23 fichiers, ~3 600 lignes |
| `src/DurablePlugin/` | plugin Sylius — 9 fichiers, ~550 lignes |
| `src/Bridge/Dbal/` | journal Doctrine DBAL |
| `src/Bridge/Temporal/` | **la partie écrite à la main seulement** — 94 fichiers sur 774 ; les 680 fichiers protobuf générés sont exclus |
| `documentation/`, `symfony/`, `sylius/` | documentation et les deux bancs d'essai |

Hors périmètre : les paquets Laravel et Magento.

## Les vingt axes

| Axe | Rapport |
|---|---|
| Cohérence Symfony, minimalisme, DX d'installation | [01](01-coherence-symfony.md) |
| PHP moderne, Fibers, rejeu, chemin chaud | [02](02-php-moderne-fibers.md) |
| Contrats d'interface, typage, generics | [03](03-contrats-typage.md) |
| État d'API, pagination, modèle de lecture HTTP | [04](04-etat-api.md) |
| Compilation du conteneur, paresse, coût au boot | [05](05-conteneur-performance.md) |
| Arbre de configuration, validation, messages d'erreur | [06](06-configuration.md) |
| Compatibilité PHP/Symfony, matrice CI, analyse statique | [07](07-compatibilite-statique.md) |
| Console et surface de sécurité | [08](08-console-securite.md) |
| Routing, HttpKernel, cycle de vie de la requête | [09](09-routing-http.md) |
| API publique, immutabilité, nommage, invariants | [10](10-api-publique.md) |
| Vocabulaire du domaine, versionnage, exploitabilité | [11](11-vocabulaire-exploitation.md) |
| Interopérabilité entre bundles, Doctrine, extension | [12](12-interop-doctrine.md) |
| Exactitude de la documentation vis-à-vis du code | [13](13-documentation.md) |
| Compiler passes, DataCollector, profiler | [14](14-passes-profiler.md) |
| Messenger : transports, stamps, retry, middleware | [15](15-messenger.md) |
| Prise en main, structure documentaire | [16](16-prise-en-main.md) |
| Composer, contraintes, découpage du monorepo | [17](17-composer-publication.md) |
| Conformité au squelette de plugin Sylius 2 | [18](18-plugin-sylius.md) |
| Pyramide de tests, déterminisme, conformance | [19](19-tests.md) |
| Back-office Sylius 2, Twig, grids, i18n | [20](20-admin-sylius.md) |

## Notes d'édition

Deux corrections apportées après la relecture croisée des PR, le 4 septembre 2026 :

- **[13](13-documentation.md), C3** affirmait que le mot « Psalm » n'apparaissait qu'une seule fois
  dans tout le dépôt. C'est faux : `psalm.xml`, `psalm-baseline.xml`, `psalm-magento.xml` et deux
  passages de `.github/workflows/ci.yml` le portent — et **[07](07-compatibilite-statique.md)**, dans
  ce même versement, consacre un constat entier à la baseline Psalm. Le constat de fond tient — le
  `suggest` ne cite qu'une extension, et l'ADR renvoyé est le mauvais — c'est sa preuve qui était
  fausse, et elle est retirée. Deux rapports d'un même audit ne peuvent pas s'appuyer sur des faits
  incompatibles sans que le lecteur ait le droit de le savoir.

## Ce que l'audit n'a pas vu

Un audit est une lecture de code, et deux classes de défaut lui échappent par construction. Les
issues ouvertes du dépôt, qui sont des rapports d'exploitation, en portent des exemples :

- **Ce qui ne se voit qu'au rendu.** Une charge utile qui s'affiche blanc sur blanc parce qu'une
  feuille de style tierce repeint un fond sans toucher la couleur ne se déduit pas du gabarit.
- **Ce qui ne se voit qu'à l'usage.** Qu'aucun message durable ne soit routé par défaut se lit dans
  le code comme une absence de recette ; que la conséquence soit un workflow qui rejoue dans la
  requête web et meurt avec le process, il faut l'avoir vécu.

Symétriquement, les rapports d'exploitation ne signalent pas ce qui se paie en latence plutôt qu'en
erreur — un rejeu quadratique — ni ce qui attend un outil que personne n'a encore lancé — un
`doctrine:migrations:diff` sur un journal que Doctrine ne connaît pas.

## Suites engagées

- **DUR041** décrivait une couverture de conformité qui n'existe pas ; repris.
- **`backend-data-parity`** — change OpenSpec ouvert à partir des constats sur l'identité d'une
  exécution et sur la parité entre les quatre backends.

Les autres constats n'ont pas tous de suite : les rapports sont un état des lieux daté, pas une
liste de tâches acceptées.
