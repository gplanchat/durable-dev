<?php

declare(strict_types=1);

namespace App\Durable\Activity;

final class TickActivityHandler implements TickActivity
{
    public function tick(): string
    {
        return 'tick';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function tickFromPayload(array $payload): string
    {
        return $this->tick();
    }
}
