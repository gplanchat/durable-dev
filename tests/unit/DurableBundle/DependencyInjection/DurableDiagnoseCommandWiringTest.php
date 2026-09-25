<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `durable:execution:diagnose` knows when the metadata store is per process: on Temporal native only.
 * A DSN with `journal: false` keeps its metadata in SQL, where an empty row does mean an unknown id
 * (#337, B-12).
 *
 * @internal
 */
final class DurableDiagnoseCommandWiringTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default&tls=0';

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function backends(): iterable
    {
        yield 'Temporal native' => [['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]], true];
        yield 'in memory' => [[], false];
        yield 'DSN without the journal' => [[
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN, 'journal' => false],
        ], false];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('backends')]
    public function testTheCommandKnowsWhetherTheMetadataIsPerProcess(array $config, bool $processLocal): void
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        self::assertSame($processLocal, $container->findDefinition(DiagnoseExecutionCommand::class)->getArgument(5));
    }
}
