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

/**
 * Servir des opérations Nexus depuis une application Laravel.
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
        // Le refus vient du cœur, et il arrive à l'enregistrement — pas au premier appel, quand
        // l'application est en production et qu'un appelant attend une réponse.
        $app = $this->container('illuminate', [BillingHandler::class => BillingService::class]);
        (new DurableServiceProvider($app))->register();

        $this->expectException(\Throwable::class);

        $app->make(NexusOperationRegistry::class);
    }

    public function testAnApplicationThatServesNothingGetsARegistryAnyway(): void
    {
        // Sans gestionnaire déclaré, rien ne doit rougir : appeler une opération Nexus ne se
        // déclare pas ici, et c'est le cas le plus courant.
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

    public function testTheNexusWorkerIsAssembledUnderTemporal(): void
    {
        $app = $this->container('temporal', []);
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(TemporalNexusWorker::class, $app->make(TemporalNexusWorker::class));
    }

    /** @param array<class-string, class-string> $handlers */
    private function container(string $backend, array $handlers): Container
    {
        $app = new Container();
        $durable = ['backend' => $backend, 'workflows' => [], 'nexus' => ['handlers' => $handlers]];
        if ('temporal' === $backend) {
            $durable['temporal'] = ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test'
                . '&journal_task_queue=durable-journal&activity_task_queue=durable-activities'];
        }
        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));

        return $app;
    }
}
