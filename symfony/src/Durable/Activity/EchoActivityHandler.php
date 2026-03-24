<?php

declare(strict_types=1);

namespace App\Durable\Activity;

final class EchoActivityHandler implements EchoActivity
{
    public function echoUpper(string $text = ''): string
    {
        return strtoupper($text);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function echoUpperFromPayload(array $payload): string
    {
        return $this->echoUpper((string) ($payload['text'] ?? ''));
    }
}
