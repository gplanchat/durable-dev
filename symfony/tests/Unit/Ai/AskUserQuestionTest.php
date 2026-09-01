<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Question\AskUserQuestion;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * L'outil dont l'exécution est une suspension : son résultat n'est pas calculé, il est attendu.
 *
 * C'est l'inverse de la garde — celle-ci décide si un outil que le modèle a choisi peut partir,
 * celui-là va chercher ce que le modèle ne sait pas. Même primitive, un signal et une attente.
 */
#[CoversClass(AskUserQuestion::class)]
final class AskUserQuestionTest extends TestCase
{
    private const CALL = 'q1';

    public function testTheQuestionToolIsAlwaysOfferedEvenWithoutAnyOtherTool(): void
    {
        $offered = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$offered): array {
                foreach ($payload['options']['tools'] ?? [] as $tool) {
                    $offered[] = $tool['function']['name'] ?? '?';
                }

                return self::text('Bonjour.');
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-0');

        self::assertContains(AskUserQuestion::TOOL, $offered);
    }

    public function testTheAnswerComesBackToTheModelAsTheToolResult(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        // La réponse est déjà au journal : le workflow l'applique en atteignant sa condition.
        // C'est bien un signal — le même que celui qu'enverrait la page, à la même place.
        $this->answerUpFront($environment, 'question-1', ['Par lot']);

        self::assertSame('Compris : Par lot', $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-1'));
    }

    public function testSeveralAnswersTravelTogetherWhenTheQuestionAllowsIt(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());
        $this->answerUpFront($environment, 'question-2', ['Par lot', 'En arrière-plan']);

        self::assertSame('Compris : Par lot ; En arrière-plan', $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-2'));
    }

    /**
     * Sans réponse, l'agent ne reste pas planté : l'échéance lui rend une phrase qui le lui dit,
     * et il poursuit. L'horloge virtuelle du runner avance d'échéance en échéance.
     */
    public function testAQuestionNobodyAnswersExpiresAndTheAgentIsToldSo(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(5.0), 'question-3');

        self::assertStringContainsString('Aucune réponse', $answer);
    }

    /**
     * Aucune activité n'est planifiée pour une question : il n'y a rien à exécuter.
     */
    public function testAQuestionNeverReachesAnActivity(): void
    {
        $toolCalls = 0;
        $handlers = $this->scriptedModel();
        $handlers['ai_tool_call'] = static function (array $payload) use (&$toolCalls): string {
            ++$toolCalls;

            return 'exécuté';
        };

        $environment = WorkflowTestEnvironment::inMemory($handlers);
        $this->answerUpFront($environment, 'question-4', ['Par lot']);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-4');

        self::assertSame(0, $toolCalls);
    }

    /**
     * L'ordre compte : le curseur des messages ne lit que ce qui suit `ExecutionStarted`.
     *
     * @param list<string> $answers
     */
    private function answerUpFront(WorkflowTestEnvironment $environment, string $executionId, array $answers): void
    {
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        $environment->getEventStore()->append(new WorkflowSignalReceived(
            $executionId,
            'question_answered',
            ['callId' => self::CALL, 'answers' => $answers],
        ));
    }

    /**
     * La forme de production : c'est le chargeur de définition qui enregistre les
     * `#[AsSignalMethod]`. Instancier la classe dans une closure les laisserait sur le carreau, et
     * aucun signal n'atteindrait jamais sa condition.
     *
     * @return array<string, mixed>
     */
    private function input(?float $timeout = null): array
    {
        return [
            'prompt' => 'Importe le catalogue',
            'maxTurns' => 1,
            'mode' => 'auto',
            'humanTimeoutSeconds' => $timeout,
        ];
    }

    /**
     * Premier tour : le modèle pose la question. Second tour : il relit ce qu'on lui a répondu.
     *
     * @return array<string, callable>
     */
    private function scriptedModel(): array
    {
        $round = 0;

        return [
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return self::toolCall(self::CALL, AskUserQuestion::TOOL, [
                        'question' => 'Comment veux-tu importer ?',
                        'header' => 'Import',
                        'options' => [
                            ['label' => 'Par lot', 'description' => 'Tout d’un coup'],
                            ['label' => 'En arrière-plan', 'description' => 'Au fil de l’eau'],
                        ],
                        'multiSelect' => true,
                    ]);
                }

                $last = end($payload['payload']['messages']);

                return self::text('Compris : '.$last['content']);
            },
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function toolCall(string $id, string $name, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments, \JSON_UNESCAPED_UNICODE)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
