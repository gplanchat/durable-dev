<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Insère WorkflowRunDispatchProfilerMiddleware dans chaque bus Messenger via le paramètre « busId ».middleware
 * (avant MessengerPass du FrameworkBundle).
 */
final class RegisterWorkflowDispatchProfilerMiddlewarePass implements CompilerPassInterface
{
    private const MIDDLEWARE_ENTRY = ['id' => 'durable.messenger.middleware.workflow_run_dispatch_profiler'];

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $param = $busId.'.middleware';
            if (!$container->hasParameter($param)) {
                continue;
            }

            $middleware = $container->getParameter($param);
            if (!\is_array($middleware)) {
                continue;
            }

            if ($this->isTraceableFirst($middleware)) {
                array_splice($middleware, 1, 0, [self::MIDDLEWARE_ENTRY]);
            } else {
                array_unshift($middleware, self::MIDDLEWARE_ENTRY);
            }

            $container->setParameter($param, $middleware);
        }
    }

    /**
     * @param list<array{id?: string, arguments?: array<int, mixed>}> $middleware
     */
    private function isTraceableFirst(array $middleware): bool
    {
        return isset($middleware[0]['id']) && 'traceable' === $middleware[0]['id'];
    }
}
