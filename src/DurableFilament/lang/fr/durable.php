<?php

declare(strict_types=1);

return [
    'navigation' => 'Exécutions Durable',
    'backend' => [
        'checked' => ':name · vérifié à :time',
        'not_configured' => 'Aucun backend Durable lisible n’est configuré pour cette application.',
        'sql' => ['answers' => 'La base SQL répond.', 'unreachable' => 'La base SQL est injoignable : :error'],
        'database' => ['answers' => 'La base de données répond.', 'unreachable' => 'La base de données est injoignable : :error'],
        'temporal' => ['connected' => 'Connecté au namespace Temporal « :namespace ».', 'unreachable' => 'Le namespace Temporal « :namespace » est injoignable : :error'],
        'memory' => ['ephemeral' => 'Le catalogue en mémoire répond, mais il ne voit que les exécutions de ce processus. Une liste vide dit seulement qu’aucune exécution n’a tourné ici. Configurez un backend qui enregistre hors de ce processus, une base SQL ou un cluster Temporal, pour lire les exécutions de tous les autres.'],
    ],
    'outcomes_heading' => 'Issues des :count exécutions de cette page',
    'kpi' => [
        'waiting_for_worker' => 'En attente d’un worker',
    ],
    'workers' => [
        'state' => [
            'polled' => 'À l’écoute',
            'missing' => 'Personne à l’écoute',
            'unknown' => 'Impossible de demander',
        ],
        'polling' => 'Le worker :role est à l’écoute.',
        'missing' => 'Aucun worker :role n’a interrogé le backend depuis :seconds secondes : les exécutions s’arrêtent à leur première tâche :role. Démarrez php artisan durable:temporal-worker --role=:role.',
        'unknown' => 'Impossible de demander au backend si un worker :role est à l’écoute : :error',
        // Shown only as the :error of workers.unknown, after its colon: hence the lowercase.
        'queue_unlisted' => 'la file de Laravel ne tient aucune liste des processus qui lancent php artisan queue:work.',
    ],
    'status' => [
        'all' => 'Toutes',
        'running' => 'En cours',
        'completed' => 'Terminée',
        'failed' => 'En échec',
        'cancelled' => 'Annulée',
        'continued_as_new' => 'Poursuivie à neuf',
    ],
    'filter' => [
        'outcome' => 'Issue',
        'workflow_name' => 'Nom du workflow',
        'execution_id_prefix' => 'L’identifiant d’exécution commence par',
        'submit' => 'Filtrer',
    ],
    'runs' => [
        'title' => 'Exécutions de workflow',
        'empty' => 'Aucune exécution ne correspond à ce filtre.',
        'execution' => 'Exécution',
        'workflow' => 'Workflow',
        'status' => 'Issue',
        'started_at' => 'Démarrée',
        'notes' => 'Notes',
    ],
    'pagination' => [
        'first' => 'Première page',
        'next' => 'Page suivante',
    ],
    'run' => [
        'waiting_for_worker' => 'En attente d’un worker · :elapsed',
        'waiting_on' => 'En attente de :reason',
        'title' => 'Exécution de workflow',
        'back' => 'Retour aux exécutions',
        'not_found' => 'Aucune exécution de cet identifiant sur ce backend.',
        'workflow' => 'Workflow',
        'execution' => 'Exécution',
        'backend_run' => 'Exécution côté backend :id',
        'outcome' => 'Issue',
        'history' => 'Historique',
        'no_history' => 'Aucun historique enregistré pour cette exécution.',
    ],
    'frieze' => [
        'key_waiting' => 'Hachuré : en attente d’être pris en charge. Le travail avait été demandé, mais personne ne l’avait encore commencé.',
        'key_failed' => 'Rouge : ce qui a échoué. Une annulation n’est pas peinte en rouge : c’est une issue, pas une panne.',
    ],
    'phase' => [
        'requested' => 'demandé',
        'started' => 'pris en charge',
        'failed' => 'en échec',
        'settled' => 'réglé',
    ],
    'nexus' => [
        'title' => 'Opérations Nexus',
        'endpoint' => 'Endpoint',
        'service' => 'Service',
        'operation' => 'Opération',
        'state' => 'État',
        'states' => [
            'in_flight' => 'en cours',
            'completed' => 'terminée',
            'failed' => 'en échec',
            'timed_out' => 'expirée',
            'cancelled' => 'annulée',
        ],
    ],
];
