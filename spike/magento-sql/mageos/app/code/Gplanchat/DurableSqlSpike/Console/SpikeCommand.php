<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Console;

use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\DurableSqlSpike\Runtime\SqlRuntime;
use Magento\Framework\App\DeploymentConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento durable-sql:spike <action> [args...]
 *   setup | start <id> [pause] [timer] | signal <id> <by> | work [seconds] | status <id> | nexus
 */
class SpikeCommand extends Command
{
    private ?SqlRuntime $runtime = null;

    public function __construct(private readonly DeploymentConfig $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable-sql:spike')
            ->addArgument('action', InputArgument::REQUIRED)
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runtime = new SqlRuntime($this->config);
        $a = $input->getArgument('args');
        $say = static fn(string $line) => $output->writeln(date('H:i:s') . ' pid=' . getmypid() . ' ' . $line);

        switch ($input->getArgument('action')) {
            case 'setup':
                $this->runtime->setup();
                $say('tables: ' . implode(', ', $this->runtime->connection->createSchemaManager()->listTableNames()));
                break;
            case 'start':
                // An id starting with "nexus-" starts the workflow that calls a Nexus operation.
                $nexus = str_starts_with($a[0], 'nexus-');
                $this->runtime->queue->dispatchNewWorkflowRun(ExecutionId::fromString($a[0]), $nexus ? 'SpikeNexus' : 'SpikeOrder', $nexus ? [] : [
                    'orderId' => $a[0], 'pauseSeconds' => (int) ($a[1] ?? 0), 'timerSeconds' => (int) ($a[2] ?? 5),
                ]);
                $say("started {$a[0]}");
                break;
            case 'signal':
                // DeliverWorkflowSignalHandler's body: the bundle's handler is not reachable without Messenger.
                $m = new DeliverWorkflowSignalMessage($a[0], 'approve', ['by' => $a[1] ?? 'ops']);
                $this->runtime->queue->dispatchResumeAwaiting(ExecutionId::fromString($a[0]), AwaitedFact::signal($m->requestId));
                $this->runtime->events->append(new WorkflowSignalReceived($m->executionId, $m->signalName, $m->payload, $m->requestId));
                $this->runtime->queue->dispatchResume(ExecutionId::fromString($a[0]));
                $say("signalled {$a[0]}");
                break;
            case 'work':
                $this->work((float) ($a[0] ?? 60), $say);
                break;
            case 'status':
                $this->status($a[0], $output);
                break;
            case 'nexus':
                try {
                    $this->runtime->nexus->register(NexusService::from('orders'), NexusOperationName::from('reserve'), static fn() => null);
                    $say('REGISTERED — the refusal did not happen');
                } catch (\Throwable $e) {
                    $say('register(): ' . $e::class . ': ' . $e->getMessage());
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown action.');
        }

        return 0;
    }

    private function work(float $seconds, \Closure $say): void
    {
        $r = $this->runtime;
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $taken = $r->queue->take();
            if (null === $taken) {
                usleep(200_000);
                continue;
            }
            ['id' => $id, 'message' => $message, 'deliveries' => $n] = $taken;
            $label = match (true) {
                $message instanceof ActivityMessage => "activity {$message->activityName}#{$message->attempt}",
                $message instanceof FireWorkflowTimersMessage => 'fire-timers',
                default => 'resume' . ($message->awaited ? ' awaiting' : ''),
            };
            $say("take #$id $label {$message->executionId} delivery=$n");
            if ($message instanceof ActivityMessage) {
                try {
                    $failure = $r->activities->process($message);
                    $say('  activity done' . ($failure ? ' with failure ' . $failure->getMessage() : ''));
                } catch (ActivityAttemptDeferred) {
                    $r->queue->push($message, ActivityAttemptDeferred::RETRY_AFTER_SECONDS);
                    $say('  attempt held by another worker: deferred');
                }
                $r->queue->ack($id);
                continue;
            }
            $lock = $r->locks->createLock('durable-resume-' . $message->executionId, SqlRuntime::LOCK_TTL);
            if (!$lock->acquire(false)) {
                $r->queue->push($message, 1.0);
                $r->queue->ack($id);
                $say('  resume lock held: deferred 1s');
                continue;
            }
            try {
                $message instanceof FireWorkflowTimersMessage ? ($r->timers)($message) : ($r->resume)($message);
                $say('  replayed');
            } catch (ResumeArrivedBeforeItsOutcome) {
                $r->queue->push($message, 0.5);
                $say('  arrived before its outcome (DUR050): deferred');
            } catch (\Throwable $e) {
                $say('  FAILED ' . $e::class . ': ' . $e->getMessage());
            } finally {
                $lock->release();
            }
            $r->queue->ack($id);
        }
    }

    private function status(string $executionId, OutputInterface $output): void
    {
        $c = $this->runtime->connection;
        foreach ($c->fetchAllAssociative('SELECT event_type, COUNT(*) n FROM durable_events WHERE execution_id = ? GROUP BY event_type ORDER BY MIN(id)', [$executionId]) as $row) {
            $output->writeln(sprintf('  %-32s %d', $row['event_type'], $row['n']));
        }
        $output->writeln('  run: ' . json_encode($c->fetchAssociative('SELECT status, waiting_on FROM durable_workflow_runs WHERE execution_id = ?', [$executionId])));
        $output->writeln('  epoch (DUR053 passes): ' . $c->fetchOne('SELECT epoch FROM durable_execution_heads WHERE execution_id = ?', [$executionId]));
        $output->writeln('  queue rows: ' . $this->runtime->queue->count());
    }
}
