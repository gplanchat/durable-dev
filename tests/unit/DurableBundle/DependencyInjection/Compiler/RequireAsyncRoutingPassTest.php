<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RequireAsyncRoutingPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A resume left on `sync` replays inside the request that started it and dies with the process: the
 * app boots, the first workflow completes in development, and durability is gone (#259).
 */
#[CoversClass(RequireAsyncRoutingPass::class)]
final class RequireAsyncRoutingPassTest extends TestCase
{
    public function testAnUnroutedResumeIsRefused(): void
    {
        $container = $this->dbalContainer([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ResumeWorkflowMessage` is not routed to an asynchronous transport/');

        (new RequireAsyncRoutingPass())->process($container);
    }

    public function testATimerRoutedToSyncIsRefused(): void
    {
        $container = $this->dbalContainer([
            'Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['async'],
            'Gplanchat\Durable\Transport\FireWorkflowTimersMessage' => ['sync'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FireWorkflowTimersMessage` is not routed to an asynchronous transport/');

        (new RequireAsyncRoutingPass())->process($container);
    }

    public function testANamespaceWildcardToAnAsyncTransportIsEnough(): void
    {
        $container = $this->dbalContainer(['Gplanchat\Durable\*' => ['async']]);

        (new RequireAsyncRoutingPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /**
     * SendersLocator falls back to a wildcard only when nothing more specific matched: a catch-all
     * `sync` route leaves the exact async routes in charge.
     */
    public function testACatchAllSyncRouteDoesNotOverrideExactAsyncRoutes(): void
    {
        $container = $this->dbalContainer([
            'Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['async'],
            'Gplanchat\Durable\Transport\FireWorkflowTimersMessage' => ['async'],
            '*' => ['sync'],
        ]);

        (new RequireAsyncRoutingPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testACatchAllSyncRouteAloneIsRefused(): void
    {
        $container = $this->dbalContainer(['*' => ['sync']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ResumeWorkflowMessage` is not routed/');

        (new RequireAsyncRoutingPass())->process($container);
    }

    public function testWithoutTheDbalJournalThePassSaysNothing(): void
    {
        // In memory, nothing survives the process anyway: `sync` is the honest choice there.
        $container = $this->dbalContainer([]);
        $container->removeDefinition('durable.dbal.single_resume_lock');

        (new RequireAsyncRoutingPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, list<string>> $routing what FrameworkExtension writes from framework.messenger.routing
     */
    private function dbalContainer(array $routing): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('durable.dbal.single_resume_lock', \stdClass::class);
        $container->register('messenger.senders_locator', \stdClass::class)->setArguments([$routing]);
        foreach (['async' => 'doctrine://default', 'sync' => 'sync://'] as $name => $dsn) {
            $container->setDefinition('messenger.transport.' . $name, (new Definition(TransportInterface::class))
                ->setFactory([new Reference('messenger.transport_factory'), 'createTransport'])
                ->setArguments([$dsn, ['transport_name' => $name], new Reference('messenger.default_serializer')]));
        }

        return $container;
    }
}
