<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Chat\Transcript;
use App\Ai\Chat\TranscriptMessage;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Une exécution qui en reprend une autre — clôture sur inactivité, ou continue-as-new — porte son
 * fil dans sa charge de démarrage. Tant qu'aucun appel modèle ne l'a réémis, c'est la seule trace
 * qu'en ait le journal.
 */
#[CoversClass(ChatTranscript::class)]
#[CoversClass(Transcript::class)]
#[CoversClass(TranscriptMessage::class)]
final class ChatTranscriptResumeTest extends TestCase
{
    private const EXECUTION = 'chat-repris';

    /** @var list<array{role: string, content: string}> */
    private const HISTORY = [
        ['role' => 'user', 'content' => 'Bonjour'],
        ['role' => 'assistant', 'content' => 'Bonjour !'],
    ];

    private InMemoryEventStore $eventStore;
    private InMemoryWorkflowMetadataStore $metadataStore;
    private ChatTranscript $transcript;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->metadataStore = new InMemoryWorkflowMetadataStore();
        $this->transcript = new ChatTranscript($this->eventStore, $this->metadataStore);
    }

    public function testTheCarriedThreadShowsBeforeAnyModelCall(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertSame(
            ['Bonjour', 'Bonjour !'],
            array_map(static fn (TranscriptMessage $message): ?string => $message->content, $transcript->messages),
        );
        self::assertFalse($transcript->working, 'un fil repris n’est pas un tour en cours');
    }

    /**
     * Sans compter le fil repris du côté des messages reçus, un run repris paraît au repos pendant
     * qu'il travaille : le premier signal de ce run seul ne fait pas le poids face au sac entier.
     */
    public function testASignalOnAResumedRunStillCountsAsWorking(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Et Lyon ?']));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    /**
     * Après un continue-as-new, le store de métadonnées porte encore la charge du **premier** run.
     * Seul l'événement du run courant dit ce que ce run a repris.
     */
    public function testTheRunEventWinsOverTheStaleMetadataPayload(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => []]);
        $this->eventStore->append(new ExecutionStarted(self::EXECUTION, ['history' => self::HISTORY]));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertCount(2, $transcript->messages);
        self::assertSame('Bonjour !', $transcript->messages[1]->content);
    }

    /**
     * Le journal du run courant l'emporte dès qu'il porte le fil : la charge de démarrage n'est
     * qu'un repli.
     */
    public function testAModelCallSupersedesTheCarriedThread(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Et Lyon ?']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => 'Bonjour'],
                ['role' => 'assistant', 'content' => 'Bonjour !'],
                ['role' => 'user', 'content' => 'Et Lyon ?'],
            ]],
        ]));

        self::assertCount(3, $this->transcript->forExecution(self::EXECUTION)->messages);
    }

    /**
     * Ce qui repart est le fil parlé. Un `role: tool` et un message d'assistant qui ne porte que
     * des `tool_calls` racontent la mécanique du run qui s'achève : les rejouer remplirait le
     * suivant de bulles vides et de références d'appels qui n'existent plus.
     */
    public function testTheSeedDropsToolNoise(): void
    {
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'system', 'content' => 'Tu es concis.'],
                ['role' => 'user', 'content' => 'Météo à Lyon ?'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c1', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Lyon"}']],
                ]],
                ['role' => 'tool', 'content' => '18 °C'],
                ['role' => 'assistant', 'content' => 'Il fait 18 °C.'],
            ]],
        ]));

        self::assertSame([
            ['role' => 'user', 'content' => 'Météo à Lyon ?'],
            ['role' => 'assistant', 'content' => 'Il fait 18 °C.'],
        ], $this->transcript->forExecution(self::EXECUTION)->seed());
    }
}
