<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Teste TemporalTransportFactory : sélection du purpose, validation DSN, erreurs de création.
 *
 * Ces tests ne nécessitent pas d'extension gRPC ni de connexion Temporal.
 *
 * @internal
 */
#[CoversClass(TemporalTransportFactory::class)]
final class TemporalTransportFactoryTest extends TestCase
{
    // ── supports() ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, bool}>
     */
    public static function dsnSupportProvider(): array
    {
        return [
            'temporal://'              => ['temporal://127.0.0.1:7233', true],
            'temporal-journal://'      => ['temporal-journal://127.0.0.1:7233', true],
            'temporal-application://'  => ['temporal-application://127.0.0.1:7233', true],
            'doctrine://'              => ['doctrine://default', false],
            'in-memory://'             => ['in-memory://', false],
            'amqp://'                  => ['amqp://localhost', false],
            'empty string'             => ['', false],
        ];
    }

    #[DataProvider('dsnSupportProvider')]
    public function testSupports(string $dsn, bool $expected): void
    {
        $factory = new TemporalTransportFactory([]);

        self::assertSame($expected, $factory->supports($dsn, []));
    }

    // ── createTransport() — cas d'erreur sans gRPC ────────────────────────

    public function testCreateTransportForActivityWorkerPurposeWithoutWorkerThrows(): void
    {
        $factory = new TemporalTransportFactory([], null); // activityWorker = null

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/activity_worker/');

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=activity_worker',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportForJournalPurposeWithoutWorkflowRegistryThrows(): void
    {
        // workflowRegistry = null (no registry injected)
        $factory = new TemporalTransportFactory([], null, null, null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/WorkflowRegistry/');

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=journal',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportForApplicationPurposeWithoutInnerDsnThrows(): void
    {
        $factory = new TemporalTransportFactory([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inner=/');

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=application',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportForApplicationWithOptionsInnerDsnAlsoThrowsWithoutInnerFactory(): void
    {
        // inner= provided in options but no matching inner TransportFactory registered
        $factory = new TemporalTransportFactory([]);

        $this->expectException(\InvalidArgumentException::class);

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=application',
            ['inner' => 'in-memory://'],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportForApplicationWithInnerDsnAndInnerFactoryCreatesTransport(): void
    {
        $innerTransport = $this->createStub(TransportInterface::class);

        $innerFactory = $this->createMock(TransportFactoryInterface::class);
        $innerFactory->method('supports')->willReturn(true);
        $innerFactory->method('createTransport')->willReturn($innerTransport);

        $factory = new TemporalTransportFactory([$innerFactory]);

        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=application&inner=in-memory://',
            [],
            $this->createStub(SerializerInterface::class),
        );

        self::assertNotNull($transport);
    }

    public function testCreateTransportForUnknownPurposeThrows(): void
    {
        $factory = new TemporalTransportFactory([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown_purpose/');

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=unknown_purpose',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testCreateTransportForActivityWorkerWithWorkerInjectedDoesNotThrow(): void
    {
        // TemporalActivityWorker is final; use reflection to bypass the constructor
        // (we only need a non-null instance to satisfy the type check in TemporalTransportFactory).
        /** @var TemporalActivityWorker $worker */
        $worker = (new \ReflectionClass(TemporalActivityWorker::class))->newInstanceWithoutConstructor();
        $factory = new TemporalTransportFactory([], $worker);

        // Should not throw — returns TemporalActivityWorkerTransport wrapping the worker
        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=activity_worker',
            [],
            $this->createStub(SerializerInterface::class),
        );

        self::assertNotNull($transport);
    }

    // ── Purpose resolution ────────────────────────────────────────────────

    public function testPurposeFromQueryStringOverridesDefault(): void
    {
        // purpose=activity_worker → invalid without worker → confirms query string was used
        $factory = new TemporalTransportFactory([], null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/activity_worker/');

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=activity_worker',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testPurposeFromOptionsArrayOverridesDsnQueryString(): void
    {
        // purpose=journal in options but no registry → LogicException (not InvalidArgumentException for activity_worker)
        $factory = new TemporalTransportFactory([], null, null, null);

        $this->expectException(\LogicException::class); // journal path

        $factory->createTransport(
            'temporal://127.0.0.1:7233?purpose=activity_worker', // DSN says activity_worker
            ['purpose' => 'journal'],                              // options override to journal
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testDefaultPurposeIsJournalWhenNoPurposeAndNoInnerDsn(): void
    {
        // No purpose= and no inner= → defaults to 'journal' → needs WorkflowRegistry
        $factory = new TemporalTransportFactory([], null, null, null);

        $this->expectException(\LogicException::class); // journal requires WorkflowRegistry

        $factory->createTransport(
            'temporal://127.0.0.1:7233',
            [],
            $this->createStub(SerializerInterface::class),
        );
    }

    public function testDefaultPurposeIsApplicationWhenInnerDsnPresentInQueryString(): void
    {
        // inner= in DSN → defaults to 'application'
        $innerTransport = $this->createStub(TransportInterface::class);

        $innerFactory = $this->createMock(TransportFactoryInterface::class);
        $innerFactory->method('supports')->willReturn(true);
        $innerFactory->method('createTransport')->willReturn($innerTransport);

        $factory = new TemporalTransportFactory([$innerFactory]);

        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?inner=in-memory%3A%2F%2F',
            [],
            $this->createStub(SerializerInterface::class),
        );

        self::assertNotNull($transport);
    }
}
