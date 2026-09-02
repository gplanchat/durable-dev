<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Context\ContextBudget;
use App\Ai\Context\ContextOverflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La garde posée sur l'appel modèle, là où celle des outils est posée sur les effets.
 *
 * Elle tourne en code workflow, donc elle est rejouée : sa seule contrainte non négociable est
 * d'être pure. C'est ce que le dernier test vérifie.
 */
#[CoversClass(ContextBudget::class)]
final class ContextBudgetTest extends TestCase
{
    public function testAConversationUnderTheCeilingIsLeftAlone(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Sois concis.'],
            ['role' => 'user', 'content' => 'Bonjour'],
        ];

        self::assertSame($messages, (new ContextBudget(10_000))->fit($messages));
    }

    public function testTheOldestTurnsGoFirstAndTheSystemMessageStays(): void
    {
        $fitted = (new ContextBudget(120))->fit($this->longConversation(20));

        self::assertSame('system', $fitted[0]['role']);
        self::assertSame('Sois concis.', $fitted[0]['content'], 'Le message système a été emporté.');
        self::assertStringContainsString('retirés du contexte', $fitted[1]['content']);
        self::assertSame('Question 20', end($fitted)['content'], 'Le dernier tour doit toujours rester.');
        self::assertLessThan(\count($this->longConversation(20)), \count($fitted));
    }

    /**
     * Le piège de la compaction : un `assistant` qui demande des outils et les `tool` qui lui
     * répondent forment un bloc. Couper au milieu laisse un résultat orphelin, que les
     * fournisseurs refusent.
     */
    public function testAToolResultIsNeverLeftWithoutItsCall(): void
    {
        $messages = [['role' => 'system', 'content' => 'S']];
        for ($turn = 0; $turn < 12; ++$turn) {
            $messages[] = ['role' => 'user', 'content' => \sprintf('Demande %d %s', $turn, str_repeat('x', 40))];
            $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c'.$turn]]];
            $messages[] = ['role' => 'tool', 'content' => 'résultat', 'tool_call_id' => 'c'.$turn];
        }

        $fitted = (new ContextBudget(200))->fit($messages);

        $ouverts = [];
        foreach ($fitted as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $ouverts[$call['id']] = true;
            }
            if ('tool' === $message['role']) {
                self::assertArrayHasKey($message['tool_call_id'], $ouverts, 'Un résultat d’outil a perdu son appel.');
            }
        }
    }

    public function testHalvingTightensTheCeiling(): void
    {
        $budget = new ContextBudget(1_000);

        self::assertSame(500, $budget->halved()->maxTokens);
        self::assertLessThan($budget->ceiling(), $budget->halved()->ceiling());
    }

    /**
     * Rejouée, la compaction doit rendre exactement le même payload — sinon l'appel modèle
     * journalisé et celui du rejeu divergent, et la garde de divergence (DUR042) tire.
     */
    public function testCompactionIsPure(): void
    {
        $budget = new ContextBudget(120);
        $messages = $this->longConversation(20);

        self::assertSame($budget->fit($messages), $budget->fit($messages));
    }

    public function testAProviderOverflowIsRecognisedByCodeOrByMessage(): void
    {
        self::assertTrue(ContextOverflow::detected(['error' => ['code' => 'context_length_exceeded']]));
        self::assertTrue(ContextOverflow::detected(['message' => 'This model has a maximum context length of 32k']));
        self::assertFalse(ContextOverflow::detected(['error' => ['code' => 'rate_limit_exceeded']]));
        self::assertFalse(ContextOverflow::detected(['choices' => [['message' => ['content' => 'ok']]]]));
    }

    /**
     * Le plafond de la compaction, et il est assumé : **un tour à lui seul plus gros que la
     * fenêtre ne peut pas être compacté**. Abandonner le message auquel le modèle doit répondre
     * n'aurait pas de sens ; on le laisse partir, le fournisseur le refuse, et c'est le chemin
     * réactif ({@see ContextOverflow}) qui reprend la main.
     */
    public function testASingleTurnBiggerThanTheWindowIsLeftAlone(): void
    {
        $enorme = [
            ['role' => 'system', 'content' => 'Sois concis.'],
            ['role' => 'user', 'content' => str_repeat('z', 4_000)],
        ];

        $budget = new ContextBudget(200);

        self::assertSame($enorme, $budget->fit($enorme));
        self::assertGreaterThan($budget->ceiling(), $budget->estimate($enorme));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function longConversation(int $turns): array
    {
        $messages = [['role' => 'system', 'content' => 'Sois concis.']];
        for ($turn = 0; $turn < $turns; ++$turn) {
            $messages[] = ['role' => 'user', 'content' => 'Question '.$turn];
            $messages[] = ['role' => 'assistant', 'content' => 'Réponse '.$turn.' '.str_repeat('y', 30)];
        }

        // Le dernier message est un `user` : c'est le tour auquel le modèle doit répondre.
        $messages[] = ['role' => 'user', 'content' => 'Question '.$turns];

        return $messages;
    }
}
