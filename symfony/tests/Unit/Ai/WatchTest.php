<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Watch\WatchTool;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La veille : l'agent dort, et se réveille en retrouvant ce qu'il comptait faire.
 *
 * C'est ce qu'un watcher de processus ne sait pas faire. Ici l'intention est écrite au journal au
 * moment de l'inscription — l'agent n'a pas à s'en souvenir trois jours plus tard, on la lui rend.
 */
#[CoversClass(WatchTool::class)]
final class WatchTest extends TestCase
{
    private const CALL = 'w1';

    public function testTheAlertHandsBackBothTheObservationAndTheIntention(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());
        $this->alertUpFront($environment, 'watch-1', 'le camion est à quai');

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-1');

        self::assertStringContainsString('le camion est à quai', $answer);
        self::assertStringContainsString('Enregistrer la réception', $answer, 'Le réveil a oublié de rendre l’intention.');
    }

    /**
     * Sans alerte, la veille tombe — et elle rend quand même l'intention, pour que l'agent sache
     * de quoi il parle en reprenant la main.
     */
    public function testAWatchNobodyRaisesExpiresAndStillRecallsTheIntention(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-2');

        self::assertStringContainsString('expiré sans alerte', $answer);
        self::assertStringContainsString('Enregistrer la réception', $answer);
    }

    public function testAWatchNeverReachesAnActivity(): void
    {
        $toolCalls = 0;
        $handlers = $this->scriptedModel();
        $handlers['ai_tool_call'] = static function () use (&$toolCalls): string {
            ++$toolCalls;

            return 'exécuté';
        };

        $environment = WorkflowTestEnvironment::inMemory($handlers);
        $this->alertUpFront($environment, 'watch-3', 'le camion est à quai');

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-3');

        self::assertSame(0, $toolCalls);
    }

    public function testTheWatchToolIsAlwaysOffered(): void
    {
        $offered = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$offered): array {
                foreach ($payload['options']['tools'] ?? [] as $tool) {
                    $offered[] = $tool['function']['name'] ?? '?';
                }

                return ['choices' => [['message' => ['content' => 'Bonjour.'], 'finish_reason' => 'stop']]];
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-4');

        self::assertContains(WatchTool::TOOL, $offered);
    }

    private function alertUpFront(WorkflowTestEnvironment $environment, string $executionId, string $observation): void
    {
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        $environment->getEventStore()->append(new WorkflowSignalReceived(
            $executionId,
            'alerte',
            ['callId' => self::CALL, 'observation' => $observation],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return ['prompt' => 'Surveille la livraison', 'maxTurns' => 1, 'mode' => 'auto'];
    }

    /**
     * @return array<string, callable>
     */
    private function scriptedModel(): array
    {
        $round = 0;

        return [
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => self::CALL,
                        'type' => 'function',
                        'function' => ['name' => WatchTool::TOOL, 'arguments' => json_encode([
                            'sujet' => 'commande.expediee',
                            'observation' => 'La livraison arrive à l’entrepôt',
                            'intention' => 'Enregistrer la réception et prévenir l’équipe',
                            'deadlineSeconds' => 5,
                        ], \JSON_UNESCAPED_UNICODE)],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => $last['content']], 'finish_reason' => 'stop']]];
            },
        ];
    }

    /**
     * Un sujet hors du vocabulaire ne doit pas armer une veille : rien ne pourrait jamais la
     * lever, et l'agent dormirait jusqu'à son échéance sans que personne ne le sache.
     */
    public function testAnUnknownSubjectIsRefusedInsteadOfArmingADeadWatch(): void
    {
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => self::CALL,
                        'type' => 'function',
                        'function' => ['name' => WatchTool::TOOL, 'arguments' => json_encode([
                            'sujet' => 'livraison.arrivee',
                            'observation' => 'Le camion',
                            'intention' => 'Prévenir',
                        ], \JSON_UNESCAPED_UNICODE)],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => $last['content']], 'finish_reason' => 'stop']]];
            },
        ]);

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-refus');

        self::assertStringContainsString('Sujet de veille inconnu', $answer);
        self::assertStringContainsString('commande.expediee', $answer, 'Le refus doit dire ce qui est acceptable.');
    }
}
