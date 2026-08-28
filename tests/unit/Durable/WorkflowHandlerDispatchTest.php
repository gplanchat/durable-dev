<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\PendingUpdate;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

enum DispatchSignal: string
{
    case Approve = 'approve';
    case Tick = 'tick';
}

/**
 * AsWorkflow de classe : le handler est déclaré par attribut, et l'état qu'il mute est celui que
 * le corps observe.
 */
#[AsWorkflow('Approval')]
final class ApprovalWorkflow
{
    /** @var list<array<string, mixed>> */
    private array $approvals = [];

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsSignalMethod(DispatchSignal::Approve)]
    public function onApprove(array $payload): void
    {
        $this->approvals[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[AsWorkflowMethod]
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
 * `#[AsSignalMethod]` arrivant au bloc 5. Deux exceptions assumées : le cas 3.3 passe au vert sans
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

        self::assertSame(3, $engine->resume('disp-5', $handler), 'trois livraisons, trois appels');
        self::assertSame(3, $calls);

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
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $store->append(new ExecutionStarted('upd-1', []));

        $handler = static function (WorkflowEnvironment $wf): string {
            $approved = false;
            $wf->onUpdate('approve', static function (array $args) use (&$approved): array {
                $approved = true;

                return ['ok' => true, 'by' => $args['by']];
            });
            $wf->await(static function () use (&$approved): bool {
                return $approved;
            });

            return 'terminé';
        };

        // L'update arrive hors journal, pour cette passe seulement — comme sur la tâche Temporal.
        $pending = new PendingUpdate('approve', ['by' => 'alice']);

        self::assertSame('terminé', $engine->resume('upd-1', $handler, null, [$pending]));
        self::assertTrue($pending->handled);
        self::assertSame(['ok' => true, 'by' => 'alice'], $pending->result);
        self::assertNull($pending->failure);

        // L'appelant consigne l'issue, comme le serveur écrit UPDATE_COMPLETED après la réponse
        // du worker.
        $store->append(new WorkflowUpdateHandled('upd-1', 'approve', ['by' => 'alice'], $pending->result));

        // Rejoué sans update en attente : l'update est relu du journal, le handler refait l'état,
        // et le workflow reprend le même chemin.
        self::assertSame('terminé', $engine->resume('upd-1', $handler));
    }

    public function testARaisingUpdateHandlerDoesNotFailTheWorkflow(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $store->append(new ExecutionStarted('upd-2', []));

        $result = $engine->resume('upd-2', static function (WorkflowEnvironment $wf): string {
            $attempts = 0;
            $wf->onUpdate('approve', static function (array $args) use (&$attempts): never {
                ++$attempts;

                throw new \DomainException('approbation refusée');
            });
            $wf->await(static function () use (&$attempts): bool {
                return $attempts > 0;
            });

            return 'le workflow continue';
        }, null, [$pending = new PendingUpdate('approve', ['by' => 'alice'])]);

        self::assertSame('le workflow continue', $result, 'un update en échec ne fait pas échouer l’exécution');
        self::assertTrue($pending->handled);
        self::assertNotNull($pending->failure);
        self::assertSame(\DomainException::class, $pending->failure->class);
        self::assertSame('approbation refusée', $pending->failure->message);
    }

    public function testAFailedUpdateReplayedDoesNotFailTheWorkflow(): void
    {
        // Le chemin que seul un vrai serveur avait révélé : au replay, le handler d'un update en
        // échec rejoue et relève de nouveau. Sa défaillance est déjà partie chez l'appelant —
        // la laisser remonter ferait échouer une exécution que l'original avait laissée vivante.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('upd-3', []));
        $store->append(new WorkflowUpdateHandled(
            'upd-3',
            'refuse',
            [],
            null,
            new FailureEnvelope(\DomainException::class, 'approbation refusée'),
        ));
        $store->append(new WorkflowSignalReceived('upd-3', 'tick', ['n' => 1]));

        $result = $engine->resume('upd-3', static function (WorkflowEnvironment $wf): string {
            $ticks = [];
            $wf->onUpdate('refuse', static function (array $args): never {
                throw new \DomainException('approbation refusée');
            });
            $wf->onSignal(DispatchSignal::Tick, static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });
            $wf->await(static function () use (&$ticks): bool {
                return [] !== $ticks;
            });

            return 'toujours vivant';
        });

        self::assertSame('toujours vivant', $result);
    }

    public function testTheOutcomeIsRecordedBeforeWhatTheWorkflowDoesInResponse(): void
    {
        // L'ordre, et pas seulement le contenu : l'issue doit précéder ce que le workflow fait
        // en réponse, sinon un replay les appliquerait dans l'autre sens. C'est l'ordre que
        // Temporal produit — l'acceptation avant les commandes du workflow (ADR DUR035).
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $store->append(new ExecutionStarted('upd-4', []));

        $engine->resume('upd-4', static function (WorkflowEnvironment $wf): string {
            $approved = false;
            $wf->onUpdate('approve', static function (array $args) use (&$approved): string {
                $approved = true;

                return 'ok';
            });
            $wf->await(static function () use (&$approved): bool {
                return $approved;
            });

            // Ce que le workflow fait *parce que* l'update est passé.
            $wf->sideEffect(static fn(): string => 'après');

            return 'terminé';
        }, null, [new PendingUpdate('approve', ['by' => 'alice'])]);

        $order = [];
        foreach ($store->readStream('upd-4') as $event) {
            $order[] = (new \ReflectionClass($event))->getShortName();
        }

        $update = array_search('WorkflowUpdateHandled', $order, true);
        $effect = array_search('SideEffectRecorded', $order, true);
        self::assertIsInt($update);
        self::assertIsInt($effect);
        self::assertLessThan($effect, $update, 'l’issue de l’update précède ce qu’elle a débloqué');
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
