---
title: Premiers pas
weight: 10
---

# Premiers pas

Ce tutoriel vous mène d'une application Symfony vide à un premier workflow exécuté jusqu'au bout.
Vous installez le bundle, vous le configurez, vous écrivez une activité et un workflow, vous lancez
le workflow depuis un contrôleur, puis vous démarrez le worker qui l'exécute. Un workflow est une
méthode PHP dont l'avancement survit aux redémarrages. Une activité est un appel qui a un effet de
bord, comme une requête HTTP ou un e-mail. Le journal enregistre chaque étape et son résultat. Le
[glossaire](../glossary/) définit chacun de ces termes.

## Ce qu'il vous faut

- **PHP 8.2+**
- **Composer**
- Pour les tests : aucune infrastructure supplémentaire. Le backend **en mémoire** tourne entièrement dans un seul processus PHP.
- Pour le développement local et la production **sans cluster** : une seule base SQL, par le backend **DBAL** sous Symfony ou le backend **Illuminate** sous Laravel. Aucune extension à compiler.
- Avec un cluster, pour la production **à l'échelle** ou des tests d'intégration réalistes : un cluster **Temporal** (image Docker disponible) et l'extension PHP **`ext-grpc`**. Dans une image de conteneur, copiez l'extension depuis une [image préconstruite](../container-images/) au lieu de la compiler.

Les quatre backends exécutent le même code de workflow. [Backends](../backends/) compare ce que
chacun propose.

---

## Installation

**Cette page suit l'intégration Symfony.** Durable a trois intégrations d'hôte, chacune avec son
câblage, son fichier de configuration et son worker. Vérifiez le paquet qui correspond à votre
application avant de lancer quoi que ce soit :

| Votre application | À installer | À lire plutôt |
|---|---|---|
| **Symfony** (Sylius compris) | `gplanchat/durable-bundle` | cette page |
| **Laravel** | `gplanchat/durable-laravel` | [Paquets](../packages/#gplanchatdurable-laravel--lintégration-laravel) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Paquets](../packages/#gplanchatdurable-magento--lintégration-magento) |
| **Sans framework** | `gplanchat/durable` | [Paquets](../packages/#gplanchatdurable--la-bibliothèque) |

Les concepts, l'API de workflow et l'API d'activité sont les mêmes pour les quatre. Seul le câblage
ci-dessous est propre à Symfony.

Chaque bloc ci-dessous commence par deux lignes `composer config`. Durable est en version bêta, et
chaque paquet dépend de ses paquets voisins sur cette même ligne bêta. Un drapeau de stabilité comme
`@beta` sur la ligne `require` ne vaut que pour le paquet qui le porte, pas pour ses dépendances. Un
projet resté sur la stabilité minimale `stable` par défaut refuse donc l'installation tant qu'il
n'accepte pas les versions bêta.

### La bibliothèque seule (sans framework)
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable
```

### L'intégration Symfony
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer config extra.symfony.allow-contrib true
composer require gplanchat/durable-bundle
```


La troisième ligne autorise les recettes contrib. La recette Flex du bundle se trouve dans
`symfony/recipes-contrib`, et Flex demande confirmation avant d'exécuter une recette contrib. La
réponse par défaut est non, et c'est la seule réponse possible sous `--no-interaction`. Sans cette ligne,
l'installation affiche `IGNORING gplanchat/durable-bundle`, `config/bundles.php` ne mentionne pas le
bundle, et la configuration ci-dessous échoue avec *There is no extension able to load the
configuration for "durable"*. Si vous voyez ce message, rien d'autre n'est cassé : autorisez les
recettes contrib comme ci-dessus et relancez l'installation, répondez `y` à la question, ou ajoutez
vous-même la ligne dans `config/bundles.php` :
```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```


La recette écrit aussi un `config/packages/durable.yaml` et deux lignes `MESSENGER_DURABLE_*_DSN`
dans `.env`. Les fichiers ci-dessous **remplacent** ce `durable.yaml`. La version de la recette nomme
les magasins avec `event_store.type` et `workflow_metadata.type`, dépréciés depuis que `backend` les
remplace. Une fois le fichier remplacé, plus rien ne lit les deux lignes de `.env` : supprimez-les.

---

## Configuration Symfony minimale

### `config/packages/durable.yaml`

Par défaut, le bundle utilise le backend **en mémoire**. Il ne garde rien d'un processus à l'autre :
réservez-le aux tests. [Dans quel profil êtes-vous ?](#dans-quel-profil-êtes-vous-) indique le
backend du développement local.
```yaml
durable:
    backend: in_memory       # 'dbal' ou 'temporal' dans les profils plus bas
    activity_transport:
        type: messenger
        transport_name: durable_activities
    child_workflow:
        async_messenger: true
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\GreetingActivities   # listez ici vos interfaces d'activité
```


Pour faire tourner un environnement sur Temporal, nommez le backend et donnez-lui le DSN. Le DSN est
lu à la compilation du conteneur : un environnement qui contient ces lignes tourne sur Temporal, même
avec un `DURABLE_DSN` vide.
```yaml
when@dev:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```


### `config/packages/messenger.yaml`

Durable fait passer ses messages internes par **Symfony Messenger**. Sous Temporal, le bundle
enregistre lui-même les workers `durable_workflows` et `durable_activities`. Les deux transports
Messenger qui portent ces noms, et leur routage, n'appartiennent qu'aux environnements sans cluster,
ici `test`. Un environnement qui a un DSN et les déclare ne compile pas.
```yaml
framework:
    messenger:
        transports:
            sync: 'sync://'
        routing:
            Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage: sync
            Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage: sync

when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
            routing:
                Gplanchat\Durable\Transport\ResumeWorkflowMessage:     durable_workflows
                Gplanchat\Durable\Transport\ActivityMessage:           durable_activities
                Gplanchat\Durable\Transport\FireWorkflowTimersMessage: durable_workflows
```


Pour Temporal (`dev` / `prod`) :
```yaml
# .env.dev (ou .env.local)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```


---

## Déclarer workflows et activités

Chaque hôte enregistre les trois sortes de classe à sa manière :

| | Symfony | Laravel (`config/durable.php`) | Magento (`di.xml`, `RuntimeFactory`) |
|---|---|---|---|
| workflow | `#[AsWorkflow]` sur un service, autoconfiguré | listé dans `workflows` | listé dans l'argument `workflowClasses` |
| gestionnaire d'activités | `#[AsActivityHandler]` sur un service, autoconfiguré | listé dans `activity_handlers` | listé dans l'argument `activityHandlers` |
| gestionnaire Nexus | `#[AsNexusServiceHandler]` sur un service, autoconfiguré | listé dans `nexus.handlers` | listé dans l'argument `nexusHandlers` |

Seul Symfony enregistre une classe d'après son attribut. Laravel et Magento ne scannent rien : une
classe qu'ils ne listent pas n'est pas enregistrée, quel que soit son attribut. Sous Laravel,
`#[AsActivityHandler]` et `#[AsNexusServiceHandler]` sur un gestionnaire listé indiquent le contrat
qu'il sert. Sous Magento, `#[AsNexusServiceHandler]` l'indique, et `#[AsActivityHandler]` peut
l'indiquer. Le [tableau par hôte](../configuration/#host-table) donne tous les autres réglages. La
suite de cette section suit le chemin Symfony.

### Marquer les workflows

Vous n'avez rien à ajouter. Une classe qui porte `#[AsWorkflow]` est enregistrée dès qu'elle est un
service, et avec l'`autoconfigure: true` par défaut d'une application Symfony, elle en est déjà un.

Les versions précédentes demandaient de marquer le dossier à la main :
```yaml
# config/services.yaml — désormais inutile
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```


La balise fonctionne toujours : une application qui la déclare continue de marcher, mais la balise
fait double emploi. Si vous la gardez, gardez aussi l'`exclude`. La balise ne filtre rien : chaque
service qu'elle désigne est transmis au registre des workflows, qui exige exactement un
`#[AsWorkflowMethod]` par classe et lève une exception sinon.

### Déclarer les implémentations d'activité

Sous Symfony, vous n'avez rien à ajouter. L'autoconfiguration du bundle enregistre une classe qui porte `#[AsActivityHandler]` dès qu'elle est un service, et avec l'`autoconfigure: true` par défaut d'une application Symfony, elle en est déjà un.

---

## Un premier workflow

Les cinq étapes suivantes construisent un workflow qui salue un nom. L'activité est la salutation. Le
workflow l'appelle, et Durable enregistre son résultat dans le journal.

### 1. Définir un contrat d'activité {#1--définir-un-contrat-dactivité}

Le contrat est une interface. Le workflow l'appelle ; le gestionnaire de l'étape 2 l'implémente.
```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;

// Optionnel : préfixe le nom des activités déclarées en dessous.
#[AsActivity(name: 'greeting-activities')]
interface GreetingActivities
{
    #[AsActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}
```


### 2. Implémenter l'activité {#2--implémenter-lactivité}
```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

// Sous Symfony, cet attribut enregistre la classe ; Laravel et Magento la listent.
#[AsActivityHandler(contract: GreetingActivities::class)]
final class GreetingActivitiesHandler implements GreetingActivities
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}
```


### 3. Définir le workflow {#3--définir-le-workflow}
```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Workflow\Activity\GreetingActivities;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'greet')]
final class GreetWorkflow
{
    /** @param ActivityStub<GreetingActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(GreetingActivities::class)]
        ActivityStub $greeting,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greeting->greet($name));
    }
}
```


`$name` vient de l'entrée avec laquelle le workflow démarre. Durable fournit `$greeting` et `$env`,
comme Symfony fournit ses services à un contrôleur. Voyez
[Les arguments que fournit Durable](../workflows/#arguments-durable-supplies).

### 4. Le déclencher depuis un contrôleur ou un service {#4--le-déclencher-depuis-un-contrôleur-ou-un-service}

Démarrez une exécution avec `WorkflowResumeDispatcher::dispatchNewWorkflowRun()`. C'est la seule
façon de démarrer une exécution qui fonctionne sur tous les backends : en mémoire, DBAL et Temporal. Sur Temporal, elle appelle `startAsync()` du
client à votre place. N'appelez `startAsync()` vous-même que si vous avez besoin de ses options de
démarrage (délais, attributs de recherche, cron). Cette méthode appartient au client Temporal et
n'existe sur aucun autre backend.

Le deuxième argument est le nom du workflow : celui que déclare `#[AsWorkflow]`, ou le nom court de
la classe en l'absence d'attribut. Vous pouvez aussi passer `GreetWorkflow::class`. Le répartiteur le
ramène au même nom, et le journal, le tableau de bord et `durable:execution:diagnose` affichent
`greet` dans les deux cas.
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;

final class GreetController
{
    public function __construct(
        private readonly WorkflowResumeDispatcher $dispatcher,
    ) {}

    public function __invoke(string $name): JsonResponse
    {
        $executionId = ExecutionId::fromString('greet-'.uniqid());
        $this->dispatcher->dispatchNewWorkflowRun($executionId, 'greet', ['name' => $name]);

        // 202 : le run est en file, pas terminé. Répondre 200 ici est la première chose qui
        // fait attendre un résultat qu'aucun consommateur n'a encore produit.
        return new JsonResponse(['executionId' => $executionId->toString()], JsonResponse::HTTP_ACCEPTED);
    }
}
```


Le contrôleur répond `202 Accepted` avec l'identifiant d'exécution. Le workflow n'a pas encore
tourné : il attend en file qu'un worker le consomme, ce qui est l'étape suivante.

### 5. Faire tourner un consommateur, sinon rien n'arrive

`dispatchNewWorkflowRun()` rend `void` et ne fait qu'*envoyer* l'exécution. Le workflow s'exécute
quand un worker consomme les transports configurés plus haut. D'ici là, l'exécution attend en file,
et un tableau de bord l'affiche `RUNNING`. Dans cet état, `RUNNING` veut dire *pas terminée* ; cela
ne veut pas dire qu'un worker la traite.

Démarrez le worker :
```bash
php bin/console durable:worker
```


Le worker lit les noms des transports dans votre configuration : les transports vers lesquels
`messenger.yaml` route `ResumeWorkflowMessage` et `FireWorkflowTimersMessage`, et
l'`activity_transport` de `durable.yaml`. Il affiche les transports qu'il consomme
(`Consuming durable_workflows, durable_activities.`) et confie le reste à `messenger:consume`, à qui
il transmet `--limit`, `--time-limit`, `--memory-limit`, `--failure-limit`, `--sleep` et
`--no-reset`. Si les reprises ne sont routées nulle part, ou seulement vers `sync`, le worker ne
démarre pas et affiche pourquoi, au lieu d'attendre devant une file vide.

`messenger:consume durable_workflows durable_activities` fonctionne toujours : ces deux noms sont les
transports que **vous** avez déclarés dans `messenger.yaml`. Les signaux et les mises à jour n'y
figurent pas, parce que ce guide les route vers `sync`. Si vous les routez vers un transport
asynchrone à vous, consommez ce transport vous-même : `durable:worker` ne le cherche pas.

Ces deux commandes relèvent du profil **à plusieurs processus** décrit plus bas : un worker dans son
propre processus, sur de vrais transports. Le profil `when@test` ci-dessus met les deux transports en
`in-memory://`, où un worker séparé ne consomme rien. Un transport en mémoire ne contient que les
messages envoyés par son propre processus, et Messenger réinitialise les services après chaque
message traité, ce qui vide la file en mémoire. L'activité que le workflow a mise en file disparaît,
et l'exécution reste sur `ActivityScheduled`. Dans ce profil, menez le workflow jusqu'au bout dans
le test qui l'a envoyé (`DurableBundleTestTrait`, voyez [Tester des workflows](../testing/)), ou
consommez dans ce même processus avec `--no-reset`. Sans `--no-reset`, les deux commandes refusent
de démarrer sur un transport Durable en mémoire et indiquent laquelle de ces deux solutions choisir.

Pour voir ce que le moteur conserve d'une exécution, passez son identifiant :
```bash
php bin/console durable:execution:diagnose greet-abc123
```


La commande affiche les métadonnées de l'exécution, ses liens parent et enfants, et les premiers
événements de son journal avec leurs données : l'entrée du workflow, puis les arguments et le
résultat de chaque activité. Pour ce workflow, le résultat de l'activité `greet` est
`Hello, <name>!`, avec le nom passé au contrôleur. Quand vous le voyez dans le journal, votre premier
workflow s'est exécuté jusqu'au bout !

Les valeurs rangées sous des clés comme `password`, `token`, `secret`, `authorization`, `card` ou
`api_key` sont masquées, et les longues chaînes sont tronquées ; `--raw` les affiche telles qu'elles
sont stockées. Le masquage se fait d'après le nom de la clé : des données personnelles rangées sous
d'autres clés restent visibles, vérifiez où vous collez la sortie. Le panneau du profileur web masque
les valeurs de la même façon.

#### Dans quel profil êtes-vous ?

Trois configurations fonctionnent, une par étape. Si vous en mélangez deux, rien ne signale d'erreur :
l'exécution échoue en silence.

**Les tests : un seul processus, en mémoire.** Transports `in-memory://` et magasins en mémoire.
Envoi, reprise et activité s'exécutent dans un même processus PHP : un test envoie l'exécution et la
mène à son terme en une fois, avec `DurableBundleTestTrait` ou avec `durable:worker --no-reset` dans
ce processus. Les commandes de worker de l'étape 5 relèvent du profil suivant. Un transport en
mémoire **ne survit pas à son processus** : envoyer depuis une requête web et consommer dans un
worker séparé ne peut pas fonctionner, pas plus que le rejeu, car le journal dont le worker aurait
besoin se trouve dans la mémoire du processus web.

**Le développement local, et la production sans cluster : plusieurs processus, sur DBAL.** De vrais
transports **et** un magasin durable. Configurez les deux : avec un vrai transport seul, le worker
reçoit une entrée de file pour un workflow dont il ne peut pas lire le journal.

Ce profil demande des paquets que la prise en main ci-dessus n'installe pas : le journal DBAL,
DoctrineBundle pour le service `doctrine.dbal.default_connection` qu'utilise le journal, et le
transport Doctrine de Messenger pour les files `doctrine://` plus bas. La recette de DoctrineBundle
configure aussi l'ORM, d'où la présence de `doctrine/orm`. Si vous n'utilisez pas l'ORM, retirez
plutôt la section `orm:` de `config/packages/doctrine.yaml`.
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-dbal doctrine/doctrine-bundle doctrine/orm symfony/doctrine-messenger
```

```yaml
durable:
    backend: dbal
    dbal:
        connection: doctrine.dbal.default_connection
```


Les deux files quittent `when@test:` pour cet environnement, sur Doctrine, avec le même routage :
```yaml
framework:
    messenger:
        transports:
            durable_workflows:  'doctrine://default?queue_name=durable_workflows'
            durable_activities: 'doctrine://default?queue_name=durable_activities'
```


**Avec un cluster Temporal : le DSN seulement.** Un environnement dont `durable.temporal.dsn` est
renseigné tourne sur Temporal. Le cluster conserve le journal et les files, et les
[workers ci-dessous](#démarrer-les-workers-temporal-production--mode-dev) l'interrogent. Le bloc
`when@dev` plus haut met `dev` dans ce profil ; retirez-le pour développer sur DBAL.

Les trois profils suivent une même règle : **une exécution survit exactement à ce à quoi survivent
son journal et sa file.** Si vous routez `ResumeWorkflowMessage` ou `ActivityMessage` vers un
transport qu'un worker séparé ne peut pas lire, le workflow rejoue dans la requête web qui l'a
démarré, et s'arrête quand ce processus s'arrête.

---

## Démarrer les workers Temporal (production / mode dev)

Quand `DURABLE_DSN` pointe vers un serveur Temporal, lancez les workers enregistrés par le bundle,
chacun dans son propre processus. **Ce sont les commandes Symfony.** Les autres hôtes interrogent le
même cluster avec les leurs : `php artisan durable:temporal-worker` et `--role=activity` sous
Laravel, `bin/magento durable:worker --role=journal` et `--role=activity` sous Magento.
```bash
# Worker des tâches de workflow (interroge Temporal pour les tâches de workflow)
php bin/console durable:worker --role=workflow

# Worker d'activités (interroge Temporal pour les tâches d'activité)
php bin/console durable:worker --role=activity
```


Une application qui [sert une opération Nexus](../nexus/) lance un troisième worker,
`--role=nexus`. Ces workers sont les récepteurs `durable_workflows`, `durable_activities` et
`durable_nexus`, et `messenger:consume` accepte aussi ces noms.

Sur Temporal, lancez **un processus par rôle**. Chaque récepteur interroge le cluster en attente
longue, et un worker interroge ses récepteurs à tour de rôle. Dans un seul processus, une tâche de
workflow peut attendre qu'une attente d'activité inoccupée expire avant d'être prise en charge. Pour
la même raison, `--limit` et `--failure-limit` n'arrêtent jamais un worker Temporal, car ses
récepteurs ne transmettent aucun message à Messenger. `--time-limit` et `--memory-limit`
l'arrêtent, une fois l'attente en cours terminée.

En développement local avec `symfony serve`, ajoutez ceci à `.symfony.local.yaml` :
```yaml
workers:
    workflows:
        cmd: ['symfony', 'console', 'durable:worker', '--role=workflow', '--time-limit=3600']
    activities:
        cmd: ['symfony', 'console', 'durable:worker', '--role=activity', '--time-limit=3600']
```


---

## Et ensuite

- [Concepts](../concepts/) : le modèle de rejeu, les backends et l'historique d'événements.
- [Écrire un workflow](../workflows/) : l'API complète, avec signaux, requêtes, mises à jour, workflows enfants et minuteurs.
- [Écrire des activités](../activities/) : `ActivityOptions`, réessais, délais et injection de dépendances.
- [Tester des workflows](../testing/) : `DurableTestCase`, `ActivitySpy` et `DurableBundleTestTrait`.
- [Référence de configuration](../configuration/) : chaque clé de `durable.yaml`.
- [Backends](../backends/) : la mémoire, DBAL, Illuminate et Temporal, quand choisir lequel, et la mise en place Docker Compose.
