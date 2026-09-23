<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Under Temporal the bundle registers the workers itself, from `durable.temporal.dsn`, and
 * `messenger:consume` finds them by alias. A transport the application still declares under one
 * of those names, or under a former `durable_temporal_*` name, is refused here with what to do.
 *
 * Runs after NexusHandlerPass, which decides whether `durable_nexus` exists at all.
 *
 * @see https://github.com/gplanchat/durable-dev/issues/420
 */
final class TemporalReceiversPass implements CompilerPassInterface
{
    private const RECEIVERS = [
        'durable.temporal.workflows_receiver',
        'durable.temporal.activities_receiver',
        'durable.temporal.nexus_receiver',
    ];

    // ponytail: matched by name only; a former transport renamed by the application reaches
    // Symfony's own "No transport supports the given Messenger DSN" instead.
    private const FORMER = [
        'durable_temporal_journal' => ['durable_workflows', 'durable.temporal.workflows_receiver'],
        'durable_temporal_activity' => ['durable_activities', 'durable.temporal.activities_receiver'],
        'durable_temporal_nexus' => ['durable_nexus', 'durable.temporal.nexus_receiver'],
    ];

    public function process(ContainerBuilder $container): void
    {
        $temporal = false;
        foreach (self::RECEIVERS as $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }
            $temporal = true;
            foreach ($container->getDefinition($id)->getTag('messenger.receiver') as $tag) {
                if ($container->hasDefinition('messenger.transport.' . $tag['alias'])) {
                    throw new \LogicException(\sprintf(
                        'The Messenger transport "%1$s" collides with the Temporal worker the Durable bundle registers under that name from durable.temporal.dsn: remove it from framework.messenger.transports, along with any routing to it. Consume the worker with "messenger:consume %1$s".',
                        $tag['alias'],
                    ));
                }
            }
        }

        if (!$temporal) {
            return;
        }

        foreach (self::FORMER as $former => [$current, $receiver]) {
            if (!$container->hasDefinition('messenger.transport.' . $former)) {
                continue;
            }
            // `journal: false`: no workflow or activity worker here, the application's own
            // transports of that name run the workflows locally.
            if (!$container->hasDefinition($receiver)) {
                throw new \LogicException(\sprintf(
                    'The Messenger transport "%s" is no longer supported: with durable.temporal.journal: false, workflows run locally over the application\'s own Messenger transports. Remove it from framework.messenger.transports.',
                    $former,
                ));
            }

            throw new \LogicException(\sprintf(
                'The Messenger transport "%s" is no longer needed: the Durable bundle registers the Temporal workers itself from durable.temporal.dsn. Remove it from framework.messenger.transports and consume "%s" instead.',
                $former,
                $current,
            ));
        }
    }
}
