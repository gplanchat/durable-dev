<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use PHPUnit\Framework\TestCase;

/**
 * La question du domaine — « cette tentative est-elle encore permise ? » — se pose à l'objet,
 * plus à chaque site d'appel qui devait retraduire un 0 magique.
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
        self::assertFalse($limit->allowsAttempt(4), 'ofAttempts(3) = 3 exécutions, pas 4');
    }

    public function testOnceForbidsAnyRetry(): void
    {
        self::assertTrue(RetryLimit::once()->allowsAttempt(1));
        self::assertFalse(RetryLimit::once()->allowsAttempt(2));
    }

    public function testRetriesVocabularyAddsTheInitialAttempt(): void
    {
        self::assertSame(3, RetryLimit::ofRetries(2)->maxAttempts(), '2 retentatives = 3 tentatives');
        self::assertTrue(RetryLimit::ofRetries(0)->isUnlimited(), 'aucun plafond, pas « une seule tentative »');
    }

    public function testABoundBelowOneAttemptIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one attempt/');

        RetryLimit::ofAttempts(0);
    }

    public function testWireValueKeepsTheTemporalEncoding(): void
    {
        // 0 = illimité sur le fil : c'est le langage du serveur, et il voyage dans l'historique
        // des exécutions en cours — le modèle PHP ne doit pas le changer.
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
        $options = (new ActivityOptions(RetryLimit::once(), initialIntervalSeconds: 0.5))
            ->withRetryLimit(RetryLimit::ofAttempts(7));

        self::assertSame(7, $options->retryLimit->maxAttempts());
        self::assertSame(0.5, $options->initialIntervalSeconds);
    }
}
