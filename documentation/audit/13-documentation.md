# Exactitude de la documentation — conventions des composants récents, doc contre code

## Synthèse

Le périmètre est sain sur l'axe le plus dur : un balayage des 51 FQCN `Gplanchat\…` cités dans les
README, `UPGRADE.md` et `documentation/user/` ne trouve **aucun exemple PHP qui ne résoudrait pas** —
les 9 noms absents de `src/` sont tous des anciens noms, correctement présentés comme tels dans
`UPGRADE.md`. Les cinq ADR cités (DUR006, DUR030, DUR037, DUR038, DUR041) existent tous et disent
bien ce que les README leur font dire. Ce qui décroche est ailleurs : la **référence de
configuration**, qui se réclame exhaustive, ignore un nœud entier du `Configuration.php` et se
trompe sur un défaut ; le README du pont Temporal documente deux des quatre `purpose` que le code
accepte — les deux manquants étant précisément ceux que le guide utilisateur fait écrire. Le reste
tient de la métadonnée : un tableau racine en retard d'un paquet publié, une contrainte Symfony
recopiée à la main qui ment sur le `composer.json`, et quatre surfaces publiques en français sans
contrepartie anglaise.

## Constats

### C1 — La référence de configuration se dit exhaustive et ignore le nœud `dbal` en entier

- **Fichier** : `documentation/user/configuration/_index.md:8` (« every key accepted by `DurableBundle` ») contre `src/DurableBundle/DependencyInjection/Configuration.php:18-25,30,50,74,83`
- **Gravité** : majeur
- **Constat** : Le nœud `durable.dbal` (`connection`, `lock_factory`) est absent de la page — ni exemple, ni section. Les quatre clés `table_name` (`event_store`, `workflow_metadata`, `activity_transport`, `child_workflow.parent_link_store`) ne sont documentées nulle part. Les trois `enumNode('type')` acceptent `['in_memory', 'dbal']` (lignes 29, 73, 82) alors que les tableaux de la page (`:45`, `:93`, `:146`) ne listent que `in_memory` — la page se contredit elle-même en `:58`, où sa prose parle de `event_store.type: dbal`. Enfin `:103` annonce `messenger` comme défaut de `activity_transport.type`, quand `Configuration.php:49` fait `->defaultValue('in_memory')`.
- **Amont** : le code lui-même (`Configuration.php`) ; c'est la sortie de `bin/console config:dump-reference durable` qui fait foi, comme pour tout bundle Symfony.
- **Correctif** : régénérer la page depuis `config:dump-reference` plutôt que de la maintenir à la main, ou au minimum ajouter la section `dbal`, les quatre `table_name`, la valeur `dbal` dans les trois énumérations et corriger le défaut de `activity_transport.type`.

### C2 — Le README du pont Temporal documente deux des quatre `purpose` que la fabrique accepte

- **Fichier** : `src/Bridge/Temporal/README.md:17` et `:29-31` contre `src/Bridge/Temporal/Messenger/TemporalTransportFactory.php:70,80,90`
- **Gravité** : majeur
- **Constat** : Le README annonce « **`options.purpose`** (`journal` \| `application`) » et sa table *Components* ne liste que `TemporalJournalTransport` et `TemporalApplicationTransport`. La fabrique en accepte quatre — son propre message d'erreur ligne 90 dit « expected journal, application, activity_worker, or nexus_worker » — et les deux manquants sont ceux que la documentation utilisateur fait écrire : `purpose: activity_worker` (`documentation/user/getting-started/_index.md:128`, `documentation/user/backends/_index.md:158`) et `purpose: nexus_worker` (`documentation/user/nexus/_index.md:185`).
- **Amont** : le code du paquet (`TemporalTransportFactory.php:70-90`) ; c'est la seule page d'accueil Packagist du satellite `durable-bridge-temporal`.
- **Correctif** : ajouter `activity_worker` et `nexus_worker` à la phrase d'invariant et les deux transports correspondants à la table *Components*.

### C3 — `src/Durable/README.md` renvoie à un ADR sans rapport et annonce une extension Psalm qui n'existe pas

- **Fichier** : `src/Durable/README.md:29`
- **Gravité** : majeur
- **Constat** : La ligne dit « Composer `suggest` lists optional PHPStan / **Psalm** extensions (see **DUR012** in the ADR index) ». Le `suggest` de `src/Durable/composer.json` ne cite qu'une extension, `gplanchat/durable-phpstan` ; **aucun paquet d'extension Psalm n'existe dans ce dépôt** — `SPLITS` n'en publie pas, et il n'y a pas de répertoire pour lui. (Psalm, lui, y est bien présent en tant qu'analyseur : `psalm.xml`, `psalm-baseline.xml`, `psalm-magento.xml`, et deux passages dans `.github/workflows/ci.yml`. C'est l'*extension* que la ligne annonce qui n'existe pas, pas l'outil.) Et DUR012 est *API client layer and repository adapters* — aucune mention de PHPStan ni de Psalm ; l'ADR qui fonde réellement l'extension est DUR038, celui que le `suggest` cite lui-même.
- **Amont** : `documentation/adr/DUR012-api-client-and-repository-adapter-layers.md` et `documentation/INDEX.md:31`.
- **Correctif** : remplacer la ligne par un renvoi à DUR038 et supprimer la mention Psalm — c'est le README du paquet phare, la première page que voit un lecteur de Packagist.

### C4 — Le tableau des paquets du README racine est en retard d'un paquet publié

- **Fichier** : `README.md:9-21`
- **Gravité** : majeur
- **Constat** : Le tableau liste huit paquets ; `gplanchat/durable-magento` (`src/DurableModule/`) n'y est pas, alors qu'il est *splitté et publié* (`bin/splitsh-publish.sh:39`), qu'il a son `composer.json`, son `LICENSE` et son README, et qu'il est documenté sur deux sections du guide (`documentation/user/packages/_index.md:20,288`). Même staleness plus bas : `README.md:71` ne renvoie qu'à 3 des 11 README de paquets, et `UPGRADE.md` n'est lié depuis nulle part dans le README racine. *(L'absence de `src/DurableDemoContracts/` est en revanche assumée et documentée par son propre README `:8-19` — pas un défaut.)*
- **Amont** : `bin/splitsh-publish.sh:39`, `documentation/user/packages/_index.md:288`.
- **Correctif** : ajouter la ligne `gplanchat/durable-magento` → `src/DurableModule/`, compléter la liste des README de paquets, et lier `UPGRADE.md` depuis la section *Documentation*.

### C5 — Quatre surfaces publiques sont en français sans contrepartie anglaise, dont trois pages Packagist

- **Fichier** : `UPGRADE.md:1`, `src/DurablePhpstan/README.md:1`, `src/DurableDemoContracts/README.md:1`, `src/DurableRector/README.md:34`
- **Gravité** : majeur
- **Constat** : Le guide utilisateur est délibérément bilingue (paires Hugo `_index.md` / `_index.fr.md`) — ce n'est pas le sujet. Le sujet est que ces quatre fichiers-là sont en français **sans version anglaise**, alors que les huit autres README de paquets, le README racine et la langue par défaut du site sont en anglais. Deux d'entre eux (`durable-phpstan`, `durable-rector`) sont la seule page d'accueil de satellites Packagist installables séparément, et `src/DurableRector/README.md` bascule de langue en son milieu (intro anglaise, section `## Monter de version à l'intérieur de Durable`).
- **Amont** : non sourcé côté convention amont — l'argument est interne : incohérence avec les huit README frères et avec la langue par défaut du site publié.
- **Correctif** : traduire ces quatre surfaces en anglais et, si le français doit être conservé, le faire sous la même mécanique `.fr.md` que le guide utilisateur.

### C6 — La contrainte Symfony du README du bundle contredit son propre `composer.json`

- **Fichier** : `src/DurableBundle/README.md:19` contre `src/DurableBundle/composer.json`
- **Gravité** : mineur
- **Constat** : Le README annonce « Symfony **6.4 || 7.4** ». Le `composer.json` du même répertoire contraint tous ses composants Symfony en `^6.4 || ^7.0 || ^8.0` — la 8.x est donc supportée et n'est pas annoncée, et « 7.4 » n'est pas une borne que la contrainte exprime. Le README du plugin, pour la même pile, écrit correctement « Symfony 6.4, 7.x or 8.x » (`src/DurablePlugin/README.md:64`) : les deux README du même dépôt se contredisent.
- **Amont** : `src/DurableBundle/composer.json` (source de vérité de l'installation).
- **Correctif** : aligner sur « Symfony 6.4, 7.x or 8.x », ou renvoyer au `composer.json` sans recopier la contrainte.

### C7 — Cinq des sept `->info()` du bundle sont en français, et c'est ce qu'imprime `config:dump-reference`

- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:20,22,23,38,42` (français) contre `:58,61` (anglais)
- **Gravité** : mineur
- **Constat** : Ces chaînes ne sont pas des commentaires : elles sont la documentation que Symfony rend à l'utilisateur via `bin/console config:dump-reference durable` et `debug:config`. Cinq sont en français, deux en anglais, dans le même arbre — un développeur qui déroule la référence lit deux langues.
- **Amont** : `symfony/symfony`, `src/Symfony/Bundle/FrameworkBundle/DependencyInjection/Configuration.php` — toutes les chaînes `->info()` du bundle de référence sont en anglais (vérifié sur la branche 7.4).
- **Correctif** : passer les cinq chaînes en anglais ; c'est aussi ce qui permettra de dériver la page de C1 automatiquement.

### C8 — Trous structurels dans les README de paquets, et un `composer.json` sans mots-clés

- **Fichier** : `src/Bridge/Dbal/README.md` (aucun `composer require`), `src/Durable/README.md`, `src/Bridge/Illuminate/README.md`, `src/DurableLaravel/README.md`, `src/DurableRector/README.md` (aucune section *Requirements*), `src/DurableDemoContracts/composer.json`
- **Gravité** : mineur
- **Constat** : `src/Bridge/Dbal/README.md` a *Requirements*, *Components*, *Configuration* et *Concurrency* mais ne dit jamais `composer require gplanchat/durable-bridge-dbal` — la seule commande d'installation manque au paquet le plus documenté. Symétriquement, quatre README ont la commande sans section *Requirements*. Côté métadonnées, les dix paquets publiés partagent une colonne vertébrale de mots-clés (`durable-execution`, `workflow`, `orchestration`, `long-running`) ; `gplanchat/durable-demo-contracts` est le seul sans aucun `keywords` — cohérent avec son non-publication, mais c'est la seule irrégularité de l'ensemble, qui est par ailleurs homogène.
- **Amont** : `symfony/symfony`, `src/Symfony/Component/Scheduler/README.md` — structure canonique d'un composant récent : titre, une phrase de description, puis *Resources* (Documentation / Contributing / Report issues). Les README Durable ont l'équivalent (encadré miroir + *Documentation* + *License*) ; ce sont l'installation et les prérequis qui manquent par endroits.
- **Correctif** : ajouter la ligne `composer require` au README DBAL et une section *Requirements* de trois lignes (PHP, dépendances majeures) aux quatre autres ; laisser `durable-demo-contracts` sans mots-clés est défendable puisqu'il n'est pas publié.
