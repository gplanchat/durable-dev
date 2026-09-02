<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Guard\AgentMode;
use App\Ai\Team\DelegateTool;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * **L'autorité ne grandit pas par délégation.**
 *
 * C'est l'invariant de toute l'histoire d'équipes d'agents. Sans lui, déléguer est le chemin
 * d'échappement de la garde : un agent en `standard` ne peut pas envoyer de courriel, mais il
 * confierait la tâche à un sous-agent en `auto` qui l'enverrait. La garde ne serait pas contournée
 * par une faille — elle serait devenue décorative.
 */
#[CoversClass(AgentMode::class)]
final class DelegationCeilingTest extends TestCase
{
    public function testTheStrictestOfTheChainWins(): void
    {
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Auto, AgentMode::Standard));
        self::assertSame(AgentMode::Edition, AgentMode::strictest(AgentMode::Auto, AgentMode::Edition));
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Standard, AgentMode::Edition, AgentMode::Auto));
    }

    /**
     * Un plafond refuse ce qui desserre, et **seulement** ça : un délégué qui veut être plus
     * prudent que son plafond en a toujours le droit.
     */
    public function testOnlyALooserModeIsRefusedByACeiling(): void
    {
        self::assertTrue(AgentMode::Auto->loosens(AgentMode::Standard));
        self::assertTrue(AgentMode::Edition->loosens(AgentMode::Standard));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Auto));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Standard));
    }

    /**
     * Le mode demandé au démarrage se plie au plafond — et on le lit **à travers la garde**, seul
     * endroit où un mode veut dire quelque chose : l'outil externe part-il, ou attend-il un accord
     * que personne ne donnera ?
     */
    public function testAnAgentStartedAboveItsCeilingIsClampedDown(): void
    {
        self::assertFalse(
            $this->externalToolRan(demande: 'auto', plafond: 'standard'),
            'Un agent délégué a envoyé un courriel que la garde de son parent lui refusait.',
        );
    }

    /**
     * La moitié qu'on oublie : le plafond vaut **aussi après**. Un sous-agent qui accepterait
     * `set_mode: auto` n'aurait pas de plafond du tout.
     */
    public function testASetModeSignalCannotLoosenPastTheCeiling(): void
    {
        self::assertFalse(
            $this->externalToolRan(demande: 'standard', plafond: 'standard', signale: 'auto'),
            'Un signal a desserré le plafond ; la garde est décorative.',
        );
    }

    /**
     * Le témoin : sans plafond, exactement le même scénario laisse passer. Sans lui, les deux
     * assertions ci-dessus passeraient même si la garde bloquait tout pour une autre raison.
     */
    public function testWithoutACeilingTheSameScenarioGoesThrough(): void
    {
        self::assertTrue(
            $this->externalToolRan(demande: 'auto', plafond: 'auto'),
            'Le témoin ne passe pas : le test ne prouve rien sur le plafond.',
        );
    }

    public function testTheDelegationToolIsOfferedAndHarmlessByItself(): void
    {
        $definition = DelegateTool::definition();

        self::assertSame('deleguer', $definition->name);
        self::assertFalse(
            AgentMode::Standard->requiresApprovalFor($definition->effect),
            'Déléguer n\'écrit nulle part : c\'est ce que fait le délégué qui repasse par une garde.',
        );
    }

    /**
     * Fait tourner un agent à qui le modèle demande un envoi de courriel — un effet `external`, le
     * cas que seule `auto` laisse passer — et dit si l'activité d'outil a réellement tourné.
     */
    private function externalToolRan(string $demande, string $plafond, ?string $signale = null): bool
    {
        $tours = 0;
        $execute = false;

        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$tours): array {
                if (0 === $tours++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'send_email', 'arguments' => '{"to":"x@example.test","body":"x"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                return ['choices' => [['message' => ['content' => 'fini'], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function () use (&$execute): string {
                $execute = true;

                return 'envoyé';
            },
        ]);

        $executionId = \sprintf('ceiling-%s-%s-%s', $demande, $plafond, $signale ?? 'rien');
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        if (null !== $signale) {
            $environment->getEventStore()->append(new WorkflowSignalReceived($executionId, 'set_mode', ['mode' => $signale]));
        }

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => ['send_email' => ['description' => 'Envoie un courriel.', 'effect' => 'external', 'parameters' => []]],
            'prompt' => 'Envoie le compte rendu',
            'maxTurns' => 1,
            'mode' => $demande,
            'modeCeiling' => $plafond,
            // Personne ne validera : l'attente doit tomber vite pour que le test tienne en
            // millisecondes plutôt qu'en quart d'heure.
            'humanTimeoutSeconds' => 0.01,
        ], $executionId);

        return $execute;
    }
}
