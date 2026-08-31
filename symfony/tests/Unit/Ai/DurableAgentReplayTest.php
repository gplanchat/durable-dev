<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La question du prototype : la boucle d'appel d'outils de Symfony AI survit-elle au rejeu ?
 *
 * Le runner in-memory tourne en mode distribué : chaque `await` suspend le fiber et **rejoue le
 * code du workflow depuis le début** à la reprise. `Runner::run()` est donc réexécuté une fois par
 * point de suspension — si la boucle n'était pas rejouable, ce test n'irait pas au bout.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class DurableAgentReplayTest extends TestCase
{
    private const TOOLS = [
        'weather' => [
            'description' => 'Météo courante d\'une ville.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ],
        ],
    ];

    public function testTheToolCallingLoopReplaysWithoutReinvokingTheModel(): void
    {
        [$result, $modelCalls, $toolCalls, $passes] = $this->executeAgent('exec-1');

        self::assertSame('Paris 22°C, Lyon 25°C.', $result);

        // Sans ça, le reste ne prouve rien : il faut que le code du workflow — donc
        // `Runner::run()` et la boucle d'appel d'outils — ait bien été réexécuté.
        self::assertGreaterThan(3, $passes, 'Le workflow n\'a pas été rejoué ; le test ne prouve rien.');

        // Le journal court-circuite le rejeu : trois tours de modèle, pas un de plus, alors que le
        // code du workflow a été réexécuté à chaque reprise.
        self::assertCount(3, $modelCalls, 'Le rejeu a redemandé le modèle.');
        self::assertCount(2, $toolCalls, 'Le rejeu a ré-exécuté un outil.');
    }

    public function testTheOutboundPayloadsAreIdenticalAcrossTwoIndependentRuns(): void
    {
        [, $first] = $this->executeAgent('exec-a');
        [, $second] = $this->executeAgent('exec-b');

        // L'assertion qui compte. Le journal est indexé par position de curseur, pas par contenu :
        // les comptages ci-dessus passeraient même si le payload avait changé. Un UUID de message
        // qui fuit dans la charge, un toolbox relu du conteneur, et c'est ici que ça rougit.
        self::assertSame(
            json_encode($first, \JSON_PRETTY_PRINT),
            json_encode($second, \JSON_PRETTY_PRINT),
            'Le code du workflow n\'est pas déterministe : deux exécutions produisent des payloads différents.',
        );
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: int}
     */
    private function executeAgent(string $executionId): array
    {
        $modelCalls = [];
        $toolCalls = [];
        $passes = 0;

        $scripted = [
            $this->toolCallResponse('call_1', 'weather', ['city' => 'Paris']),
            $this->toolCallResponse('call_2', 'weather', ['city' => 'Lyon']),
            $this->textResponse('Paris 22°C, Lyon 25°C.'),
        ];

        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$modelCalls, $scripted): array {
                $modelCalls[] = ['model' => $payload['model'], 'payload' => $payload['payload'], 'options' => $payload['options']];

                return $scripted[\count($modelCalls) - 1];
            },
            'ai_tool_call' => static function (array $payload) use (&$toolCalls): string {
                $toolCalls[] = $payload;

                return \sprintf('%s: 22°C', $payload['arguments']['city']);
            },
        ]);

        $result = $environment->run(
            static function ($workflowEnvironment) use (&$passes): string {
                ++$passes;

                return (new DurableAgentWorkflow($workflowEnvironment))
                    ->run('Météo à Paris et à Lyon ?', 'gpt-4o-mini', self::TOOLS);
            },
            $executionId,
        );

        return [$result, $modelCalls, $toolCalls, $passes];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCallResponse(string $id, string $name, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
