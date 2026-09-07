<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\DurableProbe\Workflow\Activity\EveryCaseActivities;

/**
 * An execution that contains **every case the observation screen has to know how to show**.
 *
 * The bench only had a happy path: three activities that succeed, no timer, no child, no failure.
 * A screen that has never seen a failure has never proved it knew how to show one, and a timeline
 * validated on a single shape of action proves nothing about the next. This one is the acceptance
 * vehicle: what it produces is what the page has to make legible.
 *
 * What it contains, and why:
 * - an activity that **succeeds** — the reference row;
 * - an **unstable** activity, two failures then a success: an action that carries red *and* ends
 *   well, which proves the colour marks the event and not the whole action;
 *   ⚠ **it only retries on the in-memory backend** — on Temporal the three attempts are consumed
 *   in two seconds without the activity's code being called back. It is this probe that found it,
 *   and the fact is reported in issue #218: here it is not worked around;
 * - a five-second **timer**, which has to announce its duration without anyone having to subtract
 *   two timestamps;
 * - a **child workflow** that succeeds and another that fails, on separate rows;
 * - a **doomed** activity, whose failure is final and is caught here: the execution finishes, and
 *   the failure stays visible in its journal.
 *
 * ⚠ **Two cases are missing, and that is deliberate.** A *signal* needs a sender, and the host's
 * runtime exposes no send — the probe runs unattended, so nothing would send it one. A *Nexus*
 * operation needs **two applications facing each other**: it is in {@see OrderNexusWorkflow},
 * which calls the Sylius and Symfony mockups, and it stays there. Putting it here would make this
 * probe impossible to start on its own — it would wait for two workers that only run under
 * `demo/run.sh`. So a journal containing the three Nexus events does exist, it just carries
 * another execution name.
 */
final class EveryCaseWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @return array<string, string>
     */
    #[AsWorkflowMethod]
    public function run(string $caseId = 'CASE-1'): array
    {
        $steady = $this->environment->activityStub(EveryCaseActivities::class);
        // Three attempts, one second between each: enough for the wait between two attempts to
        // be a visible interval, short enough not to keep the operator waiting.
        $patient = $this->environment->activityStub(
            EveryCaseActivities::class,
            ActivityOptions::of(retryLimit: RetryLimit::ofAttempts(3), initialInterval: 1.0, backoffCoefficient: 1.0),
        );
        // A single attempt: the failure is final, and it is final right away.
        $hopeless = $this->environment->activityStub(
            EveryCaseActivities::class,
            ActivityOptions::of(retryLimit: RetryLimit::once()),
        );

        $trace = [];
        $trace['succeed'] = $this->environment->await($steady->succeed($caseId));

        $this->environment->sleep(Duration::seconds(5), 'cooling down before retry');
        $trace['timer'] = 'slept 5 s';

        // Caught as well, and not on principle: on the in-memory backend it retries and returns
        // "recovered after 3 attempts", on Temporal it fails. Letting it propagate would make the
        // execution die here and would deprive the page of the four following cases — and the page
        // is precisely what we have come to judge. The difference between the two backends is a
        // fact this probe found, not a reason to show only one backend.
        $trace['flaky'] = $this->caught(fn(): mixed => $this->environment->await($patient->flaky($caseId)));

        $trace['child'] = $this->environment->await(
            $this->environment->childWorkflowStub(EveryCaseChildWorkflow::class)->run($caseId . '-ok'),
        );

        // Caught, both of them: what the page has to show is a failure **inside** an execution
        // that finishes. An execution that dies on the first error would have only one red row,
        // the last one, and would teach nothing about the layout of the rest.
        $trace['failing child'] = $this->caught(fn(): mixed => $this->environment->await(
            $this->environment->childWorkflowStub(EveryCaseChildWorkflow::class)->run($caseId . '-ko', true),
        ));

        $trace['doomed'] = $this->caught(fn(): mixed => $this->environment->await($hopeless->doomed($caseId)));

        return $trace;
    }

    private function caught(\Closure $attempt): string
    {
        try {
            return (string) $attempt();
        } catch (\Throwable $failure) {
            return 'caught: ' . $failure->getMessage();
        }
    }
}
