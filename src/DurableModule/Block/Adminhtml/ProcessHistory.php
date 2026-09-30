<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Bridge\Temporal\Store\TaskQueuePollers;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * The state of the backend, what the screen counts, and how far it reads — above the grid.
 *
 * ⚠ **An empty grid says nothing on its own.** It reads the same when nothing has run, when the
 * cluster is down, and when the journal does not outlive the request. This screen did not probe: a
 * dead cluster rendered a serene empty grid here — the worse of the two possible errors, since the
 * operator concludes there is nothing to see. Three states, then, and the port already tells them
 * apart.
 *
 * The counters cover **the window this screen reads**, not the store's history, and the banner
 * says so. A label reading "total" under which one reads twenty teaches the operator that an
 * application which has recorded five hundred executions has twenty.
 *
 * ponytail: the window is read a second time here, the grid having already read its own. Two calls
 * on an admin screen, against a data provider that would have to return counters the grid chrome
 * cannot display. If it ever weighs, the way out is a request cache around the catalog, not a
 * coupling between the banner and the grid.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class ProcessHistory extends Template
{
    private ?BackendHealth $health = null;

    /** @var array<string, TaskQueuePollers>|null */
    private ?array $workers = null;

    /** @var array<string, int>|null */
    private ?array $counters = null;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getHealth(): BackendHealth
    {
        return $this->health ??= $this->runtimeFactory->catalog()->checkHealth();
    }

    /**
     * Does this host's journal live in this process? Then it was born with this request and will
     * die with it, and the grid will be empty — which is the right answer.
     *
     * The fact now comes from the port and not from `hasCluster()`: it is the in-memory catalog
     * that knows it is ephemeral, not the host guessing it from the absence of a DSN.
     */
    public function isEphemeral(): bool
    {
        return $this->getHealth()->ephemeral;
    }

    /**
     * Who polls each role's queue, when there is a cluster that answers: a missing worker leaves
     * executions stopped without a single failure, so the grid alone would look healthy. At worst
     * a cluster that answers the health check then hangs costs two 5 s probes on this render.
     *
     * @return array<string, TaskQueuePollers> keyed by `durable:worker --role`
     */
    public function getWorkers(): array
    {
        return $this->workers ??= $this->isReachable() && !$this->isEphemeral() ? $this->runtimeFactory->workers() : [];
    }

    public function getWorkerSilenceSeconds(): int
    {
        return RuntimeFactory::WORKER_SILENCE_SECONDS;
    }

    public function isReachable(): bool
    {
        return $this->getHealth()->reachable;
    }

    /**
     * One counter per outcome, over the window this screen reads.
     *
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        if ($this->counters === null) {
            $this->counters = RunDashboard::outcomeCounters(
                $this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs,
            );
        }

        return $this->counters;
    }

    public function getWindow(): int
    {
        return RuntimeFactory::OBSERVATION_WINDOW;
    }
}
