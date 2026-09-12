<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Chat\ChatTranscript;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * « L'agent travaille » doit vouloir dire qu'un tour est en cours — pas qu'aucun tour n'a
 * jamais commencé.
 */
#[CoversClass(ChatTranscript::class)]
final class ChatTranscriptStatusTest extends TestCase
{
    private const EXECUTION = 'chat-1';

    private InMemoryEventStore $eventStore;
    private ChatTranscript $transcript;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->transcript = new ChatTranscript($this->eventStore, new InMemoryWorkflowMetadataStore());
    }

    public function testAFreshExecutionIsIdleNotWorking(): void
    {
        self::assertFalse($this->transcript->forExecution(self::EXECUTION)->working);
    }

    public function testASignalledMessageTheModelHasNotSeenYetCountsAsWorking(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Salut']));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    public function testAnAnsweredTurnIsIdleAgain(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Salut']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'user', 'content' => 'Salut']]],
        ]));
        $this->eventStore->append(new ActivityCompleted(self::EXECUTION, 'a1', [
            'choices' => [['message' => ['content' => 'Bonjour.']]],
        ]));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertFalse($transcript->working);
        self::assertSame('Bonjour.', $transcript->messages[1]->content);
    }

    public function testAScheduledModelCallWithoutItsResultIsWorking(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Salut']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'user', 'content' => 'Salut']]],
        ]));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    /**
     * Le raisonnement se lit à deux endroits, et il faut les deux : celui d'un tour passé est
     * remis par le normaliseur dans la charge du tour suivant, celui du dernier tour n'a pas de
     * tour suivant et ne vit que dans le résultat.
     *
     * La forme est celle du pont Mistral — des morceaux `thinking` et `text` dans `content` — et
     * non le `reasoning_content` du contrat générique, que Mistral refuse par un 422.
     */
    public function testTheThreadCarriesTheReasoningOfPastAndLastTurns(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Météo à Lyon ?']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => 'Météo à Lyon ?'],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Une ville est nommée, je lis la météo.']]],
                ]],
                ['role' => 'tool', 'content' => 'Lyon: 25°C'],
            ]],
        ]));
        $this->eventStore->append(new ActivityCompleted(self::EXECUTION, 'a1', [
            'choices' => [['message' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Le relevé est là, je réponds.']]],
                ['type' => 'text', 'text' => 'Lyon 25°C.'],
            ]]]],
        ]));

        $messages = $this->transcript->forExecution(self::EXECUTION)->messages;

        self::assertSame('Une ville est nommée, je lis la météo.', $messages[1]->reasoning, 'Le raisonnement d\'un tour passé est perdu.');
        self::assertNull($messages[0]->reasoning, 'Un message de l\'humain n\'a pas de raisonnement.');
        self::assertSame('Lyon 25°C.', $messages[3]->content);
        self::assertSame('Le relevé est là, je réponds.', $messages[3]->reasoning, 'Le raisonnement du dernier tour est perdu.');
    }

    public function testAMessageWithoutReasoningCarriesNullNotAnEmptyString(): void
    {
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'assistant', 'content' => 'Bonjour.', 'reasoning_content' => '   ']]],
        ]));

        self::assertNull($this->transcript->forExecution(self::EXECUTION)->messages[0]->reasoning);
    }
}
