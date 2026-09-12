<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\BillingHandler;
use unit\DurableLaravel\Fixtures\BillingService;
use unit\DurableLaravel\Fixtures\DeferredBillingHandler;
use unit\DurableLaravel\Fixtures\DeferredBillingService;
use unit\DurableLaravel\Fixtures\MistypedSettleWorkflow;
use unit\DurableLaravel\Fixtures\SettleWorkflow;

/**
 * Serving Nexus operations from a Laravel application.
 */
final class NexusOnLaravelTest extends TestCase
{
    public function testTheRegistryRoutesWhenTheBackendIsTemporal(): void
    {
        $app = $this->container('temporal', [BillingHandler::class => BillingService::class]);
        (new DurableServiceProvider($app))->register();

        $registry = $app->make(NexusOperationRegistry::class);

        self::assertTrue($registry->serves(NexusService::named('billing'), NexusOperationName::named('charge')));
    }

    public function testADeclaredHandlerIsRefusedOnABackendThatCannotRoute(): void
    {
        // The refusal comes from the core, and it lands at registration — not on the first call,
        // when the application is in production and a caller is waiting for an answer.
        $app = $this->container('illuminate', [BillingHandler::class => BillingService::class]);
        (new DurableServiceProvider($app))->register();

        $this->expectException(\Throwable::class);

        $app->make(NexusOperationRegistry::class);
    }

    public function testAnApplicationThatServesNothingGetsARegistryAnyway(): void
    {
        // With no handler declared, nothing must go red: calling a Nexus operation is not
        // declared here, and that is the most common case.
        $app = $this->container('illuminate', []);
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(NexusOperationRegistry::class, $app->make(NexusOperationRegistry::class));
    }

    public function testAContractThatIsNotAnInterfaceSaysWhichKeyIsWhich(): void
    {
        $app = $this->container('temporal', [BillingHandler::class => 'App\\Nope']);
        (new DurableServiceProvider($app))->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no such interface exists');
        $this->expectExceptionMessage('the value is the contract');

        $app->make(NexusOperationRegistry::class);
    }

    public function testAHandlerThatServesNothingIsRefused(): void
    {
        $app = $this->container('temporal', [\stdClass::class => BillingService::class]);
        (new DurableServiceProvider($app))->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serves none of the operations');

        $app->make(NexusOperationRegistry::class);
    }

    public function testAWorkflowCoversTheOperationTheHandlerHasNoMethodFor(): void
    {
        $app = $this->container(
            'temporal',
            [DeferredBillingHandler::class => DeferredBillingService::class],
            [SettleWorkflow::class],
        );
        (new DurableServiceProvider($app))->register();

        $registry = $app->make(NexusOperationRegistry::class);

        self::assertTrue($registry->serves(NexusService::named('deferred-billing'), NexusOperationName::named('settle')));
    }

    public function testAWorkflowWhoseParameterNamesDoNotMatchTheContractIsRefused(): void
    {
        // The breakdown this refusal replaces is a silent one: the payload is keyed by parameter
        // name at both ends, so `$ammount` would silently receive null without any error being
        // raised. Symfony refuses from its compiler pass; a host that reads a configuration file
        // has to refuse at the same moment — at registration, not on the first call.
        $app = $this->container(
            'temporal',
            [DeferredBillingHandler::class => DeferredBillingService::class],
            [MistypedSettleWorkflow::class],
        );
        (new DurableServiceProvider($app))->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\$ammount/');
        $this->expectExceptionMessageMatches('/silently receive null/');

        $app->make(NexusOperationRegistry::class);
    }

    public function testAnOptionalExtraParameterIsAllowed(): void
    {
        // `$dryRun` is not on the contract, but it has a default value: its absence is a
        // decision, not an oversight.
        $app = $this->container(
            'temporal',
            [DeferredBillingHandler::class => DeferredBillingService::class],
            [SettleWorkflow::class],
        );
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(NexusOperationRegistry::class, $app->make(NexusOperationRegistry::class));
    }

    public function testTheNexusWorkerIsAssembledUnderTemporal(): void
    {
        $app = $this->container('temporal', []);
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(TemporalNexusWorker::class, $app->make(TemporalNexusWorker::class));
    }

    /**
     * @param array<class-string, class-string> $handlers
     * @param list<class-string>                $workflows
     */
    private function container(string $backend, array $handlers, array $workflows = []): Container
    {
        $app = new Container();
        $durable = ['backend' => $backend, 'workflows' => $workflows, 'nexus' => ['handlers' => $handlers]];
        if ('temporal' === $backend) {
            $durable['temporal'] = ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test'
                . '&journal_task_queue=durable-journal&activity_task_queue=durable-activities'];
        }
        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));

        return $app;
    }
}
