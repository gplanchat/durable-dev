<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Workflow\DurableAgentWorkflow;
use App\Durable\DurableMessengerDrain;
use Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les deux bornes qui ferment un run d'agent, et ce qu'elles laissent derrière elles.
 *
 * @internal
 */
#[Group('integration')]
final class AiAgentLifecycleTest extends KernelTestCase
{
    use DurableBundleTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Un silence assez long termine l'exécution au lieu de la laisser ouverte indéfiniment.
     * Cinquante millisecondes ici, une heure en démo : c'est le même minuteur journalisé.
     */
    public function testAnIdleAgentEndsItsRun(): void
    {
        $executionId = $this->dispatchWorkflow(DurableAgentWorkflow::class, [
            'tools' => [],
            'idleTimeoutSeconds' => 0.05,
        ]);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, '');
    }

    /**
     * Au bout de N tours, le run passe la main — et ce qu'il transmet est le fil parlé, dans la
     * forme que la projection sait déjà relire.
     */
    public function testTheRunHandsOverItsThreadAfterTheRolloverThreshold(): void
    {
        $executionId = $this->dispatchWorkflow(DurableAgentWorkflow::class, [
            'tools' => [],
            'prompt' => 'Salut',
            'maxTurns' => 5,
            'rolloverAfterTurns' => 1,
        ]);

        $this->drainUntilHandover($executionId);

        $continued = null;
        foreach ($this->getEventStoreService()->readStream($executionId) as $event) {
            if ($event instanceof WorkflowContinuedAsNew) {
                $continued = $event;
            }
        }

        self::assertNotNull($continued, 'le run devait passer la main après son premier tour');
        self::assertSame(DurableAgentWorkflow::TYPE, $continued->nextWorkflowType());

        $history = $continued->nextPayload()['history'] ?? [];
        self::assertCount(2, $history);
        self::assertSame(['role' => 'user', 'content' => 'Salut'], $history[0]);
        self::assertSame('assistant', $history[1]['role']);
        self::assertNotSame('', $history[1]['content']);

        // La charge transmise n'a de valeur que si le run suivant en fait vraiment un sac : le
        // modèle doit voir le fil repris *et* le nouveau message, pas repartir de zéro.
        $successor = $this->dispatchWorkflow(
            DurableAgentWorkflow::class,
            $continued->nextPayload() + ['prompt' => 'Salut encore'],
        );
        $this->drainUntilHandover($successor);

        $resumed = (new ChatTranscript($this->getEventStoreService(), $this->getWorkflowMetadataStore()))
            ->forExecution($successor);

        self::assertGreaterThanOrEqual(4, \count($resumed->messages), 'le fil repris doit précéder le nouveau tour');
        self::assertSame('Salut', $resumed->messages[0]->content);
        self::assertSame('Salut encore', $resumed->messages[2]->content);
    }

    /**
     * Une reprise froide ne rejoue pas la conversation : elle la résume. Le modèle du tour suivant
     * ne doit voir qu'un message repris, pas les quatre d'avant — c'est tout l'intérêt.
     */
    public function testAResumedRunStartsFromASummary(): void
    {
        $executionId = $this->dispatchWorkflow(DurableAgentWorkflow::class, [
            'tools' => [],
            'compactHistory' => true,
            'history' => [
                ['role' => 'user', 'content' => 'Météo à Lyon ?'],
                ['role' => 'assistant', 'content' => '18 °C'],
                ['role' => 'user', 'content' => 'Et à Paris ?'],
                ['role' => 'assistant', 'content' => '14 °C'],
            ],
            'prompt' => 'Merci',
            'maxTurns' => 1,
        ]);

        $this->drainMessengerUntilSettled($executionId);

        $resumed = (new ChatTranscript($this->getEventStoreService(), $this->getWorkflowMetadataStore()))
            ->forExecution($executionId);

        // Le résumé, le nouveau message, la réponse. Pas les quatre tours d'avant.
        self::assertCount(3, $resumed->messages);
        self::assertStringStartsWith('Résumé de notre conversation précédente :', (string) $resumed->messages[0]->content);
        self::assertStringContainsString('Météo à Lyon', (string) $resumed->messages[0]->content);
        self::assertSame('Merci', $resumed->messages[1]->content);
    }

    /**
     * La compaction porte un nom d'activité à part, et ce n'est pas cosmétique : sous
     * `ai_model_invoke`, la projection prendrait sa charge — la conversation qu'on remplace — pour
     * le tour en cours, et une reprise restée silencieuse réafficherait tout l'ancien fil.
     */
    public function testACompactedRunGoneIdleShowsTheSummaryNotTheOldThread(): void
    {
        $executionId = $this->dispatchWorkflow(DurableAgentWorkflow::class, [
            'tools' => [],
            'compactHistory' => true,
            'history' => [
                ['role' => 'user', 'content' => 'Météo à Lyon ?'],
                ['role' => 'assistant', 'content' => '18 °C'],
            ],
            'idleTimeoutSeconds' => 0.05,
        ]);

        $this->drainMessengerUntilSettled($executionId);

        $resumed = (new ChatTranscript($this->getEventStoreService(), $this->getWorkflowMetadataStore()))
            ->forExecution($executionId);

        self::assertCount(1, $resumed->messages);
        self::assertStringStartsWith('Résumé de notre conversation précédente :', (string) $resumed->messages[0]->content);
    }

    /**
     * Un run qui passe la main ne « se termine » pas : il n'a pas de résultat, et sa métadonnée
     * disparaît au profit de celle du run suivant. Le drain du trait attend un résultat, celui-ci
     * attend la relève.
     */
    private function drainUntilHandover(string $executionId): void
    {
        DurableMessengerDrain::drainUntilCompleteOrSignalWait(
            $this->getEventStoreService(),
            $this->getWorkflowMetadataStore(),
            $this->getMessageBus(),
            $this->getMessengerReceiverLocator(),
            $executionId,
        );
    }
}
