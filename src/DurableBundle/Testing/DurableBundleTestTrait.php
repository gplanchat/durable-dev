<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Testing;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Uuid\NativeUuidV7Generator;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Trait PHPUnit pour les tests d'intégration Symfony avec le DurableBundle.
 *
 * À utiliser dans une classe qui étend {@see \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase}.
 *
 * Pré-requis dans `config/packages/messenger.yaml` (env test) : transports Messenger in-memory
 * (ex. `durable_workflows: 'in-memory://'`, `durable_activities: 'in-memory://'`).
 *
 * Usage :
 * ```php
 * final class MyWorkflowIntegrationTest extends KernelTestCase
 * {
 *     use DurableBundleTestTrait;
 *
 *     public function testGreetWorkflow(): void
 *     {
 *         self::bootKernel();
 *         $executionId = $this->dispatchWorkflow(
 *             MyGreetWorkflow::class,
 *             ['name' => 'World'],
 *         );
 *         $this->drainMessengerUntilSettled($executionId);
 *         $this->assertWorkflowResultEquals($executionId, 'Hello, World!');
 *     }
 * }
 * ```
 */
trait DurableBundleTestTrait
{
    /** @var list<string> Noms des transports Messenger à vider en mode test */
    private static array $durableWorkflowTransports = ['durable_workflows', 'durable_activities'];

    /** Durée max du drain (secondes) avant de déclarer l'échec */
    private static float $durableMaxDrainSeconds = 30.0;

    /**
     * Dispatch un workflow via le bus Messenger et retourne son executionId.
     *
     * Le workflow est identifié par sa classe PHP. Le type (alias) est résolu via
     * {@see WorkflowDefinitionLoader}.
     *
     * @param class-string $workflowClass
     * @param array<string, mixed> $input
     */
    protected function dispatchWorkflow(string $workflowClass, array $input = [], ?string $executionId = null): string
    {
        $executionId ??= (new NativeUuidV7Generator())->generate();
        $loader = new WorkflowDefinitionLoader();
        $workflowType = $loader->workflowTypeForClass($workflowClass);

        $this->getWorkflowResumeDispatcher()->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    /**
     * Vide les transports Messenger (durable_workflows + durable_activities) jusqu'à ce que le
     * workflow identifié par $executionId soit terminé ou que le timeout soit atteint.
     *
     * À appeler après {@see dispatchWorkflow} dans un test en mode in-memory Messenger.
     *
     * @throws \RuntimeException si le workflow ne se termine pas dans le délai imparti
     */
    protected function drainMessengerUntilSettled(string $executionId): void
    {
        $eventStore = $this->getEventStoreService();
        $metadataStore = $this->getWorkflowMetadataStore();
        $messageBus = $this->getMessageBus();
        $receiverLocator = $this->getMessengerReceiverLocator();

        $t0 = microtime(true);
        $hadMessage = false;
        $idleStreak = 0;

        while (microtime(true) - $t0 < self::$durableMaxDrainSeconds) {
            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return;
            }

            $worked = $this->drainTransports($receiverLocator, $messageBus, $hadMessage);

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return;
            }

            if ($worked) {
                $idleStreak = 0;

                continue;
            }

            ++$idleStreak;
            if ($hadMessage && $idleStreak > 30 && !$metadataStore->hasActiveWorkflowMetadata($executionId)) {
                break;
            }

            if ($metadataStore->hasActiveWorkflowMetadata($executionId)) {
                usleep(100_000);
            } else {
                usleep(1_000);
            }
        }

        // Vérification finale
        if (null === WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
            // On autorise l'échec du workflow (WorkflowExecutionFailed)
            $hasFailed = false;
            foreach ($eventStore->readStream($executionId) as $event) {
                if ($event instanceof WorkflowExecutionFailed) {
                    $hasFailed = true;
                    break;
                }
            }
            if (!$hasFailed) {
                throw new \RuntimeException(
                    \sprintf(
                        'Le workflow "%s" ne s\'est pas terminé dans le délai imparti (%ss).',
                        $executionId,
                        self::$durableMaxDrainSeconds,
                    ),
                );
            }
        }
    }

    /**
     * Vérifie que le workflow s'est terminé avec le résultat attendu.
     */
    protected function assertWorkflowResultEquals(string $executionId, mixed $expectedResult): void
    {
        $eventStore = $this->getEventStoreService();
        $completed = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $completed = $event;
                break;
            }
        }
        Assert::assertNotNull(
            $completed,
            \sprintf('Le workflow "%s" ne s\'est pas terminé (aucun ExecutionCompleted dans le journal).', $executionId),
        );
        Assert::assertEquals(
            $expectedResult,
            $completed->result(),
            \sprintf('Le résultat du workflow "%s" ne correspond pas à l\'attendu.', $executionId),
        );
    }

    /**
     * Vérifie que le workflow a échoué.
     *
     * @param class-string<\Throwable>|'' $expectedFailureClass
     */
    protected function assertWorkflowFailed(string $executionId, string $expectedFailureClass = ''): void
    {
        $eventStore = $this->getEventStoreService();
        $failed = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                $failed = $event;
                break;
            }
        }
        Assert::assertNotNull(
            $failed,
            \sprintf('Le workflow "%s" n\'a pas échoué (aucun WorkflowExecutionFailed dans le journal).', $executionId),
        );

        if ('' !== $expectedFailureClass) {
            Assert::assertSame(
                $expectedFailureClass,
                $failed->failureClass(),
                \sprintf('La classe d\'échec du workflow "%s" ne correspond pas.', $executionId),
            );
        }
    }

    /**
     * Retourne le DataCollector Durable (panneau profiler).
     *
     * Nécessite que le kernel soit en mode debug et que le profiler soit activé.
     */
    protected function getDataCollector(): DurableDataCollector
    {
        return static::getContainer()->get(DurableDataCollector::class);
    }

    /**
     * Retourne l'EventStore du container de test.
     */
    protected function getEventStoreService(): EventStoreInterface
    {
        return static::getContainer()->get(EventStoreInterface::class);
    }

    private function getWorkflowResumeDispatcher(): WorkflowResumeDispatcher
    {
        return static::getContainer()->get(WorkflowResumeDispatcher::class);
    }

    private function getWorkflowMetadataStore(): WorkflowMetadataStore
    {
        return static::getContainer()->get(WorkflowMetadataStore::class);
    }

    private function getMessageBus(): MessageBusInterface
    {
        return static::getContainer()->get('messenger.default_bus');
    }

    private function getMessengerReceiverLocator(): ContainerInterface
    {
        return static::getContainer()->get('messenger.receiver_locator');
    }

    private function drainTransports(ContainerInterface $receiverLocator, MessageBusInterface $messageBus, bool &$hadMessage): bool
    {
        $worked = false;
        foreach (static::$durableWorkflowTransports as $transportName) {
            if (!$receiverLocator->has($transportName)) {
                continue;
            }
            $receiver = $receiverLocator->get($transportName);
            if (!$receiver instanceof TransportInterface) {
                continue;
            }
            foreach ($receiver->get() as $envelope) {
                try {
                    $messageBus->dispatch($envelope->with(new ReceivedStamp($transportName)));
                    $receiver->ack($envelope);
                } catch (\Throwable $e) {
                    $receiver->reject($envelope);
                    throw $e;
                }
                $hadMessage = true;
                $worked = true;
            }
        }

        return $worked;
    }
}
