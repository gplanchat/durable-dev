# Composant Console et surface de sécurité — signature, sortie, codes de retour

## Synthèse
Le périmètre console tient en deux commandes : `durable:execution:diagnose` (bundle Symfony) et `durable:worker` (module Magento) ; `bin/` ne contient que des scripts shell d'opérateur, pas de point d'entrée PHP. Les deux commandes sont correctement construites (injection par constructeur, `configure()` déclaratif, retours `Command::SUCCESS/FAILURE`), mais aucune ne traite sa sortie ni ses entrées selon les conventions du composant : la sortie JSON traverse le formateur et perd du contenu, l'erreur part sur stdout, et l'option `--role` lève une exception hors de la hiérarchie `Console\Exception`. La commande `durable:worker` est une boucle longue sans `SignalableCommandInterface` alors que son propre docblock la confie à systemd/supervisor. **Sur l'axe désérialisation, le périmètre est sain** : aucun `unserialize()`, aucun `eval()`, aucun `Closure::fromCallable()` dans `src/Durable`, `src/DurableBundle`, `src/Bridge/Dbal` ; `EventDataMapper::toDomainEvent()` est une liste blanche `match` fermée par un `default => throw`, et `WorkflowRegistry` ne résout qu'un type préenregistré — un journal falsifié ne peut donc pas instancier une classe arbitraire. Le dashboard Sylius est protégé par `#[IsGranted]` **et** par l'`access_control` Sylius, mais son chemin est écrit en dur.

## Constats

### C1 — La sortie `--json` traverse le formateur et perd du contenu
- **Fichier** : `src/DurableBundle/Command/DiagnoseExecutionCommand.php:88`
- **Gravité** : majeur
- **Constat** : `$output->writeln(json_encode($payload, ...))` écrit en mode `OUTPUT_NORMAL`, donc `OutputFormatter::format()` consomme toute balise reconnue présente dans les données. Reproduit sur `vendor/symfony/console` du dépôt : l'entrée `{"note":"client <error>secret</error> <fg=red>y</>"}` ressort `{"note":"client secret<\/error> y<\/> "}` — le contenu d'une charge utile d'activité est silencieusement amputé, et décoration activée ce sont des séquences ANSI qui sont injectées dans les valeurs JSON. Le même défaut touche la sortie humaine ligne 129-135, qui passe `truncateJson()` dans `$io->writeln(sprintf(...))`.
- **Amont** : `vendor/symfony/console/Descriptor/Descriptor.php:47` — les descripteurs JSON/XML de Symfony écrivent leur contenu avec `OutputInterface::OUTPUT_RAW` (`vendor/symfony/console/Output/OutputInterface.php:33`) précisément pour cela ; `OutputFormatter::escape()` (`vendor/symfony/console/Formatter/OutputFormatter.php:41`) couvre le cas de la sortie humaine.
- **Correctif** : passer `OutputInterface::OUTPUT_RAW` en troisième argument de `write()` pour la branche `--json`, et envelopper les extraits de charge utile dans `OutputFormatter::escape()` pour la branche humaine.

### C2 — Le worker est une boucle longue sans gestion de signal
- **Fichier** : `src/DurableModule/Console/Command/RunWorkerCommand.php:114-120` (classe déclarée ligne 40)
- **Gravité** : majeur
- **Constat** : la boucle ne sort que par `--max-tasks` ou `--time-limit` ; la classe n'implémente pas `SignalableCommandInterface`, donc un `SIGTERM` de superviseur tue le processus au milieu d'un `$tick()` — exactement le scénario que le docblock de la classe revendique (« un superviseur redémarre »). Sans `--max-tasks` ni `--time-limit`, la boucle est infinie et n'a aucun point d'arrêt propre.
- **Amont** : `vendor/symfony/messenger/Command/ConsumeMessagesCommand.php:43` (`implements SignalableCommandInterface`), `:317` `getSubscribedSignals()`, `:322` `handleSignal()` — le worker de référence de l'écosystème finit son message courant puis s'arrête ; interface fournie par `vendor/symfony/console/Command/SignalableCommandInterface.php`.
- **Correctif** : implémenter `SignalableCommandInterface` en s'abonnant à `SIGTERM`/`SIGINT`, poser un drapeau d'arrêt lu en tête de boucle, et retourner `Command::SUCCESS` après le tour en cours.

### C3 — Le chemin du dashboard écrit en dur ignore le préfixe admin configurable de Sylius
- **Fichier** : `src/DurablePlugin/Resources/config/routes.yaml:2` (`path: /admin/durable/dashboard`)
- **Gravité** : mineur
- **Constat** : Sylius rend son préfixe d'administration configurable par variable d'environnement ; la route du greffon le fige à `/admin`. Sur une boutique dont le préfixe a été changé, la page sort du pare-feu `admin` et du `access_control` correspondant, retombe dans le pare-feu `shop` (contexte de session différent) et devient inaccessible à un administrateur pourtant authentifié. Aucune fuite : `#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]` (`src/DurablePlugin/Controller/AdminDashboardController.php:23`) refuse quand même.
- **Amont** : `sylius/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/config.yml:9-10` — `sylius_admin.path_name: '%env(resolve:SYLIUS_ADMIN_ROUTING_PATH_NAME)%'` puis `sylius.security.admin_regex: "^/%sylius_admin.path_name%"`, référencé par `sylius/config/packages/security.yaml:19` et `:120`.
- **Correctif** : préfixer la route par `%sylius_admin.path_name%` (ou documenter le greffon comme à importer sous le préfixe admin de l'application) plutôt que par `/admin` littéral.

### C4 — Validation d'option faite dans `execute()` et exception hors hiérarchie Console
- **Fichier** : `src/DurableModule/Console/Command/RunWorkerCommand.php:95`
- **Gravité** : mineur
- **Constat** : un `--role` inconnu lève un `\InvalidArgumentException` global depuis `execute()`. Le composant dispose d'une hiérarchie dédiée dont l'application sait qu'elle signale une erreur d'usage et non un plantage applicatif ; ici l'exploitant reçoit une trace d'exception applicative pour une faute de frappe. Le `default` du `match` est de plus atteint après que la commande a déjà été acceptée par le `InputDefinition`.
- **Amont** : `vendor/symfony/console/Exception/InvalidOptionException.php` et `vendor/symfony/console/Exception/ExceptionInterface.php` — les erreurs de valeur d'option du composant passent toutes par là (cf. `Input::validate()`).
- **Correctif** : lever `Symfony\Component\Console\Exception\InvalidOptionException` et déplacer le contrôle dans `initialize()`, avant tout effet de bord.

### C5 — L'erreur part sur stdout, et un identifiant inconnu sort en code 0
- **Fichier** : `src/DurableBundle/Command/DiagnoseExecutionCommand.php:45` (et `:97-98`, `:141`)
- **Gravité** : mineur
- **Constat** : le refus d'un `executionId` vide est écrit avec `$output->writeln()`, donc sur stdout — le canal que `--json` réserve aux données. Par ailleurs un identifiant totalement inconnu (aucune métadonnée, flux vide) produit un `warning` et retourne `Command::SUCCESS`, ce qui rend la commande inutilisable comme sonde dans un script.
- **Amont** : `vendor/symfony/console/Style/SymfonyStyle.php:360` — `getErrorStyle()` existe pour router les messages d'erreur vers `ConsoleOutputInterface::getErrorOutput()`.
- **Correctif** : écrire les erreurs via `$io->getErrorStyle()`, et retourner `Command::FAILURE` quand ni métadonnées ni événements n'existent pour l'identifiant demandé.

### C6 — `--limit` accepte n'importe quelle valeur sans le dire
- **Fichier** : `src/DurableBundle/Command/DiagnoseExecutionCommand.php:50`
- **Gravité** : mineur
- **Constat** : `max(0, (int) $input->getOption('limit'))` transforme `--limit=abc` et `--limit=-5` en `0`. La commande s'exécute alors sans échantillon d'événements et sans signaler que l'option a été ignorée — un diagnostic silencieusement amputé est pire qu'un refus.
- **Amont** : non sourcé (aucune règle amont explicite ; `messenger:consume --limit` ne valide pas davantage sa valeur).
- **Correctif** : refuser une valeur non numérique ou négative avec `InvalidOptionException`, en rappelant la valeur reçue.

### C7 — TLS désactivé par défaut sur la connexion gRPC, sans mTLS possible
- **Fichier** : `src/Bridge/Temporal/WorkflowServiceClientFactory.php:23-27`
- **Gravité** : mineur
- **Constat** : le canal est construit avec `ChannelCredentials::createInsecure()` sauf si le DSN porte `?tls=1` (`src/Bridge/Temporal/TemporalConnection.php:108`), et `createSsl()` est appelé sans argument — aucune façon de présenter un certificat client, une CA ou un `serverName`. Une grappe distante ne peut donc être jointe qu'en clair ou en TLS simple. À noter en sens inverse : le DSN est marqué `#[\SensitiveParameter]` (`TemporalConnection.php:92`), ce qui l'exclut des traces.
- **Amont** : https://docs.temporal.io/self-hosted-guide/security — « Temporal supports Mutual Transport Layer Security (mTLS) […] between application processes and a Temporal Service » ; le `serverName` y est recommandé contre l'usurpation.
- **Correctif** : accepter des paramètres de DSN pour la CA, le certificat et la clé client, et les passer à `ChannelCredentials::createSsl($rootCerts, $privateKey, $certChain)`.

### C8 — Les charges utiles d'activité sont recopiées brutes dans le profiler et dans le diagnostic
- **Fichier** : `src/DurableBundle/DataCollector/DurableDataCollector.php:643` et `src/DurableBundle/Command/DiagnoseExecutionCommand.php:69`
- **Gravité** : remarque
- **Constat** : `'payload' => $event->payload()` verse la charge utile complète d'un événement dans le profil sérialisé, et `--json` la reverse intégralement sur stdout. Rien n'expurge un jeton, un mot de passe ou une donnée personnelle qu'une activité aurait reçu en argument ; le profil persiste sur disque dans `var/cache`. Aucun `Logger` n'est injecté dans le cœur ni dans le bundle, le journal applicatif est donc hors de cause.
- **Amont** : non sourcé (Symfony n'impose pas d'expurgation dans les collecteurs ; le précédent le plus proche est `#[\SensitiveParameter]`, déjà employé ailleurs dans le dépôt).
- **Correctif** : offrir une liste de clés à masquer (configuration du bundle) appliquée dans `summarizePayload()` et dans l'échantillon du diagnostic, ou n'exposer la charge utile complète que sous `-vv`.

## Note de couverture
Aucun test du greffon Sylius n'exerce le contrôle d'accès : `src/DurablePlugin/tests/` ne contient aucune assertion sur `IsGranted`, un 403 ou un rôle. La suppression accidentelle de l'attribut ligne 23 du contrôleur ne serait rattrapée par rien.
