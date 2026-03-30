<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Messenger\TemporalApplicationTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * @internal
 */
#[CoversClass(TemporalTransportFactory::class)]
#[CoversClass(TemporalConnection::class)]
#[CoversClass(TemporalApplicationTransport::class)]
#[CoversClass(TemporalJournalTransport::class)]
final class TemporalTransportFactoryTest extends TestCase
{
    #[Test]
    public function itShouldSupportTemporalSchemes(): void
    {
        $factory = new TemporalTransportFactory([]);

        self::assertTrue($factory->supports('temporal://127.0.0.1:7233', []));
        self::assertTrue($factory->supports('temporal-journal://127.0.0.1:7233', []));
        self::assertTrue($factory->supports('temporal-application://127.0.0.1:7233?inner=in-memory://', []));
        self::assertFalse($factory->supports('redis://localhost', []));
    }

    #[Test]
    public function itShouldParseConnectionFromDsnWithInner(): void
    {
        $connection = TemporalConnection::fromDsn(
            'temporal://temporal:7233?namespace=ns1&inner=in-memory://&workflow_task_queue=wf&activity_task_queue=act&tls=0',
        );

        self::assertSame('temporal:7233', $connection->target);
        self::assertSame('ns1', $connection->namespace);
        self::assertSame('in-memory://', $connection->innerMessengerDsn);
        self::assertSame('wf', $connection->workflowTaskQueue);
        self::assertSame('act', $connection->activityTaskQueue);
        self::assertFalse($connection->tls);
    }

    #[Test]
    public function itShouldNormalizeLegacyApplicationSchemeInFromDsn(): void
    {
        $connection = TemporalConnection::fromDsn(
            'temporal-application://temporal:7233?namespace=ns1&inner=in-memory://',
        );

        self::assertSame('temporal:7233', $connection->target);
        self::assertSame('in-memory://', $connection->innerMessengerDsn);
    }

    #[Test]
    public function itShouldRejectNestedTemporalInnerInFromDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal://127.0.0.1:7233?inner=temporal://x');
    }

    #[Test]
    public function itShouldThrowWhenPurposeApplicationWithoutInner(): void
    {
        $factory = new TemporalTransportFactory([new InMemoryTransportFactory()]);
        $serializer = new PhpSerializer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inner');

        $factory->createTransport(
            'temporal://127.0.0.1:7233',
            ['purpose' => 'application'],
            $serializer,
        );
    }

    #[Test]
    public function itShouldCreateApplicationTransportDelegatingToInner(): void
    {
        $factory = new TemporalTransportFactory([new InMemoryTransportFactory()]);
        $serializer = new PhpSerializer();
        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?inner=in-memory://',
            [],
            $serializer,
        );

        self::assertInstanceOf(TemporalApplicationTransport::class, $transport);
        self::assertSame('127.0.0.1:7233', $transport->getConnection()->target);
    }

    #[Test]
    public function itShouldCreateApplicationTransportWithOptionsInner(): void
    {
        $factory = new TemporalTransportFactory([new InMemoryTransportFactory()]);
        $serializer = new PhpSerializer();
        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233',
            ['purpose' => 'application', 'inner' => 'in-memory://'],
            $serializer,
        );

        self::assertInstanceOf(TemporalApplicationTransport::class, $transport);
    }

    #[Test]
    public function itShouldCreateJournalTransportWhenNoInnerAndDefaultPurpose(): void
    {
        if (!\extension_loaded('grpc')) {
            self::markTestSkipped('PHP extension grpc is required for TemporalJournalTransport.');
        }

        $factory = new TemporalTransportFactory([new InMemoryTransportFactory()]);
        $serializer = new PhpSerializer();
        $transport = $factory->createTransport('temporal://127.0.0.1:7233', [], $serializer);

        self::assertInstanceOf(TemporalJournalTransport::class, $transport);
    }

    #[Test]
    public function itShouldPreferExplicitPurposeJournalOverInnerInDsn(): void
    {
        if (!\extension_loaded('grpc')) {
            self::markTestSkipped('PHP extension grpc is required for TemporalJournalTransport.');
        }

        $factory = new TemporalTransportFactory([new InMemoryTransportFactory()]);
        $serializer = new PhpSerializer();
        $transport = $factory->createTransport(
            'temporal://127.0.0.1:7233?inner=in-memory://',
            ['purpose' => 'journal'],
            $serializer,
        );

        self::assertInstanceOf(TemporalJournalTransport::class, $transport);
    }
}
