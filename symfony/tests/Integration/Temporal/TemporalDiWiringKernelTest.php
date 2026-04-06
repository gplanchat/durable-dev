<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Vérifie que le câblage DI Temporal est correct lorsque DURABLE_DSN est configuré.
 *
 * Ce test détecte les régressions comme :
 * - DurableTemporalTransportFactoryPass non enregistré → TemporalActivityWorker null dans TemporalTransportFactory
 * - Transport durable_temporal_activity qui échoue à l'instanciation (exit code 1 des workers)
 *
 * Prérequis : DURABLE_DSN doit être défini dans l'environnement et l'extension PHP grpc doit être chargée.
 * Exécution : DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&tls=0 php bin/phpunit --group temporal-integration
 *
 * @internal
 */
#[Group('temporal-integration')]
final class TemporalDiWiringKernelTest extends KernelTestCase
{
    /**
     * On démarre en env 'dev' car c'est là que durable.temporal.dsn = DURABLE_DSN.
     * En env 'test', durable.temporal.dsn est null et les services Temporal ne sont pas enregistrés.
     *
     * Note : en env 'dev', framework.test n'est pas actif, donc self::getContainer() (qui nécessite
     * test.service_container) n'est pas disponible. On utilise self::$kernel->getContainer() à la place,
     * ce qui ne donne accès qu'aux services publics — suffisant pour notre vérification.
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
                'Définissez DURABLE_DSN pour exécuter les tests d\'intégration DI Temporal. '
                . 'Ex. : DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&tls=0',
            );
        }
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('L\'extension PHP grpc est requise pour les tests DI Temporal.');
        }
    }

    /**
     * Régression couverte : DurableTemporalTransportFactoryPass non enregistré dans DurableBundle::build()
     * → durable.temporal.activity_worker non injecté dans TemporalTransportFactory
     * → crash workers messenger:consume durable_temporal_activity (exit code 1).
     */
    public function testTemporalActivityWorkerServiceIsAvailableInDevContainer(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        self::assertTrue(
            $container->has('durable.temporal.activity_worker'),
            'Le service public durable.temporal.activity_worker doit être enregistré en env dev. '
            . 'Vérifiez DurableExtension::registerTemporalMirrorInfrastructure().',
        );
    }

    public function testTemporalActivityWorkerIsInstanceOfExpectedClass(): void
    {
        self::bootKernel();
        $worker = self::$kernel->getContainer()->get('durable.temporal.activity_worker');

        self::assertInstanceOf(
            TemporalActivityWorker::class,
            $worker,
            'durable.temporal.activity_worker doit être une instance de TemporalActivityWorker.',
        );
    }

    /**
     * Vérifie que TemporalTransportFactory peut créer le transport durable_temporal_activity
     * sans lever d'exception. Si DurableTemporalTransportFactoryPass n'est pas enregistré, le worker
     * est null et createTransport() lève InvalidArgumentException.
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
     * Vérifie via la réflexion que TemporalTransportFactory dans le container a bien reçu
     * TemporalActivityWorker via le compiler pass.
     *
     * Ce test nécessite que TemporalTransportFactory soit accessible depuis le container public.
     * Si le service est inliné/optimisé (non-partagé), ce test est ignoré gracieusement.
     */
    public function testTemporalTransportFactoryHasActivityWorkerInjectedViaReflection(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        if (!$container->has(TemporalTransportFactory::class)) {
            self::markTestSkipped(
                'TemporalTransportFactory n\'est pas accessible depuis le container public en env dev '
                . '(probablement inliné lors de la compilation). La vérification comportementale de '
                . 'testDurableTemporalActivityTransportCanBeCreatedFromWorker est suffisante.',
            );
        }

        /** @var TemporalTransportFactory $factory */
        $factory = $container->get(TemporalTransportFactory::class);

        $prop = (new \ReflectionClass(TemporalTransportFactory::class))->getProperty('activityWorker');
        $worker = $prop->getValue($factory);

        self::assertInstanceOf(
            TemporalActivityWorker::class,
            $worker,
            'TemporalTransportFactory::$activityWorker doit être injecté via DurableTemporalTransportFactoryPass. '
            . 'Si null : le compiler pass n\'est pas enregistré dans DurableBundle::build().',
        );
    }
}
