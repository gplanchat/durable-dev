<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Context\Conversation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Le tableau plat qui part au fournisseur ne dit pas ses invariants. Ce type les porte.
 */
#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase
{
    public function testTheWireSurvivesTheRoundTrip(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Sois concis.'],
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1']]],
            ['role' => 'tool', 'content' => 'ok', 'tool_call_id' => 'c1'],
            ['role' => 'assistant', 'content' => 'Voilà'],
            ['role' => 'user', 'content' => 'Merci'],
        ];

        self::assertSame($messages, Conversation::fromWire($messages)->toWire());
    }

    public function testATurnGathersEverythingTheAgentProducedInAnswer(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1']]],
            ['role' => 'tool', 'content' => 'r1', 'tool_call_id' => 'c1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'u2'],
        ]);

        self::assertCount(2, $conversation->turns);
        self::assertSame(4, $conversation->turns[0]->count(), 'Le tour doit tenir l’appel d’outil et son résultat.');
        self::assertCount(1, $conversation->system);
    }

    public function testTheLastTurnIsNeverDropped(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
        ]);

        self::assertSame($conversation, $conversation->withoutOldestTurn());
    }

    /**
     * Un marqueur de compaction posé au fil des tours n'est pas du préambule : le rattacher au
     * système le ferait remonter en tête à chaque passage, et il finirait par en avoir plusieurs.
     */
    public function testOnlyLeadingSystemMessagesArePreamble(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
            ['role' => 'system', 'content' => '[messages retirés]'],
            ['role' => 'user', 'content' => 'u2'],
        ]);

        self::assertCount(1, $conversation->system);
        self::assertCount(2, $conversation->turns);
    }
}
