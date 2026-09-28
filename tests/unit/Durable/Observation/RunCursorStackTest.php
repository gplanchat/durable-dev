<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RunCursorStack;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use PHPUnit\Framework\TestCase;

/**
 * The way back through a run listing (#383): what the conformance suite cannot reach in a few runs.
 */
final class RunCursorStackTest extends TestCase
{
    public function testAPastTheCapPageLeadsBackToTheFirstPage(): void
    {
        [$current, $stack] = [null, []];
        // One page past the cap: the stack starts again from the first page there.
        for ($page = 1; $page <= RunCursorStack::MAX_DEPTH + 1; ++$page) {
            $next = RunCursorStack::page(new WorkflowRunPage([], 'token-' . $page), $current, $stack)->nextCursor;
            [$current, $stack] = RunCursorStack::open($next);
        }

        self::assertLessThanOrEqual(RunCursorStack::MAX_DEPTH, \count($stack), 'the cursor stops growing');
        $previous = RunCursorStack::page(new WorkflowRunPage([]), $current, $stack)->previousCursor;
        [$back, $backStack] = RunCursorStack::open($previous);
        $backPrevious = RunCursorStack::page(new WorkflowRunPage([]), $back, $backStack)->previousCursor;
        self::assertNotNull($previous);
        self::assertNull($back, 'past the cap, the way back is the first page');
        self::assertNull($backPrevious, 'and there is no further back');
    }

    public function testACursorStaysShortEnoughForAUrl(): void
    {
        // A Temporal page token is ~130 bytes (measured on 1.20 and 1.32); fifty of them stacked
        // would pass the 8 KB request line of nginx and Apache. Past the byte cap, as past the page
        // cap, the stack starts again from the first page.
        [$current, $stack] = [null, []];
        for ($page = 1; $page <= RunCursorStack::MAX_DEPTH; ++$page) {
            $next = RunCursorStack::page(new WorkflowRunPage([], base64_encode(random_bytes(130))), $current, $stack)->nextCursor;
            self::assertNotNull($next);
            self::assertLessThanOrEqual(RunCursorStack::MAX_BYTES, \strlen($next), \sprintf('page %d', $page));
            [$current, $stack] = RunCursorStack::open($next);
        }
    }

    public function testACursorThatDoesNotDecodeIsTheFirstPage(): void
    {
        self::assertSame([null, []], RunCursorStack::open('r1.not-base64-json'));
        self::assertSame([null, []], RunCursorStack::open(null));
        self::assertSame([null, []], RunCursorStack::open(''));
    }

    public function testACatalogCursorFromBeforeTheStackStillWorks(): void
    {
        // A link bookmarked before #383 carries the catalog's bare cursor: it lists that page, with
        // no way back rather than a wrong one.
        self::assertSame(['bare-token', []], RunCursorStack::open('bare-token'));
    }
}
