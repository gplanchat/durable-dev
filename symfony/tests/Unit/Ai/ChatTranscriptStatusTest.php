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
}
