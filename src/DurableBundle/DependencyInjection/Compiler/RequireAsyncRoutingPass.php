<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Refuses a DBAL journal whose replays would run inside the web request that started them.
 *
 * With no routing, or a route to `sync://`, the bus handles a resume inline: the workflow replays in
 * the request, and a suspended execution dies with the process. Nothing fails while testing, since a
 * workflow that completes in one pass never has to survive anything (#259).
 *
 * The two messages that replay a workflow are checked. `ActivityMessage` is sent straight to
 * `activity_transport.transport_name`, not through the routing; signals and updates record
 * themselves and hand the replay to a resume.
 *
 * ponytail: a transport whose DSN is an env var is taken as asynchronous; FrameworkExtension makes
 * the same call when it decides which transports are `sync`.
 */
final class RequireAsyncRoutingPass implements CompilerPassInterface
{
    private const MESSAGES = [ResumeWorkflowMessage::class, FireWorkflowTimersMessage::class];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('durable.dbal.single_resume_lock') || !$container->hasDefinition('messenger.senders_locator')) {
            return;
        }

        /** @var array<string, list<string>> $routing */
        $routing = $container->getDefinition('messenger.senders_locator')->getArgument(0);

        foreach (self::MESSAGES as $message) {
            $senders = [];
            foreach (self::routingKeys($message) as $type) {
                // SendersLocator's rule: a wildcard is a fallback, skipped once a sender matched.
                if (str_ends_with($type, '*') && [] !== $senders) {
                    continue;
                }
                $senders = [...$senders, ...$routing[$type] ?? []];
            }

            if ([] === $senders || [] !== array_filter($senders, fn(string $sender): bool => $this->isSync($container, $sender))) {
                throw new \LogicException(\sprintf(
                    '`%s` is not routed to an asynchronous transport. A workflow would replay inside the web request that started it and die with the process. Route it to a Doctrine, AMQP or Redis transport in framework.messenger.routing.',
                    $message,
                ));
            }
        }
    }

    /**
     * The keys SendersLocator matches a message against, in its order: class, parents, interfaces,
     * namespace wildcards, `*`. The same list as HandlersLocator::listTypes(), which is internal.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function routingKeys(string $class): array
    {
        $keys = [$class, ...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])];
        for ($namespace = $class; false !== $i = strrpos($namespace, '\\');) {
            $namespace = substr($namespace, 0, $i);
            $keys[] = $namespace . '\\*';
        }

        return [...$keys, '*'];
    }

    private function isSync(ContainerBuilder $container, string $sender): bool
    {
        $id = $container->has('messenger.transport.' . $sender) ? 'messenger.transport.' . $sender : $sender;
        if (!$container->has($id)) {
            return false;
        }
        $definition = $container->findDefinition($id);
        $dsn = $definition->getArguments()[0] ?? null;

        return SyncTransport::class === $definition->getClass() || (\is_string($dsn) && str_starts_with($dsn, 'sync://'));
    }
}
