<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Chat\ToolCallRef;
use App\Ai\Platform\ChatCompletion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La forme « chat completions » ne vit plus qu'ici — donc c'est ici qu'il faut la tenir.
 *
 * {@see \App\Ai\Platform\ScriptedChatModelClient} l'écrit, {@see \App\Ai\Chat\ChatTranscript} et
 * {@see \App\Ai\Workflow\DurableAgentWorkflow} la relisent. Le modèle scripté n'étant exercé que
 * par la démo lancée à la main, rien d'autre ne remarquerait qu'une clé a bougé : les deux côtés
 * dériveraient ensemble et le vrai fournisseur, lui, ne dériverait pas.
 *
 * D'où des littéraux plutôt qu'un aller-retour seul : ce qui est vérifié est la forme exacte, pas
 * sa cohérence avec elle-même.
 */
#[CoversClass(ChatCompletion::class)]
final class ChatCompletionTest extends TestCase
{
    public function testPlainTextKeepsTheSimpleStringEveryProviderExpects(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => 'Bonjour.'], 'finish_reason' => 'stop']]],
            ChatCompletion::ofText('Bonjour.')->toWire(),
        );
    }

    /**
     * La forme de Mistral : des morceaux `thinking`/`text`, et non un `reasoning_content` à côté —
     * que Mistral refuse par un 422.
     */
    public function testReasoningIsWrittenAsThinkingChunks(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Je pèse.']]],
                ['type' => 'text', 'text' => 'Bonjour.'],
            ]], 'finish_reason' => 'stop']]],
            ChatCompletion::ofText('Bonjour.', 'Je pèse.')->toWire(),
        );
    }

    public function testAToolCallCarriesItsArgumentsAsAJsonString(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                'id' => 'call_3',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}'],
            ]]], 'finish_reason' => 'tool_calls']]],
            ChatCompletion::ofToolCall(new ToolCallRef('call_3', 'weather', ['city' => 'Paris']))->toWire(),
        );
    }

    public function testWhatIsWrittenIsWhatIsRead(): void
    {
        $relu = ChatCompletion::fromWire(
            ChatCompletion::ofToolCall(new ToolCallRef('call_3', 'weather', ['city' => 'Paris']))->toWire(),
        );

        self::assertNotNull($relu);
        self::assertNull($relu->text);
        self::assertEquals([new ToolCallRef('call_3', 'weather', ['city' => 'Paris'])], $relu->toolCalls);

        $raisonne = ChatCompletion::fromWire(ChatCompletion::ofText('Bonjour.', 'Je pèse.')->toWire());
        self::assertSame('Bonjour.', $raisonne?->text);
        self::assertSame('Je pèse.', $raisonne?->reasoning);
    }

    /**
     * Un journal qui ne porte pas encore de réponse rend `null`, et ce n'est pas un défaut : c'est
     * ce qui distingue un tour en cours d'un tour fini ({@see \App\Ai\Chat\ChatTranscript}).
     */
    public function testAnAbsentAnswerIsNull(): void
    {
        self::assertNull(ChatCompletion::fromWire(null));
        self::assertNull(ChatCompletion::fromWire('pas un tableau'));
        self::assertNull(ChatCompletion::fromWire(['choices' => []]));
    }
}
