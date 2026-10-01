<?php

declare(strict_types=1);

return [
    'navigation' => 'Durable runs',
    'backend' => [
        'checked' => ':name · checked at :time',
    ],
    'outcomes_heading' => 'Outcomes across the :count runs on this page',
    'kpi' => [
        'waiting_for_worker' => 'Waiting for a worker',
    ],
    'status' => [
        'all' => 'All',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'continued_as_new' => 'Continued as new',
    ],
    'filter' => [
        'outcome' => 'Outcome',
        'workflow_name' => 'Workflow name',
        'execution_id_prefix' => 'Execution id starts with',
        'submit' => 'Filter',
    ],
    'runs' => [
        'title' => 'Workflow runs',
        'empty' => 'No workflow run matches this filter.',
        'execution' => 'Execution',
        'workflow' => 'Workflow',
        'status' => 'Outcome',
        'started_at' => 'Started',
        'notes' => 'Notes',
    ],
    'pagination' => [
        'first' => 'First page',
        'next' => 'Next page',
    ],
    'run' => [
        'title' => 'Workflow run',
        'back' => 'Back to the runs',
        'not_found' => 'No run with this id on this backend.',
        'workflow' => 'Workflow',
        'execution' => 'Execution',
        'backend_run' => 'Backend run :id',
        'outcome' => 'Outcome',
        'history' => 'History',
        'no_history' => 'No recorded history for this run.',
    ],
    'frieze' => [
        'key_waiting' => 'Hatched: waiting to be picked up — the work had been asked for, but nobody had started it yet.',
        'key_failed' => 'Red: what failed. A cancellation is not painted red — it is an outcome, not a breakdown.',
    ],
    'phase' => [
        'requested' => 'requested',
        'started' => 'started',
        'failed' => 'failed',
        'settled' => 'settled',
    ],
    'nexus' => [
        'title' => 'Nexus operations',
        'endpoint' => 'Endpoint',
        'service' => 'Service',
        'operation' => 'Operation',
        'state' => 'State',
        'states' => [
            'in_flight' => 'in flight',
            'completed' => 'completed',
            'failed' => 'failed',
            'timed_out' => 'timed out',
            'cancelled' => 'cancelled',
        ],
    ],
];
