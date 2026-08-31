<?php

declare(strict_types=1);

namespace App\Ai\Guard;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * Un appel d'outil retenu par la garde, en attente d'une décision humaine.
 */
final readonly class PendingApproval implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
        public string $reason,
    ) {
    }

    public static function of(ToolCall $toolCall, string $reason): self
    {
        return new self($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments(), $reason);
    }

    /**
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, reason: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
        ];
    }
}
