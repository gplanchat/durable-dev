<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowNamespace;
use PHPUnit\Framework\TestCase;

/**
 * Unlike {@see TaskQueue}, a namespace mistake does not stay silent: the server answers
 * `NOT_FOUND`. What it brings is therefore mostly the typing — namespace and task queue were two
 * neighbouring strings in the same constructors.
 *
 * Server verdicts probed: only "non-empty" is required; spaces, capitals, accents, tabulations
 * and more than 255 characters are accepted. But the comparison is made byte for byte —
 * `DURABLE-TEST` and `durable-test ` are not found when `durable-test` exists.
 */
final class WorkflowNamespaceTest extends TestCase
{
    public function testANameIsCarriedVerbatim(): void
    {
        self::assertSame('durable-test', WorkflowNamespace::named('durable-test')->name());
        self::assertSame('durable-test', (string) WorkflowNamespace::named('durable-test'));
    }

    public function testComparisonIsCaseSensitiveLikeTheServer(): void
    {
        // Probed: starting in "DURABLE-TEST" when "durable-test" exists gives NOT_FOUND.
        self::assertFalse(WorkflowNamespace::named('durable-test')->equals(WorkflowNamespace::named('DURABLE-TEST')));
        self::assertTrue(WorkflowNamespace::named('durable-test')->equals(WorkflowNamespace::named('durable-test')));
    }

    public function testEmptyAndBlankAreRejected(): void
    {
        $this->expectExceptionMessageMatches('/cannot be empty/');
        WorkflowNamespace::named('');
    }

    public function testEdgeWhitespaceIsRejected(): void
    {
        // Probed: "durable-test " (with a trailing space) is another namespace, hence not found.
        $this->expectExceptionMessageMatches('/byte for byte/');

        WorkflowNamespace::named('durable-test ');
    }

    public function testAControlCharacterIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/control character/');

        WorkflowNamespace::named("durable\ttest");
    }

    public function testTheSystemNamespaceIsRecognised(): void
    {
        self::assertTrue(WorkflowNamespace::named('temporal-system')->isSystem());
        self::assertFalse(WorkflowNamespace::named('durable-test')->isSystem());
    }

    public function testANamespaceAndATaskQueueCanNoLongerBeSwapped(): void
    {
        // This is the main gain: the two were neighbouring strings in the same constructors, and
        // swapping them only showed at run time, server-side.
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line intentional: this is the point of the test */
        new TemporalConnection(target: 'localhost:7233', namespace: TaskQueue::named('durable-activities'));
    }

    public function testChildOptionsRoundTripTheNamespace(): void
    {
        $options = new ChildWorkflowOptions(namespace: WorkflowNamespace::named('other-tenant'));

        self::assertSame('other-tenant', $options->toSchedulingMetadata()['namespace']);
    }

    public function testConnectionAcceptsEitherFormAndValidatesIt(): void
    {
        self::assertSame(
            'durable-test',
            (new TemporalConnection(target: 'localhost:7233', namespace: 'durable-test'))->namespace->name(),
        );

        $this->expectExceptionMessageMatches('/byte for byte/');
        new TemporalConnection(target: 'localhost:7233', namespace: ' durable-test');
    }
}
