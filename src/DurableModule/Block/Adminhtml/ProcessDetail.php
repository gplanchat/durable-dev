<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * What an execution did, read from its journal.
 *
 * Nothing Magento-specific here: `readHistory()` is the same port the Sylius dashboard queries,
 * and {@see RunTimeline} is the same projection. One observation, one surface per host.
 *
 * This block once derived its own — grouping, cutting into segments, scaling, tooltips, payload
 * formatting, an action name per row. All of it now lives in the core, for a reason that was
 * measurable on the screen: Sylius stacked blocks with no position, did not tell the queue apart
 * from the work, and rendered an empty expander on a badly encoded payload. Two halves of the same
 * model, over the same journal.
 *
 * What is left here is what belongs to the host: the **scaling** — it needs to know the width of a
 * column, and the projection returns only seconds.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class ProcessDetail extends Template
{
    private ?WorkflowRunDescription $run = null;

    private bool $looked = false;

    private ?RunTimeline $timeline = null;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getRunId(): string
    {
        return (string) $this->getRequest()->getParam('run_id');
    }

    /**
     * The requested execution, or `null` if the backend does not know it — an id pasted by hand
     * into the address bar, or an execution retention has erased.
     *
     * ⚠ The window is **the grid's**, literally the same constant: two windows of different sizes
     * made it possible to be listed here and not found there.
     */
    public function getRun(): ?WorkflowRunDescription
    {
        if (!$this->looked) {
            $this->looked = true;
            $wanted = $this->getRunId();

            foreach ($this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs as $candidate) {
                if ($candidate->runId === $wanted) {
                    $this->run = $candidate;

                    break;
                }
            }
        }

        return $this->run;
    }

    /**
     * The frieze and the journal, projected once: the template reads both, and without memoing
     * this would be a second round trip to the backend for the same answer.
     */
    public function getTimeline(): RunTimeline
    {
        if ($this->timeline === null) {
            $run = $this->getRun();
            $this->timeline = RunTimeline::of(
                $run === null ? [] : $this->runtimeFactory->catalog()->readHistory($run),
            );
        }

        return $this->timeline;
    }

    /**
     * Seconds into a percentage of the track.
     *
     * It is the only thing the host decides about the frieze, and it is right that it decides it:
     * scaling needs to know the width of a column, which a projection shared with a surface that
     * renders no markup cannot know.
     *
     * With no duration — a single action, or everything in the same microsecond — everything sits
     * on the left. Spreading by rank would pass an order off as a duration.
     */
    public function scale(float $seconds): string
    {
        $span = $this->getTimeline()->span;

        return number_format($span > 0.0 ? $seconds / $span * 100.0 : 0.0, 3, '.', '');
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('durable/process/history');
    }
}
