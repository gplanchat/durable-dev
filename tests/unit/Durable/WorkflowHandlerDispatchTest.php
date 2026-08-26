<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

enum DispatchSignal: string
{
    case Approve = 'approve';
    case Tick = 'tick';
}

/**
 * Workflow de classe : le handler est déclaré par attribut, et l'état qu'il mute est celui que
 * le corps observe.
 */
#[Workflow('Approval')]
final class ApprovalWorkflow
{
    /** @var list<array<string, mixed>> */
    private array $approvals = [];

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[SignalMethod(DispatchSignal::Approve)]
    public function onApprove(array $payload): void
    {
        $this->approvals[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[WorkflowMethod]
    public function run(): array
    {
        $this->environment->await(fn(): bool => [] !== $this->approvals);

        return $this->approvals;
    }
}

/**
 * Dispatch des handlers de signal et d'update — bloc 3 du change
 * workflow-conditions-and-handler-dispatch.
 *
 * Note aux relectures : ce fichier est ROUGE par construction, `onSignal()` et le dispatch de
 * `#[SignalMethod]` arrivant au bloc 5. Deux exceptions assumées : le cas 3.3 passe au vert sans
 * une ligne de code neuve — un signal que personne ne consomme dort déjà dans l'historique — et
 * les deux cas d'update sont explicitement incomplets, faute d'une décision de transport qui
 * appartient au bloc 5.
 */
final class WorkflowHandlerDispatchTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 3.1 / 3.2 — les deux façons de déclarer un handler
    // -------------------------------------------------------------------------

    public function testAnAnnotatedMethodHandlesTheSignalItNames(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-1', []));
        $store->append(new WorkflowSignalReceived('disp-1', 'approve', ['by' => 'alice']));

        $factory = (new WorkflowDefinitionLoader())->load(ApprovalWorkflow::class)['factory'];

        self::assertSame([['by' => 'alice']], $engine->resume('disp-1', $factory([])));
    }

    public function testAWorkflowExpressedAsACallableRegistersTheSameHandler(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-2', []));
        $store->append(new WorkflowSignalReceived('disp-2', 'approve', ['by' => 'alice']));

        $result = $engine->resume('disp-2', static function (WorkflowEnvironment $wf): array {
            $approvals = [];
            $wf->onSignal(DispatchSignal::Approve, static function (array $payload) use (&$approvals): void {
                $approvals[] = $payload;
            });
            $wf->await(static function () use (&$approvals): bool {
                return [] !== $approvals;
            });

            return $approvals;
        });

        // Même journal, même résultat : les deux formes de déclaration produisent le même dispatch.
        self::assertSame([['by' => 'alice']], $result);
    }

    // -------------------------------------------------------------------------
    // 3.3 — un message sans handler
    // -------------------------------------------------------------------------

    public function testAMessageWithNoDeclaredHandlerIsRecordedAndIgnored(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-3', []));
        $store->append(new WorkflowSignalReceived('disp-3', 'personne-ne-l-attend', ['x' => 1]));

        self::assertSame('terminé', $engine->resume('disp-3', static fn(WorkflowEnvironment $wf): string => 'terminé'));
        self::assertCount(1, $this->eventsOf($store, 'disp-3', WorkflowSignalReceived::class));
    }

    // -------------------------------------------------------------------------
    // 3.4 / 3.5 / 3.6 — ordre, répétition, et le message arrivé trop tôt
    // -------------------------------------------------------------------------

    public function testTwoSignalsAreHandledInRecordedOrder(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-4', []));
        $store->append(new WorkflowSignalReceived('disp-4', 'tick', ['n' => 1]));
        $store->append(new WorkflowSignalReceived('disp-4', 'tick', ['n' => 2]));

        $handler = $this->accumulating(2);

        self::assertSame([['n' => 1], ['n' => 2]], $engine->resume('disp-4', $handler));
        self::assertSame([['n' => 1], ['n' => 2]], $engine->resume('disp-4', $handler), 'même ordre à chaque replay');
    }

    public function testEachDeliveryReachesTheHandlerExactlyOncePerPass(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-5', []));
        foreach ([1, 2, 3] as $n) {
            $store->append(new WorkflowSignalReceived('disp-5', 'tick', ['n' => $n]));
        }

        $calls = 0;
        $handler = static function (WorkflowEnvironment $wf) use (&$calls): int {
            $seen = 0;
            $wf->onSignal(DispatchSignal::Tick, static function (array $payload) use (&$seen, &$calls): void {
                ++$seen;
                ++$calls;
            });
            $wf->await(static function () use (&$seen): bool {
                return $seen >= 3;
            });

            return $seen;
        };

        self::assertSame(3, $engine->resume('disp-6-first', $handler), 'trois livraisons, trois appels');
        $calls = 0;
        self::assertSame(3, $engine->resume('disp-5', $handler));
        self::assertSame(3, $calls, 'un replay rejoue les trois, ni plus ni moins');
    }

    public function testADeliveryRecordedBeforeAnyWaitIsStillObserved(): void
    {
        // Le signal arrive alors que le workflow n'attend rien encore : il ne doit pas être perdu.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('disp-6', []));
        $store->append(new WorkflowSignalReceived('disp-6', 'tick', ['n' => 7]));

        $result = $engine->resume('disp-6', static function (WorkflowEnvironment $wf): array {
            $ticks = [];
            $wf->onSignal(DispatchSignal::Tick, static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            // Du travail avant la première attente.
            $wf->sideEffect(static fn(): string => 'préambule');

            $wf->await(static function () use (&$ticks): bool {
                return [] !== $ticks;
            });

            return $ticks;
        });

        self::assertSame([['n' => 7]], $result);
    }

    // -------------------------------------------------------------------------
    // 3.7 / 3.8 — les updates, en attente d'une décision de transport
    // -------------------------------------------------------------------------

    public function testAnUpdateHandlerReturnValueReachesTheCaller(): void
    {
        self::markTestIncomplete(
            'La forme d’injection d’un update entrant appartient au bloc 5 : la sonde 1.3 a montré '
            . 'qu’il arrive hors journal, accepté et complété sur la même tâche. Écrire ici un '
            . 'transport que le bloc 5 réécrira vaudrait moins qu’un test honnêtement différé.',
        );
    }

    public function testARaisingUpdateHandlerDoesNotFailTheWorkflow(): void
    {
        self::markTestIncomplete(
            'Même raison, plus un manque à combler d’abord : WorkflowUpdateHandled ne porte qu’un '
            . 'résultat, un update en échec n’a nulle part où aller. Voir design.md, point ouvert.',
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function accumulating(int $expected): callable
    {
        return static function (WorkflowEnvironment $wf) use ($expected): array {
            $ticks = [];
            $wf->onSignal(DispatchSignal::Tick, static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });
            $wf->await(static function () use (&$ticks, $expected): bool {
                return \count($ticks) >= $expected;
            });

            return $ticks;
        };
    }

    private function engine(InMemoryEventStore $store): ExecutionEngine
    {
        return new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function eventsOf(InMemoryEventStore $store, string $executionId, string $class): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof $class) {
                $out[] = $event;
            }
        }

        return $out;
    }
}
