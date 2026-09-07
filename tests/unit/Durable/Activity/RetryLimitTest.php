<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use PHPUnit\Framework\TestCase;

/**
 * The domain question — "is this attempt still allowed?" — is asked of the object, no longer of
 * every call site that had to retranslate a magic 0.
 */
final class RetryLimitTest extends TestCase
{
    public function testUnlimitedAllowsEveryAttempt(): void
    {
        $limit = RetryLimit::unlimited();

        self::assertTrue($limit->isUnlimited());
        self::assertNull($limit->maxAttempts());
        self::assertTrue($limit->allowsAttempt(1));
        self::assertTrue($limit->allowsAttempt(10_000));
    }

    public function testBoundedLimitCountsTotalAttempts(): void
    {
        $limit = RetryLimit::ofAttempts(3);

        self::assertSame(3, $limit->maxAttempts());
        self::assertTrue($limit->allowsAttempt(3));
        self::assertFalse($limit->allowsAttempt(4), 'ofAttempts(3) = 3 executions, not 4');
    }

    public function testOnceForbidsAnyRetry(): void
    {
        self::assertTrue(RetryLimit::once()->allowsAttempt(1));
        self::assertFalse(RetryLimit::once()->allowsAttempt(2));
    }

    public function testRetriesVocabularyAddsTheInitialAttempt(): void
    {
        self::assertSame(3, RetryLimit::ofRetries(2)->maxAttempts(), '2 retries = 3 attempts');
        self::assertTrue(RetryLimit::ofRetries(0)->isUnlimited(), 'no cap, not "a single attempt"');
    }

    public function testABoundBelowOneAttemptIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one attempt/');

        RetryLimit::ofAttempts(0);
    }

    public function testWireValueKeepsTheTemporalEncoding(): void
    {
        // 0 = unlimited on the wire: it is the server's language, and it travels in the history
        // of the executions in flight — the PHP model must not change it.
        self::assertSame(0, RetryLimit::unlimited()->toWireValue());
        self::assertSame(5, RetryLimit::ofAttempts(5)->toWireValue());
        self::assertTrue(RetryLimit::fromWireValue(0)->isUnlimited());
        self::assertSame(5, RetryLimit::fromWireValue(5)->maxAttempts());
        self::assertTrue(RetryLimit::fromWireValue(-1)->isUnlimited());
    }

    public function testNarrowingKeepsTheStricterBound(): void
    {
        $three = RetryLimit::ofAttempts(3);
        $five = RetryLimit::ofAttempts(5);

        self::assertSame(3, $three->narrowedTo($five)->maxAttempts());
        self::assertSame(3, $five->narrowedTo($three)->maxAttempts());
        self::assertSame(3, $three->narrowedTo(RetryLimit::unlimited())->maxAttempts());
        self::assertSame(3, RetryLimit::unlimited()->narrowedTo($three)->maxAttempts());
        self::assertTrue(RetryLimit::unlimited()->narrowedTo(RetryLimit::unlimited())->isUnlimited());
    }

    public function testActivityOptionsDefaultToUnlimitedAndRoundTripThroughMetadata(): void
    {
        self::assertTrue(ActivityOptions::default()->retryLimit->isUnlimited());

        $options = new ActivityOptions(RetryLimit::ofAttempts(4));
        $decoded = ActivityOptions::fromMetadata($options->toMetadata());

        self::assertNotNull($decoded);
        self::assertSame(4, $decoded->retryLimit->maxAttempts());
    }

    public function testWithRetryLimitReplacesOnlyTheBound(): void
    {
        $options = (new ActivityOptions(RetryLimit::once(), Duration::seconds(0.5)))
            ->withRetryLimit(RetryLimit::ofAttempts(7));

        self::assertSame(7, $options->retryLimit->maxAttempts());
        self::assertSame(0.5, $options->initialInterval->toSeconds());
    }
}
