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

    'tables' => [
        'events' => 'durable_events',
        'metadata' => 'durable_workflow_metadata',
        'parent_links' => 'durable_child_workflow_parent_link',
        'runs' => 'durable_workflow_runs',
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
         * Combien de temps une reprise accepte d'attendre son tour.
         *
         * ⚠ C'est un plafond de **profondeur de file**, pas un réglage de latence : dès que
         * profondeur × durée de la section critique dépasse cette valeur, les reprises lèvent.
         */
        'wait' => 10,
    ],
];
