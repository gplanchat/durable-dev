<?php

declare(strict_types=1);

return [
    'navigation' => 'Exécutions Durable',
    'backend' => [
        'checked' => ':name · vérifié à :time',
    ],
    'outcomes_heading' => 'Issues des :count exécutions de cette page',
    'kpi' => [
        'waiting_for_worker' => 'En attente d’un worker',
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
