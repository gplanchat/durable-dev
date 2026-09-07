# Routing, HTTP, HttpKernel — routes, listeners kernel, sémantique des réponses

## Synthèse

Le périmètre HTTP est petit — une route de plugin, un listener kernel, cinq routes de bancs
d'essai — et trois des cinq questions posées s'y répondent par « sain » : les `requirements` ne
manquent nulle part de façon dangereuse (le seul paramètre, `{id}` en
`symfony/src/Controller/SamplesWorkflowController.php:33`, retombe sur le `[^/]+` par défaut de
Symfony et `SampleWorkflowCatalog::findById()` répond 404 sur inconnu) ; le seul listener kernel
du dépôt filtre bien `isMainRequest()` et se place à 1024, au-dessus de tous les écouteurs
`kernel.request` du cœur (le plus haut, `ValidateRequestListener`, est à 256) ; et le plugin
n'impose aucune route inconditionnelle — `routes.yaml` n'est chargé que si l'application l'importe
(`sylius/config/routes/durable_plugin.yaml`), `sylius/admin-bundle` reste en `suggest` tandis que
`symfony/security-bundle` est une dépendance dure, donc `#[IsGranted]` ne peut pas devenir un
no-op silencieux. Le recensement des listeners est clos : deux tags seulement dans tout `src/`,
dont un seul sur un événement kernel.

Les deux questions restantes tombent mal. Le cycle de vie de la trace d'exécution est borné par la
requête HTTP **et par elle seule**, alors que le produit tourne principalement dans un
`messenger:consume` sans requête : l'état fuit d'un message à l'autre, sans borne. Et la route
admin du plugin fige `/admin/` là où tout Sylius — cœur et plugins amont — passe par
`%sylius_admin.path_name%`, ce qui décroche la page du pare-feu admin dès que ce nom change.

## Constats

### C1 — La trace d'exécution n'est remise à zéro que par une requête HTTP, jamais dans le worker

- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:744` (et `:479`, `:550`, `:716`) ; `src/DurableBundle/EventListener/ResetDurableProfilerListener.php:24`
- **Gravité** : bloquant
- **Constat** : `durable.execution_trace` est enregistré sans tag `kernel.reset`, et son unique
  remise à zéro est le listener `KernelEvents::REQUEST`. Or l'alias
  `WorkflowExecutionObserverInterface` est injecté dans `ExecutionRuntime` (:479),
  `ExecutionEngine` (:550) et `ActivityMessageProcessor` (:716) — exactement les services qui
  tournent dans `messenger:consume`, où aucun `kernel.request` n'a jamais lieu. Chaque run et
  chaque activité empile une entrée dans `DurableExecutionTrace::$timeline` (payload compris) pour
  toute la vie du process.
- **Amont** : `symfony/vendor/symfony/http-kernel/DependencyInjection/ResettableServicePass.php:34`
  (seuls les services taggés `kernel.reset` entrent dans `services_resetter`) et
  `symfony/vendor/symfony/messenger/EventListener/ResetServicesListener.php:33`
  (`WorkerRunningEvent` → `servicesResetter->reset()` entre deux messages). `Profiler::reset()`
  (`symfony/vendor/symfony/http-kernel/Profiler/Profiler.php:167`) ne rattrape rien : il appelle
  `DurableDataCollector::reset()`, qui ne vide que `$this->data`, pas la trace — et le profiler
  est absent en production.
- **Correctif** : ajouter `->addTag('kernel.reset', ['method' => 'reset'])` sur
  `durable.execution_trace` (la méthode `reset()` existe déjà) ; le listener `kernel.request`
  devient alors redondant et peut disparaître.

### C2 — La route admin du plugin fige `/admin/` au lieu de `%sylius_admin.path_name%`

- **Fichier** : `src/DurablePlugin/Resources/config/routes.yaml:2` ; `sylius/config/routes/durable_plugin.yaml:1`
- **Gravité** : majeur
- **Constat** : le chemin est écrit en dur `/admin/durable/dashboard`, et l'import applicatif ne
  pose aucun `prefix`. Le nom de chemin admin de Sylius est un paramètre piloté par
  l'environnement, `admin` n'étant que sa valeur par défaut ; le pare-feu `admin` et la règle
  `access_control` en `ROLE_ADMINISTRATION_ACCESS` se calent tous deux sur
  `%sylius.security.admin_regex%`, dérivé du même paramètre. Avec
  `SYLIUS_ADMIN_ROUTING_PATH_NAME=backoffice`, la page reste à `/admin/…`, donc hors du pare-feu
  admin et hors de l'`access_control` admin ; le `shop_regex`
  (`^/(?!%sylius_admin.path_name%|api/.*|api$|media/.*)[^/]++`) la capture alors, et elle est
  servie sous le pare-feu boutique avec le provider client. Seul `#[IsGranted]` sur le contrôleur
  fait encore barrage.
- **Amont** : `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/config.yml:8-10`
  (`env(SYLIUS_ADMIN_ROUTING_PATH_NAME): admin`, `sylius.security.admin_regex: "^/%sylius_admin.path_name%"`) ;
  `sylius/vendor/sylius/sylius/src/Sylius/Bundle/ShopBundle/Resources/config/app/config.yml:13`.
  Convention amont côté plugins : `sylius/vendor/sylius/refund-plugin/config/routes.yaml:1-3` et
  `sylius/vendor/sylius/adyen-plugin/config/routes.yaml:8-10` importent tous deux un
  `config/routes/admin.yaml` sous `prefix: '/%sylius_admin.path_name%'`.
- **Correctif** : réduire le chemin à `/durable/dashboard` dans le fichier du plugin, et importer
  ce fichier avec `prefix: '/%sylius_admin.path_name%'` (idéalement en le scindant en
  `Resources/config/routes/admin.yaml`, comme les plugins amont). Le nom de route
  `gplanchat_durable_plugin_admin_dashboard` est correct et sans risque de collision, il ne bouge
  pas.

### C3 — Un GET démarre un workflow

- **Fichier** : `symfony/src/Controller/SamplesWorkflowController.php:33`
- **Gravité** : majeur
- **Constat** : `durable_samples_run` est déclarée `methods: ['GET']` mais son corps démarre une
  exécution — `dispatchWorkflowRun()` (:83) ou `runAndSettle()` (:72). Une méthode déclarée sûre
  produit ici un effet de bord persistant : un préchargement de lien, un crawler, un scanner ou un
  simple rechargement de page déclenchent des runs. La banc d'essai étant l'exemple de référence
  que les intégrateurs recopient, le motif se propage.
- **Amont** : RFC 9110 §9.2.1 (méthodes sûres : GET/HEAD ne doivent pas être perçues comme
  demandant une action de modification d'état).
- **Correctif** : passer la route en `methods: ['POST']` avec un formulaire à jeton CSRF côté
  gabarit, et répondre par une redirection 303 vers une route GET de résultat (PRG).

### C4 — Le workflow s'exécute de bout en bout à l'intérieur de la requête HTTP

- **Fichier** : `symfony/src/Controller/SamplesWorkflowController.php:49-72`
- **Gravité** : majeur
- **Constat** : `$waitForResult` vaut `true` par défaut (le paramètre `wait` doit être passé
  explicitement pour l'inverser), et la branche par défaut appelle `runAndSettle()` : le run
  complet, activités comprises, se déroule dans le process web pendant que la requête est tenue
  ouverte. Le cycle de vie du workflow n'est donc pas borné par la requête, il est *confondu* avec
  elle — un `max_execution_time` ou un timeout de reverse-proxy coupe alors le run là où il en est.
  C'est l'inverse de la promesse du produit, sur la page qui sert à le démontrer.
- **Amont** : non sourcé (argument de conception, pas de règle amont).
- **Correctif** : inverser la valeur par défaut (`dispatchWorkflowRun()` par défaut, `wait=1` en
  option de démonstration explicite) et rediriger vers une page d'état qui interroge le journal.

### C5 — Le `catch \Throwable` court-circuite `kernel.exception`

- **Fichier** : `symfony/src/Controller/SamplesWorkflowController.php:84-88`
- **Gravité** : mineur
- **Constat** : toute exception est attrapée dans le contrôleur et convertie en un rendu Twig avec
  `new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)`. Aucun `KernelEvents::EXCEPTION` n'est
  donc dispatché : ni le `ErrorListener` du HttpKernel, ni le logger, ni le panneau « Exception »
  du profiler ne voient jamais l'échec, et `$e->getMessage()` part dans la page rendue.
- **Amont** : non sourcé (comportement de `symfony/http-kernel` : `kernel.exception` n'est émis que
  pour les exceptions qui remontent hors du contrôleur).
- **Correctif** : laisser remonter l'exception (ou la ré-emballer en `HttpException`) et confier la
  page d'erreur au mécanisme d'erreur du framework.
