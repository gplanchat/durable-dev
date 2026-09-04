<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * La même garde, sur les slots de workflow enfant.
 *
 * Un enfant s'identifie par son **type**. Son identifiant d'exécution, lui, est engendré : le
 * comparer ferait diverger un replay parfaitement fidèle.
 *
 * Les opérations Nexus ont leur propre test, côté pont : le backend journal les refuse par
 * construction (DUR036), et y éprouver la garde serait éprouver une situation impossible.
 *
 * @see \unit\Gplanchat\Bridge\Temporal\Worker\NexusSlotDivergenceTest
 */
final class ChildWorkflowSlotDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-2425';

    public function testAChildOfAnotherTypeIsRefused(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $this->expectException(WorkflowTaskFailure::class);
        $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
    }

    public function testTheChildRefusalNamesBothTypes(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        try {
            $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
            self::fail('La divergence aurait dû être refusée.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('ChargeCardWorkflow', $message);
        self::assertStringContainsString('ReserveStockWorkflow', $message);
    }

    public function testTheMessageNamesTheSlotKind(): void
    {
        // « activity » et « child workflow » ne se cherchent pas au même endroit dans un
        // historique : confondre les deux coûte au lecteur le temps qu'il vient d'économiser.
        $context = $this->contextWithChild('ChargeCardWorkflow');

        try {
            $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
            self::fail('La divergence aurait dû être refusée.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('child workflow slot 0', $message);
        self::assertStringNotContainsString('activity slot', $message);
    }

    public function testAnUnchangedChildTypeStillReplays(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $awaitable = $context->executeChildWorkflow('ChargeCardWorkflow', ['sku' => 'ABC']);

        self::assertNotNull($awaitable, "Le type inchangé ne doit pas diverger : l'identifiant d'exécution engendré n'entre pas dans la comparaison.");
    }

    public function testTheSameChildStartedWithAnotherInputIsRefused(): void
    {
        // Le type concorde, l'input non. Sans cette garde la divergence ne remonterait jamais :
        // le journal tient déjà l'issue de l'enfant, donc rien de neuf n'est démarré et le nouvel
        // input part à la poubelle en silence.
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $this->expectException(WorkflowTaskFailure::class);
        $this->expectExceptionMessageMatches('/payload changed/');

        $context->executeChildWorkflow('ChargeCardWorkflow', ['sku' => 'XYZ']);
    }

    public function testTheInputRefusalNamesTheChildAndTheKind(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        try {
            $context->executeChildWorkflow('ChargeCardWorkflow', ['sku' => 'XYZ']);
            self::fail('La divergence de charge aurait dû être refusée.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('child workflow slot 0', $message);
        self::assertStringContainsString('"ChargeCardWorkflow" is still the same child workflow', $message);
        self::assertStringContainsString('non-deterministic workflow code', $message);
    }

    public function testAnUnchangedInputStillReplays(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $awaitable = $context->executeChildWorkflow('ChargeCardWorkflow', ['sku' => 'ABC']);

        self::assertNotNull($awaitable, 'Un replay fidèle ne doit pas diverger sur son propre input.');
    }

    private function contextWithChild(string $childType): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled(self::EXECUTION, 'child-1', $childType, ['sku' => 'ABC']));

        return $this->context($store);
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
            $this->createStub(ChildWorkflowRunnerInterface::class),
        );
    }
}
