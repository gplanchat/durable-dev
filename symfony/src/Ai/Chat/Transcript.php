<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\PendingApproval;
use App\Ai\Question\PendingQuestion;
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
     * @param list<PendingApproval>   $pending   validations retenues par la garde
     * @param list<PendingQuestion>   $questions questions posées par l'agent
     */
    public function __construct(
        public array $messages,
        public array $steps,
        public array $pending,
        public array $questions,
        public AgentMode $mode,
        public ?Duration $humanTimeout,
        public bool $working,
        public bool $finished,
    ) {
    }

    /**
     * @return array{messages: list<TranscriptMessage>, steps: list<ToolStep>, pending: list<PendingApproval>, questions: list<PendingQuestion>, mode: string, humanTimeoutSeconds: float|null, working: bool, finished: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'messages' => $this->messages,
            'steps' => $this->steps,
            'pending' => $this->pending,
            'questions' => $this->questions,
            'mode' => $this->mode->value,
            'humanTimeoutSeconds' => $this->humanTimeout?->toSeconds(),
            'working' => $this->working,
            'finished' => $this->finished,
        ];
    }
}
