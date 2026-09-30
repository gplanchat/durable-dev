<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\EventListener\WarnOnSharedJournalConnectionListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Finds a DBAL journal on the application's default connection, and registers the warning
 * (DUR054, decision 6).
 *
 * In a pass rather than in the extension: `doctrine.dbal.default_connection` is an alias
 * DoctrineBundle sets, and the journal may name the same connection by its own id
 * (`doctrine.dbal.app_connection`). Only the merged container can tell the two apart.
 */
final readonly class WarnOnSharedJournalConnectionPass implements CompilerPassInterface
{
    private const DEFAULT_CONNECTION = 'doctrine.dbal.default_connection';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('durable.dbal.schema') || !$container->has('logger')) {
            return;
        }

        $journal = $container->getDefinition('durable.dbal.schema')->getArguments()[0] ?? null;
        if (!$journal instanceof Reference) {
            return;
        }

        $id = (string) $journal;
        if (self::resolve($container, $id) !== self::resolve($container, self::DEFAULT_CONNECTION)) {
            return;
        }

        // The class explicitly: this pass runs after ResolveClassPass, which infers it from the id.
        $container->register(WarnOnSharedJournalConnectionListener::class, WarnOnSharedJournalConnectionListener::class)
            ->setArguments([new Reference('logger'), $id])
            ->addTag('kernel.event_listener', ['event' => WorkerStartedEvent::class])
            ->setPublic(false)
        ;
    }

    private static function resolve(ContainerBuilder $container, string $id): string
    {
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }
}
