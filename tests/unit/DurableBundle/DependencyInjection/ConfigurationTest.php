<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * A wrong value is refused while the tree is processed, with its path, instead of surfacing later
 * as a failed connection or a `ReflectionException` in `cache:warmup` (#334).
 */
final class ConfigurationTest extends TestCase
{
    public function testATemporalDsnThatIsNotAStringIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('durable.temporal.dsn');

        $this->process(['temporal' => ['dsn' => 7233]]);
    }

    public function testAnEmptyTemporalDsnIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('durable.temporal.dsn');

        $this->process(['temporal' => ['dsn' => '']]);
    }

    public function testABlankTemporalDsnIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('durable.temporal.dsn');

        $this->process(['temporal' => ['dsn' => '   ']]);
    }

    public function testAnExplicitNullDsnStillMeansNoCluster(): void
    {
        // The Symfony bench writes `dsn: null` in its default and `test` profiles.
        self::assertNull($this->process(['temporal' => ['dsn' => null]])['temporal']['dsn']);
    }

    public function testANegativeRetryCountIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('durable.max_activity_retries');

        $this->process(['max_activity_retries' => -1]);
    }

    public function testAnActivityContractThatIsNotAnInterfaceIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('durable.activity_contracts.contracts.0');

        $this->process(['activity_contracts' => ['contracts' => ['App\Durable\Activity\TypoActivityInterface']]]);
    }

    public function testAnExistingActivityContractIsAccepted(): void
    {
        $config = $this->process(['activity_contracts' => ['contracts' => [\Countable::class]]]);

        self::assertSame([\Countable::class], $config['activity_contracts']['contracts']);
    }

    public function testTheOutboxTableNameIsDeprecated(): void
    {
        // Read nowhere: the outbox it names does not exist until #328 decides it.
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $this->process(['activity_transport' => ['table_name' => 'outbox']]);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $deprecations);
        self::assertStringContainsString('durable.activity_transport.table_name', $deprecations[0]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
