<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies that the Temporal DI wiring is correct when DURABLE_DSN is configured.
 *
 * This test catches regressions such as:
 * - a Temporal worker missing from `messenger:consume` (durable_workflows, durable_activities, durable_nexus)
 * - a worker service that fails to instantiate (workers exiting with code 1)
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
     * The three workers are the bundle's, consumed by name with nothing in messenger.yaml. A real
     * kernel is the only place MessengerPass turns the tagged services into those names.
     *
     * `messenger:stats` reads the same receiver locator as `messenger:consume`, without polling.
     */
    public function testTheBundleWorkersAreConsumableByName(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('messenger:stats'));
        $tester->execute(['transport_names' => ['durable_workflows', 'durable_activities', 'durable_nexus'], '--format' => 'json']);

        self::assertSame(
            ['durable_workflows', 'durable_activities', 'durable_nexus'],
            json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR)['uncountable_transports'] ?? [],
        );
    }
}
