<?php

declare(strict_types=1);

/*
 * Les valeurs par defaut du paquet, et la copie que `vendor:publish --tag=durable-config` depose
 * dans l'application. Un seul fichier pour les deux, donc rien a faire diverger.
 *
 * ATTENTION : aucun appel a `env()` ici. Le provider charge ce fichier comme jeu de valeurs par
 * defaut, y compris dans un worker autonome et dans un test — ou `env()` existe, puisqu'il vient
 * d'`illuminate/support`, mais explose sur `PhpOption\Option` que seul `vlucas/phpdotenv`
 * fournit. C'est la panne exacte que le docblock de `ResumeLock` decrit a propos de
 * `Lock::block()` : elle n'arrive que la ou personne ne regarde. Votre copie publiee, elle,
 * tourne toujours dans une application : mettez-y les `env()` que vous voulez.
 */

return [
    /*
     * Le backend de stockage. Ce paquet en sert deux, et refuse les autres par leur nom plutôt que
     * d'échouer à la première exécution : « illuminate » pose le journal sur la connexion que
     * l'application possède déjà, « memory » ne survit pas au processus et n'est là que pour les
     * tests.
     */
    'backend' => 'illuminate',

    /*
     * La connexion de base de données, au sens de config/database.php. `null` prend celle par
     * défaut de l'application — ce qui est le point de DUR030 : l'ajout au journal et l'écriture
     * métier tiennent dans une seule transaction parce que c'est la même connexion.
     */
    'connection' => null,

    /*
     * Les classes de workflow que cette application déclare.
     *
     * Le conteneur de Laravel n'a pas d'équivalent de l'autoconfiguration par attribut de Symfony,
     * donc la déclaration est explicite. Ce que ça ne change pas, c'est la classe : celle qui tourne
     * sur `durable-bundle` tourne ici sans une ligne de différence.
     *
     * Mesuré (§1.4) : cette liste coûte 0,14 ms et ne grandit pas avec l'application, là où un scan
     * par réflexion coûte 15 ms à mille classes et les charge toutes, dans chaque processus, pour en
     * trouver cinq.
     *
     * @var list<class-string>
     */
    'workflows' => [],

    /*
     * Le cluster Temporal, quand `backend` vaut « temporal ».
     *
     * Le DSN porte l'adresse, l'espace de noms et les deux files de tâches :
     *   temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
     *
     * Ce backend demande `gplanchat/durable-bridge-temporal`, qui est **suggéré et non exigé** :
     * il installe un client gRPC et cinq composants Symfony qu'une application Laravel ne charge
     * jamais. Le provider le dit par son nom si le paquet manque.
     *
     * Le journal et le catalogue vivent alors dans le cluster ; les activités et les reprises
     * continuent de voyager sur la file de l'application. Les tâches de workflow, elles, se
     * drainent avec `php artisan durable:temporal-worker`.
     */
    'temporal' => [
        'dsn' => null,
    ],

    'tables' => [
        'events' => 'durable_events',
        'metadata' => 'durable_workflow_metadata',
        'parent_links' => 'durable_child_workflow_parent_link',
        'runs' => 'durable_workflow_runs',
    ],

    /*
     * La file qui porte les activités et les reprises, au sens de config/queue.php. `null` prend
     * la connexion et la file par défaut de l'application.
     *
     * Il n'y a pas de seconde file : le travail de Durable voyage sur celle que l'application
     * draine déjà, avec `php artisan queue:work` pour seul worker.
     */
    'queue' => [
        'connection' => null,
        'name' => null,
    ],

    /*
     * Les opérations Nexus que cette application **sert** — appeler une opération n'a rien à
     * déclarer ici, c'est le workflow qui la demande.
     *
     * La clé est la classe du gestionnaire, la valeur le contrat qu'il sert :
     *
     *     'handlers' => [App\Nexus\BillingHandler::class => App\Contracts\BillingService::class],
     *
     * Ce qu'un gestionnaire ne sert pas, un workflow le remplit — il porte alors
     * `#[FulfilsNexusOperation]`, et il suffit qu'il soit dans la liste `workflows` ci-dessus.
     *
     * ⚠ Servir du Nexus exige le backend « temporal » : c'est le cluster qui route. Sous un autre
     * backend, le registre refuse à l'enregistrement et dit pourquoi, plutôt que d'échouer au
     * premier appel.
     */
    'nexus' => [
        'handlers' => [],
    ],

    'lock' => [
        /*
         * Le magasin de cache qui porte le verrou de reprise, au sens de config/cache.php. `null`
         * prend celui par défaut.
         *
         * ⚠ Il doit verrouiller **entre processus**. Mesuré sur Laravel 12 avec quatre workers et
         * vingt reprises d'une même exécution : `database` et `file` ne laissent aucun
         * chevauchement, `array` en laisse quinze sur vingt (il n'exclut que dans un processus) et
         * `null` autant (il n'exclut rien). Les quatre implémentent `LockProvider` : le typage ne
         * vous protège pas, ce réglage si.
         */
        'store' => null,

        /* Ce qui libère le verrou quand le processus qui le tient meurt. */
        'ttl' => 300,

        /*
         * Le report d'une reprise dont le tour est pris, en secondes.
         *
         * Mesuré (§1.5) : sur une exécution chaude — réveillée sans cesse par des signaux ou des
         * minuteurs — 98,8 % des reprises entrent en collision, et ce délai **est** alors la
         * latence : une seconde a transformé 32 s de travail en 148 s d'horloge. Sur un parc de
         * beaucoup d'exécutions, les collisions tombent à 0,6 % et le réglage n'a plus d'effet.
         */
        'backoff' => 1,

        /*
         * Combien de fois de suite une reprise accepte de trouver le tour pris avant d'abandonner
         * bruyamment. Un report sans fin ressemble à une exécution qui avance.
         */
        'max_deferrals' => 50,

        /*
         * Combien de temps une reprise accepte d'attendre son tour.
         *
         * ⚠ C'est un plafond de **profondeur de file**, pas un réglage de latence : dès que
         * profondeur × durée de la section critique dépasse cette valeur, les reprises lèvent.
         */
        'wait' => 10,
    ],
];
