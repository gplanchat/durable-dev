<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * `backend:` names where the journal lives; `event_store.type`, the two store types and
 * `temporal.journal` follow from it (#334). The old keys still work for one version, and a
 * combination with two sources of truth is refused while the tree is processed, with its path.
 */
final class ConfigurationBackendTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=demo&tls=0';

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}>
     */
    public static function forbidden(): iterable
    {
        yield 'temporal without a DSN' => [[['backend' => 'temporal']], 'backend "temporal" needs temporal.dsn'];
        yield 'temporal with a dbal event store' => [[['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN], 'event_store' => ['type' => 'dbal']]], 'event_store.type "dbal" contradicts backend "temporal"'];
        yield 'dbal with an in-memory event store' => [[['backend' => 'dbal', 'event_store' => ['type' => 'in_memory']]], 'event_store.type "in_memory" contradicts backend "dbal"'];
        yield 'in_memory with a dbal event store' => [[['backend' => 'in_memory', 'event_store' => ['type' => 'dbal']]], 'event_store.type "dbal" contradicts backend "in_memory"'];
        yield 'dbal handing the journal to the cluster' => [[['backend' => 'dbal', 'temporal' => ['dsn' => self::DSN, 'journal' => true]]], 'temporal.journal: true contradicts backend "dbal"'];
        yield 'in_memory handing the journal to the cluster' => [[['backend' => 'in_memory', 'temporal' => ['dsn' => self::DSN, 'journal' => true]]], 'temporal.journal: true contradicts backend "in_memory"'];
        yield 'temporal keeping the journal away' => [[['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN, 'journal' => false]]], 'temporal.journal: false contradicts backend "temporal"'];
        yield 'legacy keys, two sources of truth' => [[['event_store' => ['type' => 'dbal'], 'temporal' => ['dsn' => self::DSN]]], 'mutually exclusive'];
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    #[DataProvider('forbidden')]
    public function testAForbiddenCombinationIsRefusedWithItsPath(array $configs, string $message): void
    {
        try {
            $this->process($configs);
            self::fail('The configuration was supposed to be refused.');
        } catch (InvalidConfigurationException $refusal) {
            self::assertStringContainsString('path "durable"', $refusal->getMessage());
            self::assertStringContainsString($message, $refusal->getMessage());
        }
    }

    /**
     * Every profile the benches ship, in the old keys: they keep building for one version.
     *
     * @return iterable<string, array{list<array<string, mixed>>, string, bool}>
     */
    public static function legacy(): iterable
    {
        $inMemory = ['event_store' => ['type' => 'in_memory'], 'workflow_metadata' => ['type' => 'in_memory'], 'child_workflow' => ['parent_link_store' => ['type' => 'in_memory']]];
        $dbal = ['event_store' => ['type' => 'dbal'], 'workflow_metadata' => ['type' => 'dbal'], 'child_workflow' => ['parent_link_store' => ['type' => 'dbal']]];

        yield 'nothing at all' => [[[]], 'in_memory', false];
        yield 'Symfony bench, default and test' => [[$inMemory + ['temporal' => ['dsn' => null]]], 'in_memory', false];
        yield 'Symfony bench, dev and prod' => [[$inMemory, ['temporal' => ['dsn' => '%env(DURABLE_DSN)%']]], 'temporal', true];
        yield 'Sylius, default' => [[$dbal], 'dbal', false];
        yield 'Sylius, demo: the cluster serves Nexus, SQL keeps the journal' => [[$dbal, ['temporal' => ['dsn' => self::DSN, 'journal' => false]]], 'dbal', false];
        yield 'Sylius, demo_caller' => [[$dbal, $inMemory + ['temporal' => ['dsn' => self::DSN, 'journal' => true]]], 'temporal', true];
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    #[DataProvider('legacy')]
    public function testTheBackendIsDerivedFromTheOldKeys(array $configs, string $backend, bool $journal): void
    {
        $config = @$this->process($configs);

        self::assertSame($backend, $config['backend']);
        self::assertSame($journal, $config['temporal']['journal']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, bool}>
     */
    public static function backends(): iterable
    {
        yield 'in_memory' => [['backend' => 'in_memory'], 'in_memory', false];
        yield 'dbal' => [['backend' => 'dbal'], 'dbal', false];
        yield 'dbal serving Nexus' => [['backend' => 'dbal', 'temporal' => ['dsn' => self::DSN]], 'dbal', false];
        yield 'temporal' => [['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]], 'in_memory', true];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('backends')]
    public function testTheBackendSetsTheStoresAndTheJournal(array $input, string $stores, bool $journal): void
    {
        $config = $this->process([$input]);

        self::assertSame($stores, $config['event_store']['type']);
        self::assertSame($stores, $config['workflow_metadata']['type']);
        self::assertSame($stores, $config['child_workflow']['parent_link_store']['type']);
        self::assertSame($journal, $config['temporal']['journal']);
    }

    public function testTheOldKeysAreDeprecated(): void
    {
        $this->expectUserDeprecationMessage('Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.event_store.type" option is deprecated: set durable.backend instead.');

        $this->process([['event_store' => ['type' => 'dbal']]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }
}
