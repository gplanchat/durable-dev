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
    /**
     * @param array<string, mixed> $arguments
     * @param float|null           $expiresAt instant (epoch, secondes) où l'échéance tranchera à la
     *                                        place de l'humain ; `null` = pas d'échéance
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
        public string $reason,
        public ?float $expiresAt = null,
    ) {
    }

    public function expiringAt(?float $expiresAt): self
    {
        return new self($this->callId, $this->tool, $this->arguments, $this->reason, $expiresAt);
    }

    public static function of(ToolCall $toolCall, string $reason): self
    {
        return new self($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments(), $reason);
    }

    /**
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, reason: string, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
