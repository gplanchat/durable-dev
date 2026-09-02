<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
