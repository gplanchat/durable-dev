<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\Configuration;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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

    public function testAnExplicitNullDsnStillOverridesADsnSetElsewhere(): void
    {
        // A profile that turns the cluster off on top of one that turned it on.
        $config = (new Processor())->processConfiguration(new Configuration(), [
            ['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=default&tls=0']],
            ['temporal' => ['dsn' => null]],
        ]);

        self::assertNull($config['temporal']['dsn']);
    }

    public function testAnEnvPlaceholderDsnWithoutADefaultIsAccepted(): void
    {
        // `dsn: '%env(DURABLE_DSN)%'` with no `env(DURABLE_DSN)` parameter: Symfony checks the
        // placeholder with an empty string, which the blank-DSN rule refused, and every container
        // that named its DSN this way failed to compile.
        $container = new ContainerBuilder();
        $container->registerExtension(new DurableExtension());
        $container->loadFromExtension('durable', ['temporal' => ['dsn' => '%env(DURABLE_DSN)%']]);

        (new MergeExtensionConfigurationPass())->process($container);
        (new ValidateEnvPlaceholdersPass())->process($container);

        // And the DSN still turns the cluster on, read at runtime from the variable.
        self::assertTrue($container->hasDefinition('durable.temporal.connection'));
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

    public function testTheOutboxTableNameIsRefused(): void
    {
        // Deprecated in beta1 because no outbox ever existed; DUR050 (#328) chose not to build one,
        // so the option is gone rather than read. Setting it is a configuration error.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('table_name');

        $this->process(['activity_transport' => ['table_name' => 'outbox']]);
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
