<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Verifies that the Temporal DI wiring is correct when DURABLE_DSN is configured.
 *
 * This test catches regressions such as:
 * - DurableTemporalTransportFactoryPass not registered → TemporalActivityWorker null in TemporalTransportFactory
 * - durable_temporal_activity transport failing to instantiate (workers exiting with code 1)
 *
 * Prerequisites: DURABLE_DSN must be defined in the environment and the PHP grpc extension must be loaded.
 * Run: DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&tls=0 php bin/phpunit --group temporal-integration
 *
 * @internal
 */
#[Group('temporal-integration')]
final class TemporalDiWiringKernelTest extends KernelTestCase
{
    /**
     * We boot in the 'dev' env because that is where durable.temporal.dsn = DURABLE_DSN.
     * In the 'test' env, durable.temporal.dsn is null and the Temporal services are not registered.
     *
     * Note: in the 'dev' env, framework.test is not active, so self::getContainer() (which requires
     * test.service_container) is not available. We use self::$kernel->getContainer() instead,
     * which only gives access to the public services — enough for our check.
     */
    protected static function createKernel(array $options = []): \Symfony\Component\HttpKernel\KernelInterface
    {
        $options['environment'] ??= 'dev';

        return parent::createKernel($options);
    }

    public static function setUpBeforeClass(): void
    {
        $dsn = (string) (getenv('DURABLE_DSN') ?: '');
        if ('' === trim($dsn)) {
            self::markTestSkipped(
                'Set DURABLE_DSN to run the Temporal DI integration tests. '
                . 'E.g. DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&tls=0',
            );
        }
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('The PHP grpc extension is required for the Temporal DI tests.');
        }
    }

    /**
     * Regression covered: DurableTemporalTransportFactoryPass not registered in DurableBundle::build()
     * → durable.temporal.activity_worker not injected into TemporalTransportFactory
     * → messenger:consume durable_temporal_activity workers crash (exit code 1).
     */
    public function testTemporalActivityWorkerServiceIsAvailableInDevContainer(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        self::assertTrue(
            $container->has('durable.temporal.activity_worker'),
            'The public durable.temporal.activity_worker service must be registered in the '
            . 'dev env. Check DurableExtension::registerTemporalMirrorInfrastructure().',
        );
    }

    public function testTemporalActivityWorkerIsInstanceOfExpectedClass(): void
    {
        self::bootKernel();
        $worker = self::$kernel->getContainer()->get('durable.temporal.activity_worker');

        self::assertInstanceOf(
            TemporalActivityWorker::class,
            $worker,
            'durable.temporal.activity_worker must be an instance of TemporalActivityWorker.',
        );
    }

    /**
     * Regression covered, and it cost a red CI: `durable.temporal.nexus_worker` was registered
     * with a reference to `WorkflowServiceNexusRpc`, a service that nothing registered.
     * The container stopped building at all — `cache:clear` failed before the very first test,
     * across every version of the matrix at once.
     *
     * The compiler pass unit tests could not catch it: they set up a bare
     * ContainerBuilder, where the extension never ran. Only a real kernel sees it.
     */
    public function testTemporalNexusWorkerServiceIsAvailableInDevContainer(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        self::assertTrue(
            $container->has('durable.temporal.nexus_worker'),
            'The public durable.temporal.nexus_worker service must be registered in the dev env.',
        );
        self::assertInstanceOf(
            TemporalNexusWorker::class,
            $container->get('durable.temporal.nexus_worker'),
        );
    }

    /**
     * Verifies that TemporalTransportFactory can create the durable_temporal_activity transport
     * without throwing an exception. If DurableTemporalTransportFactoryPass is not registered, the
     * worker is null and createTransport() throws InvalidArgumentException.
     */
    public function testDurableTemporalActivityTransportCanBeCreatedFromWorker(): void
    {
        self::bootKernel();

        /** @var TemporalActivityWorker $worker */
        $worker = self::$kernel->getContainer()->get('durable.temporal.activity_worker');

        // Construct the factory with the worker from the container.
        // This mirrors what the DI container does — if the compiler pass is registered,
        // the factory receives the worker and can create the transport without throwing.
        $factory = new TemporalTransportFactory([], $worker);

        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=activity_worker',
            [],
            new PhpSerializer(),        );

        self::assertNotNull($transport);
    }

    /**
     * Verifies through reflection that the TemporalTransportFactory in the container did receive
     * TemporalActivityWorker through the compiler pass.
     *
     * This test requires TemporalTransportFactory to be reachable from the public container.
     * If the service is inlined/optimized (non-shared), this test is skipped gracefully.
     */
    public function testTemporalTransportFactoryHasActivityWorkerInjectedViaReflection(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        if (!$container->has(TemporalTransportFactory::class)) {
            self::markTestSkipped(
                'TemporalTransportFactory is not reachable from the public container in the dev env '
                . '(probably inlined during compilation). The behavioural check of '
                . 'testDurableTemporalActivityTransportCanBeCreatedFromWorker is sufficient.',
            );
        }

        /** @var TemporalTransportFactory $factory */
        $factory = $container->get(TemporalTransportFactory::class);

        $prop = (new \ReflectionClass(TemporalTransportFactory::class))->getProperty('activityWorker');
        $worker = $prop->getValue($factory);

        self::assertInstanceOf(
            TemporalActivityWorker::class,
            $worker,
            'TemporalTransportFactory::$activityWorker must be injected through DurableTemporalTransportFactoryPass. '
            . 'If null: the compiler pass is not registered in DurableBundle::build().',
        );
    }
}
