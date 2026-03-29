<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Activity;

/**
 * Adapte un appel worker (payload tableau, clés = noms des paramètres du contrat) vers la méthode du handler.
 */
final class PayloadToContractMethodInvoker
{
    /**
     * @param class-string $contractClass
     */
    public function __construct(
        private readonly object $handler,
        private readonly string $contractClass,
        private readonly string $contractMethodName,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): mixed
    {
        $reflection = new \ReflectionMethod($this->contractClass, $this->contractMethodName);
        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $key = $param->getName();
            if (\array_key_exists($key, $payload)) {
                $args[] = $payload[$key];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(\sprintf('Missing payload key "%s" for activity method %s::%s()', $key, $this->contractClass, $this->contractMethodName));
            }
        }

        $impl = new \ReflectionClass($this->handler);
        $method = $impl->getMethod($this->contractMethodName);

        return $method->invoke($this->handler, ...$args);
    }
}
