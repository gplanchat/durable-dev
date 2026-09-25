<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What runs Durable over Symfony Messenger: the activity transport, the resume and timer dispatchers and their handlers.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class MessengerServices
{
    /**
     * @param array<string, mixed> $config
     */
    public static function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';
        $isTemporalNative = DurableExtension::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register(ActivityTransportInterface::class, NoopActivityTransport::class)->setPublic(true);

            return;
        }

        if ('messenger' === $type) {
            $transportName = $transportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityTransportInterface::class, MessengerActivityTransport::class)
                ->setArguments([
                    new Reference('messenger.transport.' . $transportName),
                    new Reference('messenger.transport.' . $transportName),
                ])
                ->setPublic(true)
            ;

            return;
        }

        $container->register(ActivityTransportInterface::class, InMemoryActivityTransport::class)->setPublic(true);
    }
}
