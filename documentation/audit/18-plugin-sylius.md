# Conventions de plugin Sylius — conformité au squelette officiel, compatibilité Sylius 2

## Synthèse

Le paquet est un **bundle Symfony qui s'appelle « plugin »**, pas un plugin Sylius au sens du
`Sylius/PluginSkeleton` 2.0 : `type: symfony-bundle`, pas de `SyliusPluginTrait`, pas de `getPath()`,
arborescence `Resources/config` + `Resources/views` là où le squelette 2.0 et `Sylius/RefundPlugin`
posent `config/`, `templates/`, `translations/` à la racine. Il fonctionne — la CI rend réellement la
page contre MySQL — mais il est **non portable** sur deux points vérifiés : la route est écrite en dur
sous `/admin/` au lieu du préfixe `%sylius_admin.path_name%`, et le contrat Composer ne déclare aucune
dépendance Sylius alors que son unique gabarit étend le layout de `SyliusAdminBundle`. Rien n'est
traduisible : ni le libellé de menu, ni les 270 lignes du gabarit, et il n'y a pas de `translations/`.
Trois axes sont **sains** et méritent d'être dits : (a) l'isolation du backend annoncée (DUR037) tient,
aucun fichier du plugin ne mentionne `Bridge\`, `Temporal`, `Dbal` ni `grpc`, et le contrôleur ne
dépend que de `Gplanchat\Durable\Observation\RunDashboard` ; (b) rien de `SyliusResourceBundle` legacy
n'est utilisé — le plugin ne déclare ni ressource, ni grille, ni entité, donc l'absence de `Migrations/`
est correcte et non un manque ; (c) `{% extends '@SyliusAdmin/shared/layout/base.html.twig' %}` est
exactement ce que fait RefundPlugin pour ses pages autonomes — TwigHooks sert à *injecter* dans une page
existante, ce n'est pas une divergence ici. L'installation manuelle décrite au README (`bundles.php`
+ import de routes) est enfin ce que fait tout plugin sans recette Flex — le squelette 2.0 n'en livre
pas davantage, l'axe « fichiers de config d'installation » est donc sain lui aussi.

## Constats

### C1 — La route admin est écrite en dur sous `/admin/`, hors du préfixe configurable de Sylius

- **Fichier** : `src/DurablePlugin/Resources/config/routes.yaml:2` (`path: /admin/durable/dashboard`),
  importé sans préfixe par `sylius/config/routes/durable_plugin.yaml:2`
- **Gravité** : majeur
- **Constat** : Sylius rend le segment admin configurable
  (`sylius_admin.path_name: '%env(resolve:SYLIUS_ADMIN_ROUTING_PATH_NAME)%'`) et en dérive
  `sylius.security.admin_regex: "^/%sylius_admin.path_name%"`. Dès qu'une boutique renomme ce segment,
  `/admin/durable/dashboard` sort du pare-feu `admin`, n'est plus couvert par la règle
  `access_control` `sylius/config/packages/security.yaml:120`, et tombe dans le pare-feu boutique dont
  le motif est une négation du même paramètre. Ce n'est **pas** un trou d'authentification —
  `#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]` (`src/DurablePlugin/Controller/AdminDashboardController.php:23`)
  refuse toujours — mais le contexte de session, l'`entry_point` et la redirection de connexion
  deviennent ceux de la boutique.
- **Amont** : `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/config.yml:9-10` ;
  `sylius/vendor/sylius/refund-plugin/config/routes.yaml:1-3` — `prefix: '/%sylius_admin.path_name%'`
  (https://github.com/Sylius/RefundPlugin/blob/2.1/config/routes.yaml) ; le squelette 2.0 sépare de même
  `config/admin_routing.yaml` de `config/shop_routing.yaml`
  (https://github.com/Sylius/PluginSkeleton/tree/2.0/config).
- **Correctif** : passer le `path` à `/durable/dashboard` et fournir un `config/routes.yaml` de plugin
  qui importe le fichier admin avec `prefix: '/%sylius_admin.path_name%'`, comme RefundPlugin ; ne
  documenter dans le README que l'import de ce fichier-là.

### C2 — Contrat Composer : `type: symfony-bundle` et aucune dépendance Sylius, alors que la page en exige une

- **Fichier** : `src/DurablePlugin/composer.json:15` (`"type": "symfony-bundle"`) et `:33-35`
  (`sylius/admin-bundle` relégué en `suggest`)
- **Gravité** : majeur
- **Constat** : l'unique gabarit du paquet fait
  `{% extends '@SyliusAdmin/shared/layout/base.html.twig' %}`
  (`src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig:1`) : sans SyliusAdminBundle, la
  seule page livrée ne peut pas être rendue. La dépendance est donc dure et déclarée facultative, et
  le `type` classe le paquet hors de l'écosystème plugin sur Packagist, là où les deux références
  amont déclarent `sylius-plugin`.
- **Amont** : https://github.com/Sylius/PluginSkeleton/blob/2.0/composer.json — `"type": "sylius-plugin"`,
  `"sylius/sylius": "^2.0"` ; `sylius/vendor/sylius/refund-plugin/composer.json` — même `type`, même
  `require` `sylius/sylius: ^2.0`.
- **Correctif** : passer à `"type": "sylius-plugin"` et déclarer `"sylius/sylius": "^2.0"` (ou
  `sylius/admin-bundle: ^2.0` si l'on veut la dépendance minimale) en `require`.

### C3 — Aucune traduction : libellé de menu et gabarit en anglais littéral, pas de `translations/`

- **Fichier** : `src/DurablePlugin/EventListener/AdminMenuListener.php:37` (`'label' => 'Durable Dashboard'`) ;
  `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig` — 270 lignes, zéro `|trans`
- **Gravité** : majeur
- **Constat** : Sylius 2 pose systématiquement des clés de traduction sur les entrées de menu et les
  gabarits admin, et les plugins publiables embarquent un dossier `translations/`. Ici tout est en dur
  en anglais dans une admin qui, elle, est localisée — l'entrée « Durable Dashboard » reste en anglais
  dans une boutique en français, et aucun intégrateur ne peut la traduire sans surcharger le listener.
- **Amont** : `sylius/vendor/sylius/refund-plugin/src/Menu/AdminMainMenuListener.php:30`
  (`->setLabel('sylius_refund.ui.credit_memos')`) et
  `sylius/vendor/sylius/refund-plugin/translations/messages.{de,en,fr,nl,pl}.yml` ;
  `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Menu/MainMenuBuilder.php:56,66,76`
  (toutes les entrées natives sont des clés) ; le squelette 2.0 ships un dossier `translations/`
  (https://github.com/Sylius/PluginSkeleton/tree/2.0/translations).
- **Correctif** : remplacer le libellé par une clé (`gplanchat_durable.ui.dashboard`), passer les
  chaînes du gabarit au filtre `trans`, et livrer `translations/messages.en.yml` (+ `fr`) dans le
  paquet.

### C4 — Le listener de menu contourne le contrat Sylius par `object` + `method_exists`

- **Fichier** : `src/DurablePlugin/EventListener/AdminMenuListener.php:15-38`
- **Gravité** : mineur
- **Constat** : la méthode reçoit `object $event` et sonde `getMenu`/`addChild`/`getChild` par
  `method_exists` avant d'agir, ce qui rend toute erreur d'intégration silencieuse : si l'événement
  ou le menu change de forme, l'entrée disparaît sans le moindre signal. Sylius fournit un type
  concret. Le lien est de plus construit en `uri` via `UrlGeneratorInterface` (`:38`) au lieu de
  `'route' => …`, ce qui prive l'entrée du marquage « actif » que KnpMenu dérive de la route.
- **Amont** : `sylius/vendor/sylius/refund-plugin/src/Menu/AdminMainMenuListener.php:21-31` —
  `MenuBuilderEvent $event` typé (`Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent`) et
  `addChild('credit_memos', ['route' => 'sylius_refund_admin_credit_memo_index'])` ;
  `…/AdminBundle/Menu/MainMenuBuilder.php:24` définit `EVENT_NAME = 'sylius.menu.admin.main'`
  (le nom d'événement utilisé ici est, lui, correct).
- **Correctif** : typer `MenuBuilderEvent $event`, supprimer les gardes `method_exists`, et déclarer
  l'entrée avec `['route' => 'gplanchat_durable_plugin_admin_dashboard']`.

### C5 — Classe de plugin sans `SyliusPluginTrait` ni `getPath()`, arborescence `Resources/` héritée de Sylius 1

- **Fichier** : `src/DurablePlugin/DurablePlugin.php:9` (`final class DurablePlugin extends Bundle {}`)
- **Gravité** : mineur
- **Constat** : le squelette 2.0 et RefundPlugin déclarent `use SyliusPluginTrait;` et
  `getPath(): string { return \dirname(__DIR__); }`, ce qui autorise l'arborescence de plugin
  (`config/`, `templates/`, `translations/` à la racine, code dans `src/`). Ici l'autoload PSR-4 est
  ancré sur `./` et les gabarits vivent en `Resources/views` — le chemin `Resources/views` reste
  résolu par TwigBundle (`symfony/twig-bundle/DependencyInjection/TwigExtension.php:232`), donc
  **rien ne casse** ; c'est une divergence de convention, pas un défaut fonctionnel. Le trait n'a
  d'ailleurs aucun effet de bord d'enregistrement : il ne surcharge que la dérivation du nom
  d'extension.
- **Amont** : https://github.com/Sylius/PluginSkeleton/blob/2.0/src/AcmeSyliusExamplePlugin.php ;
  `sylius/vendor/sylius/refund-plugin/src/SyliusRefundPlugin.php:22-28` ;
  `…/CoreBundle/Application/SyliusPluginTrait.php` (dérivation `preg_replace('/Plugin$/', …)`).
- **Correctif** : le trait ne peut **pas** être adopté tel quel — il exigerait
  `DurableExtension`/alias `durable`, déjà pris par
  `src/DurableBundle/DependencyInjection/DurableExtension.php:81`. Renommer d'abord la classe de
  plugin (p. ex. `GplanchatDurableSyliusPlugin` → alias `gplanchat_durable_sylius`), puis adopter
  trait + `getPath()` et déplacer `Resources/config` → `config/`, `Resources/views` → `templates/`.

### C6 — Les classes de test du split ne respectent pas la racine PSR-4 qu'il déclare

- **Fichier** : `src/DurablePlugin/tests/Unit/AdminMenuListenerTest.php:5`
  (`namespace Gplanchat\Durable\Plugin\Tests\Unit;`) face à
  `src/DurablePlugin/composer.json:36-40` (`"Gplanchat\\Durable\\Plugin\\": "./"`)
- **Gravité** : mineur
- **Constat** : vérifié par `composer dump-autoload --optimize --strict-psr`, qui écarte les quatre
  classes (`… does not comply with psr-4 autoloading standard … Skipping.`). Elles ne sont chargées
  que parce que PHPUnit inclut les fichiers par chemin et que `tests/bootstrap.php` ajoute son propre
  `spl_autoload_register`. Le paquet publié n'a ni `autoload-dev`, ni namespace de test déclaré, et
  sa racine PSR-4 `./` balaie aussi `tests/`.
- **Amont** : https://github.com/Sylius/PluginSkeleton/blob/2.0/composer.json —
  `"Tests\\Acme\\SyliusExamplePlugin\\": "tests/"` dans `autoload` ; idem
  `"Tests\\Sylius\\RefundPlugin\\": "tests/"` dans `sylius/vendor/sylius/refund-plugin/composer.json`.
- **Correctif** : ancrer le code en `src/` et déclarer
  `"autoload-dev": {"psr-4": {"Gplanchat\\Durable\\Plugin\\Tests\\": "tests/"}}`, ce qui rend
  `tests/bootstrap.php` inutile.

### C7 — Le split publié ne porte ni CI, ni `UPGRADE.md`, ni outillage qualité

- **Fichier** : `src/DurablePlugin/` (racine — pas de `.github/`, pas d'`UPGRADE.md`, pas d'`ecs.php`,
  pas de `phpstan.neon`) ; `bin/splitsh-publish.sh:28` publie ce dossier tel quel
- **Gravité** : remarque
- **Constat** : le squelette 2.0 livre `.github/workflows/build.yml`, `ecs.php`, `phpstan.neon`,
  `behat.yml.dist`, `tests/Application/Kernel.php` ; RefundPlugin y ajoute `UPGRADE-2.0.md` et
  `UPGRADE-2.1.md`. Le dépôt publié `gplanchat/durable-plugin` n'exécute donc rien de son propre
  `phpunit.xml`. C'est **largement atténué** par le monorepo, qui lance la suite `plugin`
  (`phpunit.xml:31-33`) et rend réellement la page contre MySQL 8.4 dans le job « Boutique Sylius »
  (`.github/workflows/ci.yml:174` et `:216-220`) ; le README du split l'assume explicitement
  (`src/DurablePlugin/README.md:5-12`) — mais l'`UPGRADE.md` racine du dépôt n'est, lui, pas versé
  dans le split.
- **Amont** : https://github.com/Sylius/PluginSkeleton/tree/2.0/.github/workflows (`build.yml`) ;
  `sylius/vendor/sylius/refund-plugin/UPGRADE-2.0.md`, `UPGRADE-2.1.md`, `ecs.php`, `phpstan.neon`.
- **Correctif** : ajouter au split un workflow minimal qui joue son `phpunit.xml`, et y verser la
  section plugin de l'`UPGRADE.md` racine (au minimum un `UPGRADE.md` de renvoi).

### C8 — Extension DI sans `Configuration`

- **Fichier** : `src/DurablePlugin/DependencyInjection/DurablePluginExtension.php:12-21`
- **Gravité** : remarque
- **Constat** : l'extension charge `services.php` sans jamais traiter `$configs`, et le paquet ne
  fournit pas de `Configuration`. Une clé `durable_plugin:` déposée par un intégrateur est ignorée
  sans erreur au lieu d'être refusée. Le nom et l'alias sont, eux, corrects au sens Symfony
  (`DurablePlugin` → `DurablePluginExtension` → alias `durable_plugin`).
- **Amont** : https://github.com/Sylius/PluginSkeleton/tree/2.0/src/DependencyInjection —
  `AcmeSyliusExampleExtension.php` **et** `Configuration.php`.
- **Correctif** : ajouter une `Configuration` minimale et l'appliquer via
  `$this->processConfiguration($this->getConfiguration($configs, $container), $configs)`, ne serait-ce
  que pour rejeter une configuration inconnue.
