<?php

declare(strict_types=1);

namespace App\Durable\Activity;

final class GreetingActivityHandler implements GreetingActivityInterface
{
    public function composeGreeting(string $name = 'World'): string
    {
        return \sprintf('Hello, %s!', $name);
    }

    /**
     * Point d’entrée pour {@see ActivityExecutor::register()} (payload sérialisé depuis le workflow).
     *
     * @param array<string, mixed> $payload
     */
    public function composeGreetingFromPayload(array $payload): string
    {
        return $this->composeGreeting((string) ($payload['name'] ?? 'World'));
    }
}
