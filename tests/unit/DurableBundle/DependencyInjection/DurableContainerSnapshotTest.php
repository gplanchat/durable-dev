<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use unit\DurableBundle\Fixtures\FirstContract;

/**
 * The whole container the extension builds, pinned in one reviewable file (#342).
 *
 * Moving the extension's registrations into loader files must not change what they register: every
 * such commit leaves `Fixtures/container-snapshot.txt` byte-identical, and a commit that means to
 * change the container shows its change there, next to its reason.
 *
 * Twelve variants, one marker each, uppercase with the profiler on and lowercase with it off. A line
 * is prefixed with the variants that register it exactly so. Each configuration branch of the
 * extension is taken on both of its sides by at least one variant:
 * - I in_memory, every default: in-memory activity transport, no contracts, the bundle's own aliases;
 * - D dbal, with the Messenger activity transport and an activity contract (the cache warmer);
 * - T temporal, with an application Guzzle client and an activity contract;
 * - N dbal with a Temporal DSN (the cluster serves Nexus, the journal stays in SQL), over PSR-18;
 * - L the legacy mixed path, a DBAL journal and in-memory metadata (deprecated keys; it goes when they do);
 * - A in_memory where the application already aliased the payload redactor and the observer.
 *
 * The one registration that depends on installed packages, the DBAL schema listener
 * (`class_exists` on doctrine/orm), is kept: doctrine/orm is a root dev dependency on every CI lane.
 *
 * After an intended change: DURABLE_UPDATE_SNAPSHOT=1 vendor/bin/phpunit --filter DurableContainerSnapshotTest
 */
final class DurableContainerSnapshotTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/container-snapshot.txt';
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default';

    public function testTheContainerIsTheSnapshot(): void
    {
        $contracts = ['activity_contracts' => ['contracts' => [FirstContract::class]]];
        $variants = [
            'I' => [['backend' => 'in_memory'], []],
            'D' => [['backend' => 'dbal', 'activity_transport' => ['type' => 'messenger']] + $contracts, []],
            'T' => [['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN, 'guzzle_client' => 'app.guzzle']] + $contracts, []],
            'N' => [['backend' => 'dbal', 'temporal' => ['dsn' => self::DSN, 'psr18_client' => 'app.psr18']], []],
            'L' => [['event_store' => ['type' => 'dbal']], []],
            'A' => [['backend' => 'in_memory'], [PayloadRedactorInterface::class => 'app.redactor', WorkflowExecutionObserverInterface::class => 'app.observer']],
        ];

        $lines = [];
        foreach ([true, false] as $debug) {
            foreach ($variants as $marker => [$config, $applicationAliases]) {
                $marker = $debug ? $marker : strtolower($marker);
                foreach (self::dump($config, $debug, $applicationAliases) as $line) {
                    $lines[$line][] = $marker;
                }
            }
        }

        $order = 'IDTNLAidtnla';
        $rows = [];
        foreach ($lines as $line => $markers) {
            $rows[] = implode('', array_map(static fn(string $m): string => \in_array($m, $markers, true) ? $m : '.', str_split($order))) . ' ' . $line;
        }
        usort($rows, static fn(string $a, string $b): int => [substr($a, 13), $a] <=> [substr($b, 13), $b]);
        $snapshot = implode("\n", $rows) . "\n";

        if ('1' === getenv('DURABLE_UPDATE_SNAPSHOT')) {
            file_put_contents(self::FIXTURE, $snapshot);
        }

        self::assertSame((string) file_get_contents(self::FIXTURE), $snapshot, 'the container changed; if that is meant, regenerate the snapshot (see the class docblock) and commit its diff');
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $applicationAliases aliases the application's own config set first
     *
     * @return list<string>
     */
    private static function dump(array $config, bool $debug, array $applicationAliases): array
    {
        $container = new ContainerBuilder();
        foreach (['kernel.debug' => $debug, 'kernel.environment' => 'test', 'kernel.project_dir' => '/project', 'kernel.cache_dir' => '/project/var/cache', 'kernel.build_dir' => '/project/var/cache', 'kernel.bundles' => [], 'kernel.bundles_metadata' => []] as $name => $value) {
            $container->setParameter($name, $value);
        }
        foreach ($applicationAliases as $alias => $target) {
            $container->setAlias($alias, $target);
        }
        // The application's aliases stay out of $before: their final state is what variant A watches.
        $before = [...array_keys($container->getDefinitions()), ...array_diff(array_keys($container->getAliases()), array_keys($applicationAliases)), ...array_keys($container->getParameterBag()->all())];

        (new DurableExtension())->load([$config], $container);

        $lines = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!\in_array($id, $before, true)) {
                $lines[] = 'def   ' . $id . ' ' . self::definition($definition);
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if (!\in_array($id, $before, true)) {
                $lines[] = 'alias ' . $id . ' -> ' . (string) $alias . ' ' . self::visibility($alias);
            }
        }
        foreach ($container->getParameterBag()->all() as $name => $value) {
            if (!\in_array($name, $before, true)) {
                $lines[] = 'param ' . $name . ' = ' . self::value($value);
            }
        }

        return $lines;
    }

    private static function definition(Definition $definition): string
    {
        $parts = ['class=' . ($definition->getClass() ?? '-'), self::visibility($definition)];
        if (!$definition->isShared()) {
            $parts[] = 'not-shared';
        }
        if ($definition->isLazy()) {
            $parts[] = 'lazy';
        }
        if (null !== $factory = $definition->getFactory()) {
            $parts[] = 'factory=' . self::value($factory);
        }
        $parts[] = 'args=' . self::value($definition->getArguments());
        if ([] !== $calls = $definition->getMethodCalls()) {
            $parts[] = 'calls=' . self::value($calls);
        }
        $tags = $definition->getTags();
        ksort($tags);
        $parts[] = 'tags=' . self::value(array_map(static function (array $occurrences): array {
            return array_map(static function (array $attributes): array {
                ksort($attributes);

                return $attributes;
            }, $occurrences);
        }, $tags));

        return implode(' ', $parts);
    }

    private static function visibility(Alias|Definition $service): string
    {
        return $service->isPublic() ? 'public' : 'private';
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof Reference => match ($value->getInvalidBehavior()) {
                ContainerInterface::NULL_ON_INVALID_REFERENCE => '@?',
                ContainerInterface::IGNORE_ON_INVALID_REFERENCE => '@!',
                default => '@',
            } . (string) $value,
            $value instanceof Definition => 'new(' . self::definition($value) . ')',
            \is_array($value) => '[' . implode(', ', array_map(
                static fn(int|string $key, mixed $item): string => $key . ': ' . self::value($item),
                array_keys($value),
                $value,
            )) . ']',
            \is_string($value) => "'" . $value . "'",
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value) => (string) $value,
            \is_object($value) => get_debug_type($value),
            default => get_debug_type($value),
        };
    }
}
