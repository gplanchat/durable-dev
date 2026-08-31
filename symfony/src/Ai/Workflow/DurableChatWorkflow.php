<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Durable\DurableAgentFactory;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Une conversation = **une** exécution de workflow. Chaque message de l'humain arrive par un signal.
 *
 * C'est ce que ni Symfony AI ni un worker Messenger ne savent faire : entre deux messages, le
 * workflow est suspendu — pas en attente dans un processus, suspendu — et il peut le rester des
 * jours, à travers un redéploiement. L'historique n'est stocké nulle part : c'est l'état du
 * workflow, reconstruit par rejeu depuis le journal.
 *
 * ponytail: le journal grossit avec la conversation. `continueAsNew` est la sortie documentée
 * (repartir d'un résumé), à faire quand une vraie conversation le justifie.
 */
#[AsWorkflow('Ai_DurableChat')]
final class DurableChatWorkflow
{
    /** @var list<string> */
    private array $inbox = [];

    private bool $closed = false;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    /**
     * Une file, pas un champ : un second message posté pendant que l'agent travaille écraserait le
     * premier. L'ordre de consommation est l'ordre du journal, donc stable au rejeu.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('user_message')]
    public function onUserMessage(array $payload): void
    {
        $text = trim((string) ($payload['text'] ?? ''));
        if ('' !== $text) {
            $this->inbox[] = $text;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('close')]
    public function onClose(array $payload): void
    {
        $this->closed = true;
    }

    /**
     * @param array<string, array{description: string, parameters: array<string, mixed>|null}> $tools
     */
    #[AsWorkflowMethod]
    public function run(
        array $tools = [],
        string $model = 'gpt-4o-mini',
        string $systemPrompt = 'Tu es un assistant concis. Utilise les outils quand ils répondent mieux que toi.',
        int $maxTurns = 20,
    ): int {
        $agent = DurableAgentFactory::create($this->environment, $model, $tools);
        $messages = new MessageBag(Message::forSystem($systemPrompt));
        $turns = 0;

        while (!$this->closed && $turns < $maxTurns) {
            $this->environment->await(fn(): bool => [] !== $this->inbox || $this->closed);

            if ($this->closed) {
                break;
            }

            $messages->add(Message::ofUser(array_shift($this->inbox)));

            // `Runner` ajoute lui-même les messages de la boucle d'outils au sac, mais pas la
            // réponse finale : elle sort de la boucle sans y passer.
            $messages->add(Message::ofAssistant($agent->call($messages)->getResult()));

            ++$turns;
        }

        return $turns;
    }
}
