<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Activity;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\Attribute\AsActivityHandler;
use Gplanchat\Durable\RegistryActivityExecutor;
use Illuminate\Contracts\Container\Container;

/**
 * The activity handlers `config/durable.php` declares, carried onto the executor: the counterpart
 * of Symfony's `ActivityHandlerPass` and Magento's `activityHandlers`. The contract is the one
 * `#[AsActivityHandler]` names, else the handler's activity interfaces. The table is built by
 * reflection when the provider registers; a handler is built only when one of its activities runs.
 */
final readonly class DeclaredActivityHandlers
{
    /** @var array<string, array{class-string, class-string, string}> activity => handler, contract, method */
    private array $table;

    /** @param list<string> $handlers */
    public function __construct(array $handlers)
    {
        $resolver = new ActivityContractResolver();
        $table = [];

        foreach ($handlers as $handler) {
            if (!class_exists($handler)) {
                throw new \InvalidArgumentException(\sprintf('Durable: "%s" is declared in durable.activity_handlers, but no such class exists.', $handler));
            }

            $served = 0;
            foreach (self::contractsOf($handler) as $contract) {
                foreach ($resolver->resolveActivityMethods($contract) as $method => $activity) {
                    if (!method_exists($handler, $method)) {
                        throw new \InvalidArgumentException(\sprintf('Durable: %s must implement %s::%s(), the contract its #[AsActivityHandler] names.', $handler, $contract, $method));
                    }
                    $table[$activity] = [$handler, $contract, $method];
                    ++$served;
                }
            }

            // Symfony's pass refuses the same two mistakes at compile time.
            if (0 === $served) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: %s is declared in durable.activity_handlers but serves no activity: it neither names a '
                    . 'contract with #[AsActivityHandler] nor implements an interface with #[AsActivityMethod] methods.',
                    $handler,
                ));
            }
        }

        $this->table = $table;
    }

    public function registerInto(RegistryActivityExecutor $executor, Container $container): RegistryActivityExecutor
    {
        foreach ($this->table as $activity => [$handler, $contract, $method]) {
            $executor->register(
                $activity,
                static fn(array $payload): mixed => (new PayloadToContractMethodInvoker($container->make($handler), $contract, $method))($payload),
            );
        }

        return $executor;
    }

    /**
     * @param class-string $handler
     *
     * @return list<class-string>
     */
    private static function contractsOf(string $handler): array
    {
        $named = (new \ReflectionClass($handler))->getAttributes(AsActivityHandler::class);
        if ([] !== $named) {
            $contract = $named[0]->newInstance()->contract;
            if (!interface_exists($contract) && !class_exists($contract)) {
                throw new \InvalidArgumentException(\sprintf('Durable: %s names "%s" in #[AsActivityHandler], which is not a loadable interface or class.', $handler, $contract));
            }

            return [$contract];
        }

        return array_values(class_implements($handler) ?: []);
    }
}
