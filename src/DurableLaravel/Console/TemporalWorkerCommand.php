<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Illuminate\Console\Command;

/**
 * Le worker de tâches de workflow, pour le backend Temporal — et le seul que ce paquet ajoute.
 *
 * Sous `illuminate`, tout passe par `php artisan queue:work` et ce paquet n'ajoute aucune commande :
 * c'est la règle que §3.2 s'est donnée. Temporal la casse pour une raison qui lui appartient — ses
 * tâches de workflow ne sont pas dans la file de l'application, elles sont dans le cluster, et
 * personne d'autre ne peut les en sortir.
 *
 * La boucle est celle du pont, `WorkflowTaskProcessor::run()`, qui ne connaît aucun framework. Cette
 * commande ne fait que lui donner un critère d'arrêt et un endroit où l'exécuter.
 */
final class TemporalWorkerCommand extends Command
{
    protected $signature = 'durable:temporal-worker {--max-time=0 : Seconds to run before exiting, 0 for no limit}';

    protected $description = 'Drain Temporal workflow tasks for the durable workflows this application declares';

    public function handle(WorkflowTaskProcessor $processor): int
    {
        $maxTime = (int) $this->option('max-time');
        $deadline = $maxTime > 0 ? microtime(true) + $maxTime : null;

        $this->info($deadline === null
            ? 'Draining Temporal workflow tasks. Ctrl-C to stop.'
            : \sprintf('Draining Temporal workflow tasks for %d seconds.', $maxTime));

        $processor->run(static fn(): bool => $deadline === null || microtime(true) < $deadline);

        return self::SUCCESS;
    }
}
