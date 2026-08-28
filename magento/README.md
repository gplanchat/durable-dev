# Le banc Magento

Un **banc**, pas une application : de quoi faire tourner `gplanchat/durable-magento` dans un vrai
Magento et regarder ce qu'il fait. Un module de palier 1 ne se teste contre rien de plus petit que
Magento, donc ce dossier *est* le harnais.

Ce qui est au dépôt : `composer.json` et son verrou, `compose.yaml`, le script de précontrôle des
extensions, deux sondes, et le module de sonde qu'elles pilotent. Rien de la distribution — voir
`.gitignore`, qui explique pourquoi la règle y est inversée.

## Ce qu'il faut avant de commencer

Mage-OS s'installe sans identifiants Adobe, depuis `https://repo.mage-os.org/`. Le banc est épinglé
sur `mage-os/product-community-edition:2.2.0`, compatible PHP 8.2, ce que le graphe de dépendances
de Durable impose.

```bash
cd magento
bash ./check-php-extensions.sh
```

Le script mesure ; la liste qu'il porte est plus fiable que celle d'un README. Sur Debian ou Ubuntu,
`ext-pdo_mysql` s'ajoute par `sudo apt-get install -y php8.2-mysql`.

## Amorcer

```bash
cd magento
cp .env.example .env
docker compose up -d
composer install
```

Les ports sont **décalés exprès**, pour ne pas se disputer ceux des bancs voisins :

| service | hôte |
|---|---|
| MySQL | `33306` |
| OpenSearch | `9201` |
| Temporal | `7234` |
| Temporal UI | `8088` |
| la boutique et son admin | `8080` |

Puis l'installation, si la base est vide :

```bash
bin/magento setup:install \
  --base-url=http://127.0.0.1:8080/ \
  --db-host=127.0.0.1:33306 --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=Durable --admin-lastname=Ops --admin-email=durable@example.com \
  --admin-user=durable --admin-password='<votre mot de passe>' \
  --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
  --search-engine=opensearch --opensearch-host=127.0.0.1 --opensearch-port=9201 \
  --backend-frontname=admin

bin/magento module:enable Gplanchat_DurableModule
bin/magento setup:upgrade
bin/magento cache:flush
```

Le module s'appelle **`Gplanchat_DurableModule`** et son paquet Composer
**`gplanchat/durable-magento`** — les deux conventions ne se croisent pas, et le
`registration.php` du module explique pourquoi.

Le banc en active un second, **`Gplanchat_DurableProbe`**, qui vit dans
`app/code`. C'est lui qui porte la démonstration et les sondes : le paquet publié
ne déclare **aucun** workflow, et ses deux tableaux de `di.xml` sont vides. Un
module d'intégration n'a pas à faire porter à un projet des workflows qui ne sont
pas les siens.

```bash
bin/magento module:enable Gplanchat_DurableModule Gplanchat_DurableProbe
```

## Le voir tourner

**En ligne de commande**, le chemin le plus court :

```bash
bin/magento durable:demo ORD-4242
#   1. durable.demo.charge
#   2. durable.demo.reserve
#   3. durable.demo.notify
#   → 'notify:charge:ORD-4242'
```

Les trois lignes ne sont pas des étiquettes écrites dans la commande : ce sont les noms que
`#[ActivityMethod]` porte sur le contrat, résolus au moment où le module assemble son moteur.

**Dans le back-office** — Magento livre son propre serveur de développement, il n'y a rien à
installer :

```bash
php -S 127.0.0.1:8080 -t pub/ phpserver/router.php
```

Puis `http://127.0.0.1:8080/admin`, et **`System > Durable processes > Process history`**. L'écran
est en lecture seule : ce qu'un exploitant vient y chercher est de savoir si une commande est
passée, pas de la relancer à la main — reprendre depuis un navigateur contournerait le verrou par
exécution.

Un compte d'administration s'ajoute par `bin/magento admin:user:create`. Si la double
authentification gêne en local : `bin/magento module:disable Magento_TwoFactorAuth`.

## Où vit le journal

Magento n'atteint que deux backends, **et c'est définitif** : `memory` et `temporal`. Les ponts SQL
sont refusés à l'installation par un `conflict` dans le `composer.json` du module, parce que
`ResourceConnection` n'est ni une connexion Doctrine DBAL ni celle d'Illuminate.

Ce n'est pas un nom de backend qui choisit, c'est **la présence d'un DSN** dans `app/etc/env.php`,
à côté de `lock` et `queue` :

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://127.0.0.1:7234?namespace=default&tls=0'],
],
```

Sans lui, le journal vit dans le processus qui l'écrit et meurt avec lui — la grille du back-office
est alors vide, **et c'est la bonne réponse** : une requête d'administration ouvre un processus
neuf. La page le dit elle-même plutôt que de laisser croire à une panne.

Avec lui, la grille lit la grappe :

```
Run                                   | Workflow       | Status  | Started
d81bfb25-af86-43b9-a310-9d9d34695a30  | DurableJournal | running | 2026-08-28 09:23:45
```

⚠ **Deux réserves à connaître.** Le nom affiché est `DurableJournal` : c'est le type Temporal qui
*porte* le journal d'une exécution, pas le type métier. Et le statut reste `running` — **aucun
worker ne draine encore la file de tâches**, donc rien ne clôt les journaux. C'est la suite de la
tâche 5 du change `magento-module`.

## Les sondes

Deux scripts, gardés parce qu'ils se rejouent, et un module de sonde dans `app/code` qui porte le
sujet de file qu'ils pilotent — hors du paquet publié, parce qu'un sujet dont le gestionnaire ne
fait que dormir n'a rien à y faire.

```bash
php probe-lock.php which                      # le verrou est-il partagé entre processus ?
php probe-queue.php publish <étiquette> <s>   # un message qui traîne
php probe-queue.php state                     # l'état des messages, en clair
php probe-queue.php recover                   # la tâche cron qui rattrape les IN_PROGRESS
php probe-queue.php unlock                    # la tâche cron qui vide queue_lock
php probe-queue.php purge                     # écarte les messages des campagnes passées
```

⚠ **Mesurer sur une file sale répond à côté** : un consommateur prend le plus ancien candidat, pas
le vôtre. `purge` avant toute campagne.

## Ce qui mord, et que rien ne dit

Six contraintes d'hôte trouvées en construisant. Chacune a coûté un tour de débogage, et aucune ne
se manifeste là où elle est commise.

- **Magento interdit `final`** sur toute classe que son conteneur instancie : il engendre un
  `Interceptor` qui l'étend. Le message — *« cannot extend final class »* — ne dit pas que le
  mot-clé est en cause.
- **Mage-OS audite les dépôts de chemin.** `composer-dependency-version-audit-plugin` refuse un
  paquet résolu localement quand un plus récent existe sur packagist.org. Le banc le désactive pour
  lui-même ; un projet consommateur doit le garder.
- **Un module d'`app/code` ne s'autocharge pas** sur cette distribution : le `composer.json` racine
  de Mage-OS ne porte pas le `psr-0: {"": ["app/code/"]}` d'un Magento classique. Enregistrer un
  composant et autocharger ses classes sont deux mécanismes distincts, et seul le premier est
  automatique.
- **Un contrôleur se résout par convention depuis le nom du module**, pas depuis l'autochargement :
  `Gplanchat_DurableModule` + `\Controller\Adminhtml\…`. Le module ajoute donc une seconde entrée `psr-4`
  pour ce seul dossier. Sans elle, la route est déclarée, **le menu s'affiche**, et Magento sert son
  404 dans le châssis d'admin — tous les symptômes désignent la déclaration, qui est juste.
- **Un argument de constructeur optionnel n'est pas auto-câblé** : Magento prend son défaut. Il faut
  le nommer dans `di.xml`, sinon la dépendance reste `null` sans une ligne d'erreur.
- **Renommer une classe que le conteneur instancie** laisse un intercepteur périmé dans
  `generated/code/`, que `setup:upgrade --keep-generated` ne retire pas. Symptôme : *« There are no
  commands defined in the "durable" namespace »*.

## Quand le module change et que le banc ne suit pas

Les dépôts de chemin sont en `"symlink": false` : éditer `src/DurableModule` ne change **rien** à
`magento/vendor/gplanchat/durable-magento` tant qu'on n'a pas réinstallé.

```bash
composer update gplanchat/durable-magento gplanchat/durable-bridge-temporal
rm -rf generated/code/Gplanchat
bin/magento setup:upgrade
bin/magento cache:flush
```

Composer relit le paquet de chemin depuis la **copie principale** du dépôt, pas depuis un worktree :
un changement fait dans un worktree n'arrivera au banc qu'une fois fusionné.

## Autres pannes d'amorçage

- **`Class "Magento\Setup\Mvc\Bootstrap\InitParamListener" not found`** — `composer dump-autoload`
  dans `magento/` ; l'overlay déclare `Magento\Setup\` dans son `composer.json`.
- **`You do not have the SUPER privilege … CREATE TRIGGER`** — l'overlay lance MySQL avec
  `--log-bin-trust-function-creators=1` ; recréer le service :
  `docker compose up -d --force-recreate magento-db`.
- **`Could not validate a connection to the OpenSearch`** — `docker compose ps opensearch`, et
  vérifier le port `9201`.

## Notes

- Ce dépôt ne livre pas de distribution Magento préinstallée.
- Le banc est Mage-OS. Que le module tourne sans modification sur la distribution d'Adobe est une
  question ouverte, que personne n'a mesurée.
