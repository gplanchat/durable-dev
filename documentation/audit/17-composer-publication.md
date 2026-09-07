# Composer et Packagist — métadonnées, contraintes, publication depuis un monorepo

## Synthèse
Les onze manifestes sont d'une hygiène rare : `license`, `description`, `authors`, `keywords`,
`type` et `autoload` sont présents partout, aucun `@dev` ni `dev-main` ne fuit dans un paquet
publiable (les `@dev` restent dans la racine et les quatre bancs d'essai), les contraintes tierces
sont toutes en `^` avec des unions explicites (`^6.4 || ^7.0 || ^8.0`), `psr/cache` est réellement
consommé (`ActivityContractResolver`, `NexusContractResolver`), et le `branch-alias`
`dev-main => 0.1.x-dev` est cohérent avec les tags `v0.1.0-alphaN` (`0.1.x-dev` se normalise
au-dessus d'`alpha10` et sous `0.2`, donc `^0.1.0-alphaN` l'attrape). Les trois défauts qui restent
sont tous des **déclarations manquantes**, pas des erreurs de contenu : un pont qui revendique
`Temporal\Api\` sans déclarer de `conflict` avec le SDK qu'il remplace, `self.version` partout entre
paquets sœurs — ce qui rend la ligne alpha publiée inaccessible sans toucher au `minimum-stability`
du consommateur —, et aucun `.gitattributes` d'export-ignore sur les dix préfixes découpés.
Aucun `composer validate` ne tourne nulle part dans la CI.

## Constats

### C1 — `Gplanchat\Bridge\Temporal` revendique `Temporal\Api\` sans `conflict` avec `temporal/sdk`
- **Fichier** : `src/Bridge/Temporal/composer.json:31` (et `:32` pour `GPBMetadata\Temporal\`) ; pas de section `conflict` dans le fichier
- **Gravité** : majeur
- **Constat** : le paquet mappe en PSR-4 deux racines d'un espace de noms qu'il ne possède pas.
  `temporal/sdk` dépend en dur de `roadrunner-php/roadrunner-api-dto`, dont l'autoload est
  `{"Temporal\\": "generated/Temporal", "GPBMetadata\\": "generated/GPBMetadata"}` : les deux jeux de
  stubs protobuf de l'API Temporal cohabitent alors dans le même graphe. PSR-4 résout par préfixe le
  plus long, donc `Temporal\Api\` (Durable) écrase `Temporal\` (SDK) et `GPBMetadata\Temporal\`
  écrase `GPBMetadata\` — silencieusement, avec deux générations de `.proto` différentes enregistrées
  dans le `DescriptorPool` global de `google/protobuf`. C'est exactement la population que
  `gplanchat/durable-rector` vise : `src/DurableRector/README.md:87` reconnaît le problème et s'en
  remet à une consigne humaine (« `composer remove temporal/sdk` is the honest forcing function »).
- **Amont** : `https://raw.githubusercontent.com/temporalio/sdk-php/master/composer.json` (autoload
  `Temporal\\` → `src`, require `roadrunner-php/roadrunner-api-dto`) et
  `https://repo.packagist.org/p2/roadrunner-php/roadrunner-api-dto.json` (autoload `Temporal\\`,
  `GPBMetadata\\`). Convention amont pour l'exprimer :
  `https://raw.githubusercontent.com/symfony/symfony/7.3/src/Symfony/Bundle/FrameworkBundle/composer.json`,
  dont le bloc `conflict` déclare une cinquantaine de bornes plutôt que de compter sur la
  documentation.
- **Correctif** : ajouter `"conflict": {"temporal/sdk": "*", "roadrunner-php/roadrunner-api-dto": "*"}`
  dans `src/Bridge/Temporal/composer.json` — le solveur refuse alors l'installation croisée au lieu de
  la laisser produire un écrasement d'autoload à l'exécution.

### C2 — `self.version` entre paquets sœurs rend la ligne alpha publiée non installable
- **Fichier** : `src/DurableBundle/composer.json:18`, `src/DurablePlugin/composer.json:18-19`, `src/Bridge/Dbal/composer.json:17`, `src/Bridge/Temporal/composer.json:19`, `src/Bridge/Illuminate/composer.json:18`, `src/DurableLaravel/composer.json:17-18`, `src/DurablePhpstan/composer.json:17`, `src/DurableRector/composer.json:18`, `src/DurableModule/composer.json:18`
- **Gravité** : majeur
- **Constat** : `self.version` produit une contrainte **exacte** vers une version **instable**
  (`0.1.0-alpha10`). Or `minimum-stability` est root-only et les drapeaux de stabilité ne s'appliquent
  qu'aux `require` de la racine : `composer require gplanchat/durable-bundle:^0.1@alpha` marque
  `durable-bundle` comme alpha mais laisse `gplanchat/durable` filtré par le `stable` par défaut, et la
  résolution échoue. Le consommateur doit exécuter `composer config minimum-stability alpha` — une
  étape qu'aucun autre paquet alpha de l'écosystème n'exige. Effet de bord durable : après la 1.0,
  `self.version` interdit à jamais de mélanger un `durable-bundle` 1.0.3 avec un `durable` 1.0.5.
- **Amont** : `https://getcomposer.org/doc/04-schema.md` — `minimum-stability (root-only)`, et
  `self.version` n'y est documenté que pour `replace` (« You should then typically only replace using
  `self.version` »), jamais pour `require`. Contre-exemple amont : Symfony découpe en lockstep comme
  ici mais requiert ses composants en `^7.3` / `^6.4|^7.0`
  (`src/Symfony/Bundle/FrameworkBundle/composer.json`), jamais en `self.version`.
- **Correctif** : remplacer chaque `self.version` par une plage sur la ligne courante
  (`"gplanchat/durable": "^0.1.0-alpha10"`, puis `^1.0` après la stable) et laisser un
  `conflict` pour les bornes basses réellement incompatibles.

### C3 — `gplanchat/durable-magento` est publié mais absent du graphe racine, et rien ne valide les manifestes
- **Fichier** : `bin/splitsh-publish.sh:39` (préfixe `src/DurableModule/` → satellite `durable-magento`) contre `composer.json:11-48` (neuf `repositories`, `src/DurableModule` absent) et `composer.json:49-58` (sept `require`, `gplanchat/durable-magento` absent)
- **Gravité** : majeur
- **Constat** : le manifeste du module Magento n'est jamais résolu par le `composer install` de la
  racine ; seul le banc `magento/composer.json` le tire en dépôt `path`, et uniquement dans les deux
  jobs Magento de la CI. Aucune commande `composer validate` n'existe dans `.github/workflows/`
  (`grep -rn "composer validate" .github/workflows/` → rien) : les onze manifestes partent chez
  Packagist sans qu'une seule exécution ait vérifié leur schéma, leur `license` SPDX ou leurs
  contraintes. Le même trou couvre `src/DurableDemoContracts/composer.json`, absent lui aussi de la
  racine — ici sans conséquence puisqu'il n'est pas dans `SPLITS`.
- **Amont** : `https://getcomposer.org/doc/03-cli.md#validate` — `composer validate` est la
  vérification que Composer prescrit avant publication ; Symfony l'exécute par composant dans sa CI.
- **Correctif** : ajouter une étape CI qui boucle
  `composer validate --strict --no-check-lock <chemin>/composer.json` sur les dix préfixes de `SPLITS`,
  et faire dériver cette liste et celle des `repositories` de la racine d'une source unique.

### C4 — Aucun `.gitattributes` d'export-ignore sur les dix préfixes découpés
- **Fichier** : aucun `.gitattributes` sous `src/` (`find . -name .gitattributes` ne renvoie que `laravel/.gitattributes`, un banc d'essai) ; conséquences visibles : `src/Bridge/Temporal/Spike/NativeExecutionSpike.php:36` et `src/DurablePlugin/phpunit.xml`
- **Gravité** : mineur
- **Constat** : les paquets sont assez propres pour que l'impact reste faible aujourd'hui — aucun
  répertoire `Tests/`, seulement `LICENSE`, `README.md` et le manifeste en non-PHP. Mais deux choses
  passent quand même : `phpunit.xml` du plugin, et surtout `Gplanchat\Bridge\Temporal\Spike\NativeExecutionSpike`,
  une classe de spike (DUR024) référencée nulle part dans le dépôt, qui entre dans la surface publique
  du paquet publié et devient donc engageante en BC. Sans garde-fou, le premier `Tests/` posé à côté
  des sources partira aussi dans le zipball Packagist.
- **Amont** : `https://raw.githubusercontent.com/symfony/symfony/7.3/src/Symfony/Component/Messenger/.gitattributes` — chaque split Symfony porte `/Tests export-ignore`, `/phpunit.xml.dist export-ignore`, `/.git* export-ignore`.
- **Correctif** : déposer un `.gitattributes` par préfixe de `SPLITS` avec `/.git* export-ignore`,
  `/phpunit.xml* export-ignore`, `/Tests export-ignore` ; sortir `Spike/` du paquet ou le déplacer hors
  du préfixe découpé.

### C5 — Les helpers de test livrés étendent PHPUnit sans borne déclarée
- **Fichier** : `src/Durable/composer.json:21` (`require-dev: phpunit/phpunit ^11.0`) et `:24` (`suggest`), pour `src/Durable/Testing/DurableTestCase.php` et six `*ConformanceTestCase`
- **Gravité** : mineur
- **Constat** : le couple `require-dev` + `suggest` est le bon idiome, et c'est celui de Symfony pour
  ses répertoires `Test/` livrés — ce n'est donc pas un piège en soi. Ce qui manque, c'est la borne :
  aucun `conflict` ne dit jusqu'où va la compatibilité. Un consommateur sous PHPUnit 12 ou 13 installe
  `gplanchat/durable` sans le moindre avertissement, puis découvre à l'exécution que
  `DurableTestCase extends PHPUnit\Framework\TestCase` ne tient plus. Les versions annoncées divergent
  d'ailleurs dans le monorepo : `^11.0` pour le cœur, `^11.5` pour le plugin et le module Magento.
- **Amont** : `https://raw.githubusercontent.com/symfony/symfony/7.3/src/Symfony/Component/Messenger/composer.json` — le bloc `conflict` est la façon dont Symfony matérialise ce genre de borne plutôt que de la laisser implicite.
- **Correctif** : ajouter `"conflict": {"phpunit/phpunit": "<11.0 || >=13.0"}` au cœur (et aligner les
  `require-dev` sur une seule contrainte dans tout le monorepo).

### C6 — `support` absent des onze manifestes, ce qui contredit l'avertissement des README
- **Fichier** : aucun `"support"` ni `"homepage"` dans `src/*/composer.json` ni `src/Bridge/*/composer.json` (présent uniquement à la racine, `composer.json:5-8`)
- **Gravité** : mineur
- **Constat** : chaque README de paquet affirme « Issues and pull requests are disabled here — open
  them on the monorepo » (`src/Durable/README.md:5-9`). Mais Packagist, faute de bloc `support`,
  construit ses liens « Issues » et « Source » à partir du dépôt VCS soumis, c'est-à-dire du satellite
  en lecture seule. Le lecteur qui arrive par Packagist plutôt que par le README atterrit sur un
  tracker fermé.
- **Amont** : `https://getcomposer.org/doc/04-schema.md` — section `support` (`issues`, `source`) ;
  les splits Symfony portent tous `"homepage": "https://symfony.com"` pour la même raison.
- **Correctif** : ajouter à chaque manifeste publiable
  `"support": {"issues": "https://github.com/gplanchat/durable-dev/issues", "source": "https://github.com/gplanchat/durable-dev"}`.

### C7 — La racine fige `"version": "0.1.0"`, au-dessus de tout tag publié
- **Fichier** : `composer.json:10`
- **Gravité** : remarque
- **Constat** : Composer déconseille le champ `version` hors nécessité, et la valeur choisie est
  supérieure à toute la ligne réellement publiée (`v0.1.0-alpha1` … `v0.1.0-alpha10`) : la racine se
  déclare plus avancée que ses propres satellites. L'impact reste nul tant que
  `gplanchat/durable-monorepo` n'est pas publié — les dépôts `path` dérivent bien `dev-main` de git,
  comme le confirme `composer.lock` — mais c'est une divergence qui n'attend qu'un `self.version` ou
  un `replace` pour devenir fausse.
- **Amont** : `https://getcomposer.org/doc/04-schema.md` — « The version of the package. In most cases
  this is not required and should be omitted. »
- **Correctif** : supprimer la ligne 10.

### C8 — L'autoload de la racine duplique celui des paquets et masque une régression d'autoload
- **Fichier** : `composer.json:74-84` (onze préfixes PSR-4 pointant sur `src/…`), alors que `composer.json:11-48` installe déjà les mêmes paquets par dépôt `path` (`vendor/gplanchat/durable -> ../../src/Durable/`)
- **Gravité** : remarque
- **Constat** : chaque espace de noms est enregistré deux fois, par la racine et par le manifeste du
  paquet symlinké. Les deux pointent sur les mêmes fichiers, donc rien ne casse — mais la suite de
  tests du monorepo resterait verte si l'`autoload` d'un paquet publiable était cassé ou supprimé,
  puisque la racine le recouvre. Le seul filet aujourd'hui est la CI Symfony/Laravel/Sylius, qui
  consomme les paquets par `path` depuis un autre répertoire racine. (Observation locale annexe :
  `vendor/gplanchat/gplanchat` est un lien symbolique absolu sur lui-même — résidu d'installation,
  `vendor/` n'étant pas versionné.)
- **Amont** : non sourcé (Composer ne l'interdit pas ; `symfony/symfony` évite la question en
  déclarant `replace` plutôt qu'un autoload racine dupliqué).
- **Correctif** : réduire l'`autoload` racine à ce que les dépôts `path` ne couvrent pas
  (`Gplanchat\DurableModule\`, `Gplanchat\Durable\Demo\Contracts\`) et laisser les autres préfixes aux
  manifestes des paquets.
