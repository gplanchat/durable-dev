<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

/**
 * "Reachable" and "unreachable" are not enough: there is a **third** one.
 *
 * An in-memory journal answers perfectly, and its answer is empty — the request that renders the
 * dashboard has never executed a single workflow. Empty is therefore the right answer, not a
 * failure. Filed under "reachable", this case teaches the operator that no workflow has run, which
 * is false; filed under "unreachable", it sends them to restart a server that does not exist.
 *
 * The fact was already said — in prose, in the health message of the in-memory catalog, and in a
 * banner written by hand on the Magento side. A sentence is not a state: a surface cannot read it
 * to decide what to display, and the two others did not have it.
 */
final class TheThirdBackendStateTest extends TestCase
{
    public function testAJournalThatDiesWithItsProcessSaysSo(): void
    {
        $health = (new InMemoryWorkflowRunCatalog(new InMemoryEventStore()))->checkHealth();

        self::assertTrue($health->reachable, 'it answers: this is not a failure');
        self::assertTrue($health->ephemeral);
    }

    public function testItSaysWhatToConfigureToReadAcrossProcesses(): void
    {
        // Without this half, the operator knows the list is lying without knowing what to do
        // about it.
        $health = (new InMemoryWorkflowRunCatalog(new InMemoryEventStore()))->checkHealth();

        self::assertMatchesRegularExpression('/SQL|Temporal/', $health->message);
    }

    public function testABackendThatSaysNothingIsTakenToOutliveTheRequest(): void
    {
        // The default covers the three catalogs that write outside the process — SQL,
        // Illuminate, Temporal. None of them has to declare what is true of it by construction.
        $health = new BackendHealth('SQL database', true, 'The SQL database answers.', new \DateTimeImmutable());

        self::assertFalse($health->ephemeral);
    }
}
