<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Live\AgentLiveFeed;
use App\Ai\Live\AgentSignals;
use App\Ai\Watch\BusinessEvent;
use App\Ai\Watch\WatchRouter;
use App\Ai\Watch\WatchSubject;
use App\Ai\Watch\WatchTool;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Le fait métier trouve-t-il les agents qui l'attendaient ?
 *
 * Sans Temporal : le catalogue est un bouchon, le journal un `InMemoryEventStore`. C'est
 * l'appariement qu'on teste — la partie qui décide —, pas le transport, qui a déjà été vu tourner.
 */
#[CoversClass(WatchRouter::class)]
final class WatchRouterTest extends TestCase
{
    /** @var list<array{executionId: string, signal: string, payload: array<string, mixed>}> */
    private array $envoyes = [];

    private InMemoryEventStore $eventStore;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->envoyes = [];
    }

    public function testItWakesOnlyTheWatchesOfThatSubject(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::CommandeExpediee);
        $this->armWatch('exec-b', 'w2', WatchSubject::PaiementRecu);

        $reveilles = $this->router(['exec-a', 'exec-b'])->dispatch(new BusinessEvent(WatchSubject::CommandeExpediee));

        self::assertSame(['exec-a'], $reveilles);
        self::assertCount(1, $this->envoyes);
        self::assertSame('alerte', $this->envoyes[0]['signal']);
        self::assertSame('w1', $this->envoyes[0]['payload']['callId']);
    }

    /**
     * Un fait n'appartient à personne : deux conversations qui attendent la même chose la
     * reçoivent toutes les deux.
     */
    public function testEveryExecutionWatchingTheSubjectIsWoken(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::ImportTermine);
        $this->armWatch('exec-b', 'w2', WatchSubject::ImportTermine);

        $reveilles = $this->router(['exec-a', 'exec-b'])->dispatch(new BusinessEvent(WatchSubject::ImportTermine));

        self::assertSame(['exec-a', 'exec-b'], $reveilles);
    }

    /**
     * Les précisions de l'événement font le texte que l'agent relira ; le sujet, lui, ne sert qu'à
     * l'appariement.
     */
    public function testTheEventDetailsReachTheAgentAsReadableText(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::CommandeExpediee);

        $this->router(['exec-a'])->dispatch(new BusinessEvent(WatchSubject::CommandeExpediee, ['numero' => 'CMD-42']));

        self::assertStringContainsString('CMD-42', $this->envoyes[0]['payload']['observation']);
    }

    public function testAnExecutionWatchingNothingIsLeftAlone(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::CommandeExpediee);

        $this->router(['exec-a'])->dispatch(new BusinessEvent(WatchSubject::StockReappro));

        self::assertSame([], $this->envoyes);
    }

    /**
     * Le catalogue rend l'identifiant Temporal, la projection veut celui de l'exécution : sans le
     * retrait du préfixe, on lirait le journal d'une exécution qui n'existe pas.
     */
    public function testARunWithoutTheTemporalPrefixIsIgnored(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::CommandeExpediee);

        $catalogue = $this->catalog([new WorkflowRunDescription(
            runId: 'r1',
            workflowName: DurableAgentWorkflow::TYPE,
            status: WorkflowRunStatus::Running,
            groupId: 'exec-a',
        )]);

        (new WatchRouter($this->transcript(), $this->signals(), $catalogue))
            ->dispatch(new BusinessEvent(WatchSubject::CommandeExpediee));

        self::assertSame([], $this->envoyes);
    }

    public function testAnotherWorkflowTypeIsNotScanned(): void
    {
        $this->armWatch('exec-a', 'w1', WatchSubject::CommandeExpediee);

        $catalogue = $this->catalog([new WorkflowRunDescription(
            runId: 'r1',
            workflowName: 'Samples_BookingSaga',
            status: WorkflowRunStatus::Running,
            groupId: 'durable-exec-a',
        )]);

        (new WatchRouter($this->transcript(), $this->signals(), $catalogue))
            ->dispatch(new BusinessEvent(WatchSubject::CommandeExpediee));

        self::assertSame([], $this->envoyes);
    }

    /**
     * Une veille est lisible au journal dès que l'appel d'outil y est planifié : la projection la
     * reconstruit de là, comme le fait le fil.
     */
    private function armWatch(string $executionId, string $callId, WatchSubject $subject): void
    {
        $this->eventStore->append(new ActivityScheduled($executionId, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'user', 'content' => 'Surveille']]],
        ]));
        $this->eventStore->append(new ActivityCompleted($executionId, 'a1', [
            'choices' => [['message' => ['content' => null, 'tool_calls' => [[
                'id' => $callId,
                'type' => 'function',
                'function' => ['name' => WatchTool::TOOL, 'arguments' => json_encode([
                    'sujet' => $subject->value,
                    'observation' => 'Ce que je guette',
                    'intention' => 'Ce que j’en ferai',
                ], \JSON_UNESCAPED_UNICODE)],
            ]]], 'finish_reason' => 'tool_calls']],
        ]));
    }

    /**
     * @param list<string> $executionIds
     */
    private function router(array $executionIds): WatchRouter
    {
        return new WatchRouter($this->transcript(), $this->signals(), $this->catalog(array_map(
            static fn (string $id): WorkflowRunDescription => new WorkflowRunDescription(
                runId: 'run-' . $id,
                workflowName: DurableAgentWorkflow::TYPE,
                status: WorkflowRunStatus::Running,
                groupId: 'durable-' . $id,
            ),
            $executionIds,
        )));
    }

    private function transcript(): ChatTranscript
    {
        return new ChatTranscript($this->eventStore, new InMemoryWorkflowMetadataStore());
    }

    /**
     * Le vrai {@see AgentSignals}, avec un bus qui enregistre et le `MockHub` du composant : ce
     * qu'on veut voir, c'est le message que le chemin réel produit, pas celui qu'un bouchon aurait
     * bien voulu rendre.
     */
    private function signals(): AgentSignals
    {
        $bus = new class($this->envoyes) implements MessageBusInterface {
            /** @param list<array{executionId: string, signal: string, payload: array<string, mixed>}> $envoyes */
            public function __construct(private array &$envoyes)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof DeliverWorkflowSignalMessage) {
                    $this->envoyes[] = [
                        'executionId' => $message->executionId,
                        'signal' => $message->signalName,
                        'payload' => $message->payload,
                    ];
                }

                return new Envelope($message);
            }
        };

        $hub = new MockHub('http://hub.test/.well-known/mercure', new StaticTokenProvider('jeton'), static fn (Update $u): string => 'urn:uuid:test');

        return new AgentSignals($bus, new AgentLiveFeed($hub));
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     */
    private function catalog(array $runs): WorkflowRunCatalogInterface
    {
        return new class($runs) implements WorkflowRunCatalogInterface {
            /** @param list<WorkflowRunDescription> $runs */
            public function __construct(private readonly array $runs) {}

            public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
            {
                return new WorkflowRunPage($this->runs);
            }

            public function readHistory(WorkflowRunDescription $run): array { return []; }

            public function checkHealth(): BackendHealth
            {
                return new BackendHealth('test', true, 'bouchon', new \DateTimeImmutable());
            }
        };
    }
}
