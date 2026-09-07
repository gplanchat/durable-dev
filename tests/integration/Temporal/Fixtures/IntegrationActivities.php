<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The activities that the integration suite schedules against a real server.
 *
 * Distinct from the unit tests' contract, and not out of symmetry: the two suites live in separate
 * namespaces, and above all what goes on the wire here is observed by a Temporal server. The name
 * transmitted is the attribute's — it must not move, otherwise it is the already recorded
 * histories that stop replaying.
 *
 * The parameter name is the payload key: `ActivityStub` builds it from the parameters declared
 * here.
 */
interface IntegrationActivities
{
    #[AsActivityMethod('double')]
    public function double(int $value): int;

    #[AsActivityMethod('append')]
    public function append(string $text): string;

    #[AsActivityMethod('refund')]
    public function refund(string $order): string;

    /** Always fails: it is the subject of the FailsOnActivity, UnboundedRetry and NonRetryable workflows. */
    #[AsActivityMethod('boom')]
    public function boom(): never;
}
