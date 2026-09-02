<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\PendingApproval;
use Gplanchat\Durable\Duration;

/**
 * L'état de la conversation tel que le journal le raconte.
 *
 * `working` et `finished` sont dérivés, pas stockés : c'est une projection, elle n'a pas d'état
 * propre à tenir à jour.
 */
final readonly class Transcript implements \JsonSerializable
{
    /**
     * @param list<TranscriptMessage> $messages
     * @param list<ToolStep>          $steps
     * @param list<PendingApproval>   $pending
     */
    public function __construct(
        public array $messages,
        public array $steps,
        public array $pending,
        public AgentMode $mode,
        public ?Duration $approvalTimeout,
        public bool $working,
        public bool $finished,
    ) {
    }

    /**
     * Ce qu'une exécution neuve doit reprendre de celle-ci : le fil parlé, sans la mécanique
     * d'outils du run qui s'achève.
     *
     * @return list<array{role: string, content: string}>
     */
    public function seed(): array
    {
        return TranscriptMessage::listToWire(array_filter(
            $this->messages,
            static fn (TranscriptMessage $message): bool => $message->carriesText(),
        ));
    }

    /**
     * @return array{messages: list<TranscriptMessage>, steps: list<ToolStep>, pending: list<PendingApproval>, mode: string, approvalTimeoutSeconds: float|null, working: bool, finished: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'messages' => $this->messages,
            'steps' => $this->steps,
            'pending' => $this->pending,
            'mode' => $this->mode->value,
            'approvalTimeoutSeconds' => $this->approvalTimeout?->toSeconds(),
            'working' => $this->working,
            'finished' => $this->finished,
        ];
    }
}
