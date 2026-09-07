# Back-office Sylius 2 — layout, grilles de ressources, menu, Twig, accessibilité

## Synthèse
Le plugin est un bundle Symfony correct et une page Sylius approximative : il étend bien
`@SyliusAdmin/shared/layout/base.html.twig`, mais reconstruit à la main le châssis d'admin
(sidebar, navbar, `page-wrapper`) que Sylius 2 compose déjà via le hook `sylius_admin.common.index`,
et n'ouvre donc aucun point d'extension à l'aval. Aucune chaîne visible ne passe par `trans` — zéro
occurrence de `|trans` dans le gabarit, libellé de menu compris — dans un back-office que Sylius
livre traduit dans une trentaine de locales. La liste des exécutions est rendue à la main alors que
`sylius/grid-bundle` documente un `DataProviderInterface` explicitement prévu pour une source non
Doctrine ; le refus peut se défendre (pagination à curseur contre `Pagerfanta`), mais il n'est
argumenté nulle part. Le chemin de route code `/admin` en dur au lieu de `%sylius_admin.path_name%`,
ce qui sort la page du pare-feu admin dès qu'une application renomme son préfixe. Deux axes sont
sains et ne donnent pas lieu à constat : l'**échappement** (autoescape Twig par défaut, aucun `|raw`
dans tout le paquet, `renderedDetails` normalisé en amont) et l'**état vide**, traité aux trois
niveaux — liste, historique, absence de sélection.

## Constats

### C1 — Le chemin de la route code `/admin` en dur au lieu de `%sylius_admin.path_name%`
- **Fichier** : `src/DurablePlugin/Resources/config/routes.yaml:2`
- **Gravité** : majeur
- **Constat** : `path: /admin/durable/dashboard` fige le préfixe d'admin. Sylius le rend
  configurable par `SYLIUS_ADMIN_ROUTING_PATH_NAME`, et en dérive `sylius.security.admin_regex`, qui
  sert à la fois de `pattern` du pare-feu `admin` (`sylius/config/packages/security.yaml:19`) et de
  règle `access_control` mappant `ROLE_ADMINISTRATION_ACCESS` (`security.yaml:120`). Dès que ce
  préfixe n'est plus `admin`, la page tombe hors des deux, et `#[IsGranted]`
  (`src/DurablePlugin/Controller/AdminDashboardController.php:23`) reste le seul garde-fou.
- **Amont** : `sylius/vendor/sylius/refund-plugin/config/routes.yaml:1-3` — un plugin officiel
  importe ses routes admin avec `prefix: '/%sylius_admin.path_name%'` et écrit ses `path` sans
  `/admin` ; paramètre défini dans
  `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/config.yml:9-10`.
- **Correctif** : scinder en `routes.yaml` (import avec `prefix: '/%sylius_admin.path_name%'`) et
  `routes/admin.yaml` dont le `path` devient `/durable/dashboard`, et corriger le README en
  conséquence.

### C2 — Aucune chaîne visible ne passe par `trans`, libellé de menu compris
- **Fichier** : `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig:69-70`
  (également `:90`, `:92-99`, `:115-131`, `:139`, `:142`, `:172`, `:175-177`, `:180`, `:218`,
  `:222`, `:260`) et `src/DurablePlugin/EventListener/AdminMenuListener.php:37`
- **Gravité** : majeur
- **Constat** : le gabarit ne contient aucune occurrence de `|trans` (vérifié : les deux
  correspondances de « trans » sont `text-transform` et un mot de commentaire), et aucun
  `Resources/translations/` n'existe dans le paquet. Le libellé de menu `'Durable Dashboard'` est
  passé tel quel : KnpMenu le traverse par `|trans(..., 'messages')`, il retombe donc sur lui-même
  et s'affiche en anglais dans un back-office français.
- **Amont** :
  `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/templates/dashboard/index.html.twig:4`
  (`{{ 'sylius.ui.dashboard'|trans }}`) ;
  `.../AdminBundle/Menu/MainMenuBuilder.php:56-57` (`setLabel('sylius.ui.dashboard')`) ;
  `sylius/vendor/knplabs/knp-menu-bundle/templates/menu.html.twig:3-9` (bloc `label` → `|trans`).
- **Correctif** : livrer `Resources/translations/messages.en.yaml` sous un préfixe
  `gplanchat_durable.ui.*`, remplacer chaque littéral du gabarit par sa clé + `|trans` et le libellé
  du menu par `setLabel('gplanchat_durable.ui.dashboard')`.

### C3 — Le châssis d'admin est reconstruit à la main au lieu d'être composé par le hook Sylius 2
- **Fichier** : `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig:61-66`
- **Gravité** : majeur
- **Constat** : le bloc `body` fait `{% include %}` de `sidebar.html.twig` et `navbar.html.twig`
  puis réécrit `<div class="page-wrapper">` à la main. Sylius 2 compose exactement ces trois
  éléments (sidebar/navbar/content) par le hook `sylius_admin.common.index`. La page perd du même
  coup le sous-arbre `…index.content` — flashes, en-tête, fil d'Ariane — et n'offre aucun point
  d'extension aux applications qui l'installent. L'argument « éviter un couplage à Sylius » ne tient
  pas : le gabarit étend déjà `@SyliusAdmin/...` et inclut deux de ses partiels.
- **Amont** :
  `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/twig_hooks/common/index.yaml:3-12`
  ; `.../AdminBundle/templates/dashboard/index.html.twig:7-9` ;
  `.../AdminBundle/templates/shared/crud/common/content.html.twig:1`.
- **Correctif** : remplacer le corps par
  `{% hook ['gplanchat_durable.dashboard.index', 'sylius_admin.common.index'] %}` et déclarer les
  panneaux (santé, compteurs, filtre, liste, détail) comme gabarits sous le préfixe du plugin dans
  un `twig_hooks` livré par l'extension. Attention : `content.html.twig` appelle un
  `{% hook 'content' %}` **relatif**, la page hériterait donc du slot `grid`
  (`twig_hooks/common/index.yaml:21-22`) dont le `data_table` lit
  `hookable_metadata.context.resources` — il faut le désactiver explicitement (`enabled: false`)
  ou ne composer que `sidebar`/`navbar`/`content` sous le seul préfixe du plugin.

### C4 — La liste est rendue à la main sans que le refus de `sylius_grid` soit écrit nulle part
- **Fichier** : `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig:136-168`
- **Gravité** : majeur
- **Constat** : la liste, le filtre (`:113-132`) et la pagination sont écrits à la main. Le coût est
  mesurable : aucune colonne triable, un seul filtre à une seule dimension, et une pagination
  **unidirectionnelle** — la ligne `:163` n'émet que `pagination.nextCursor`, il n'existe aucun lien
  « page précédente », donc un exploitant qui a cliqué deux fois ne peut plus revenir. Le contre-poids
  est réel — l'exemple amont construit `new Pagerfanta(new FixedAdapter($count, $data))` et un
  catalogue à curseur n'a pas de total — mais il n'est argumenté ni dans l'ADR DUR049 ni dans
  `openspec/changes/dashboard-presentation/proposal.md`, qui se contente de « Sylius on Tabler
  cards » sans jamais peser `sylius_grid`.
- **Amont** : `sylius/vendor/sylius/grid-bundle/src/Component/Data/DataProviderInterface.php` et
  <https://stack.sylius.com/grid/index/your_first_grid> (« a traditional repository (like Doctrine),
  an external API, an in-memory data structure, or even a query bus ») ;
  `sylius/vendor/sylius/refund-plugin/config/routes/admin.yaml` (`grid: sylius_refund_credit_memo`,
  `templates: "@SyliusAdmin\shared\crud"`).
- **Correctif** : soit un `DataProviderInterface` adossé au catalogue avec une fenêtre bornée
  (comme le fait déjà le module Magento), soit — à défaut — une section « Alternatives rejetées »
  dans DUR049 nommant `sylius_grid` et la friction curseur/`Pagerfanta`. Dans les deux cas, ajouter
  au minimum un lien « page précédente ».

### C5 — L'entrée de menu est déclarée par `uri` : elle n'est jamais marquée active
- **Fichier** : `src/DurablePlugin/EventListener/AdminMenuListener.php:36-41`
- **Gravité** : mineur
- **Constat** : l'item est construit avec `'uri' => $this->urlGenerator->generate(...)`. Les deux
  seuls voteurs enregistrés par KnpMenuBundle sont `knp_menu.voter.callback` et
  `knp_menu.voter.router` ; aucun ne compare une URI. L'entrée ne reçoit donc jamais `currentClass`,
  et la section « Configuration » ne s'ouvre pas quand on est sur la page. L'icône
  `tabler:clock` (`:40`) est par ailleurs inerte — le bloc `child_item` du menu latéral ne rend pas
  d'icône pour un enfant de sous-menu — mais c'est aussi ce que fait le plugin officiel, ce n'est
  donc pas un écart de convention.
- **Amont** : `sylius/vendor/knplabs/knp-menu-bundle/config/menu.php:97-103` ;
  `sylius/vendor/sylius/refund-plugin/src/Menu/AdminMainMenuListener.php:27-29`
  (`->addChild('credit_memos', ['route' => 'sylius_refund_admin_credit_memo_index'])`) ;
  `.../AdminBundle/templates/shared/crud/common/sidebar/menu/menu.html.twig:82-101`.
- **Correctif** : remplacer `'uri' => …` par
  `'route' => 'gplanchat_durable_plugin_admin_dashboard'` et retirer la dépendance à
  `UrlGeneratorInterface`.

### C6 — Le listener dédouble un événement dont le paquet dépend déjà
- **Fichier** : `src/DurablePlugin/EventListener/AdminMenuListener.php:15-33`
- **Gravité** : mineur
- **Constat** : la méthode reçoit `object` et enchaîne cinq `method_exists` avant d'agir, alors que
  `composer.json:20` requiert déjà `knplabs/knp-menu: ^3.5`. Le résultat n'est pas seulement verbeux :
  toute évolution de l'API amont fait disparaître l'entrée de menu **en silence**, sans erreur ni log.
  Le repli `$configurationMenu = $menu` (`:28`) accroche en outre la page à la racine du menu si le
  nœud `configuration` change de nom, ce qui déplace l'entrée sans le dire.
- **Amont** : `sylius/vendor/sylius/refund-plugin/src/Menu/AdminMainMenuListener.php:21`
  (`public function addCreditMemosSection(MenuBuilderEvent $event): void`) ;
  `.../AdminBundle/Menu/MainMenuBuilder.php:24` (`EVENT_NAME = 'sylius.menu.admin.main'`).
- **Correctif** : typer `MenuBuilderEvent` (`sylius/ui-bundle`, à passer de `suggest` à `require`),
  et supprimer les gardes ; le listener n'est de toute façon enregistré que si le bundle est chargé.

### C7 — Le test qui garde le layout ne rend pas le layout
- **Fichier** : `src/DurablePlugin/tests/Integration/TheDashboardRendersARunHistoryTest.php:151-153`
  et `src/DurablePlugin/tests/Integration/DashboardTemplateRenderTest.php:46-49`
- **Gravité** : mineur
- **Constat** : le test de rendu remplace `base.html.twig`, `sidebar.html.twig` et
  `navbar.html.twig` par des chaînes vides ; la page est donc éprouvée dans un châssis fictif, et
  rien ne détecterait un bloc renommé en amont. En regard, `DashboardTemplateRenderTest` garde le
  layout par un `assertStringContainsString` sur le nom du fichier : il verrouille une chaîne de
  caractères, pas un rendu — et casserait sur le correctif C3 sans que la page ait régressé. Aucun
  test ne couvre le volume : le catalogue de test rend une exécution et trois événements pour un
  `PAGE_SIZE` de 20.
- **Amont** : non sourcé (convention de test interne, pas une règle amont).
- **Correctif** : remplacer l'assertion sur la chaîne du layout par une assertion sur le rendu
  (présence des points d'ancrage attendus), et ajouter un cas à 20 exécutions × N événements pour
  fixer le comportement de la frise et de la pagination sous charge.

### C8 — La frise porte son sens dans la couleur et la position seules
- **Fichier** : `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig:190-206`
- **Gravité** : mineur
- **Constat** : chaque segment et chaque repère est un `<span>` vide dont la seule information est
  un `title` — un attribut qui n'est ni exposé de façon fiable aux lecteurs d'écran sur un élément
  non focalisable, ni atteignable au clavier. Un utilisateur qui n'accède pas à la couleur ne
  distingue ni une file d'un travail, ni un échec d'un succès. Les couleurs sont par ailleurs
  écrites en hexadécimal en dur (`:36-42`, `:46`, `:49-50`) alors que le reste du fichier utilise
  les jetons Tabler (`var(--tblr-border-color)`, `var(--tblr-primary)` — `:9-13`), ce qui la met
  hors du thème.
- **Amont** : WCAG 2.2 — <https://www.w3.org/TR/WCAG22/#non-text-content> et
  <https://www.w3.org/TR/WCAG22/#use-of-color> ;
  `.../AdminBundle/templates/shared/crud/common/sidebar.html.twig:1` (`data-bs-theme="dark"`),
  qui montre que le thème pilote bien les jetons.
- **Correctif** : doubler chaque segment d'un texte lisible (`<span class="visually-hidden">` avec
  la même phrase que le `title`), et remplacer les hexadécimaux par `var(--tblr-red)`,
  `var(--tblr-primary)`, `var(--tblr-orange)`.
