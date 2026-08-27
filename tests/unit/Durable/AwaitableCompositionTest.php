<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Les assembleurs rendent un {@see Awaitable} : c'est ce qui permet de les border par une
 * échéance et de les emboîter. Un assembleur qui attendait à votre place ne savait faire ni
 * l'un ni l'autre.
 */
final class AwaitableCompositionTest extends TestCase
{
    public function testAnAssembledWaitCanItselfBeBounded(): void
    {
        // Inécrivable tant que all() rendait un tableau : la valeur était déjà là quand on
        // aurait voulu la borner.
        $env = WorkflowTestEnvironment::inMemory([
            'fast' => static fn(): string => 'a',
            'slow' => static fn(): string => 'b',
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                $wf->all($wf->activityStub(SuiteActivities::class)->fast(), $wf->activityStub(SuiteActivities::class)->slow()),
                Duration::hours(1),
            ),
            'compose-1',
        );

        self::assertSame(['a', 'b'], $result);
    }

    public function testAssemblersNest(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'a' => static fn(): string => 'a',
            'b' => static fn(): string => 'b',
            'c' => static fn(): string => 'c',
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->all(
                $wf->activityStub(SuiteActivities::class)->a(),
                $wf->any($wf->activityStub(SuiteActivities::class)->b(), $wf->activityStub(SuiteActivities::class)->c()),
            )),
            'compose-2',
        );

        self::assertIsArray($result);
        self::assertSame('a', $result[0]);
        self::assertContains($result[1], ['b', 'c']);
    }

    public function testANestedRaceCancelsItsLoserExactlyOnce(): void
    {
        // isSettled() est interrogé en boucle par le moteur. Si le décompte d'un quorum
        // déballait ses membres, la course imbriquée annoncerait son perdant à chaque tour.
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);
        $store = $env->getEventStore();

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->all(
                $wf->activityStub(SuiteActivities::class)->fast(),
                $wf->any($wf->activityStub(SuiteActivities::class)->fast(), $wf->timer(Duration::hours(1))),
            )),
            'nested-1',
        );

        self::assertCount(1, $this->cancelledTimers($store, 'nested-1'));
    }

    public function testAQuorumCancelsTheMembersStillRunningWhenItFalls(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);
        $store = $env->getEventStore();

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                1,
                $wf->activityStub(SuiteActivities::class)->fast(),
                $wf->timer(Duration::hours(1)),
                $wf->timer(Duration::hours(2)),
            )),
            'quorum-6',
        );

        self::assertCount(2, $this->cancelledTimers($store, 'quorum-6'));
    }

    public function testADeadlineOnAnAssemblyReachesTheActivitiesInsideIt(): void
    {
        // La marche d'annulation doit descendre dans le composite : s'arrêter au premier
        // niveau laissait les deux activités en file, hors de portée de l'échéance.
        $env = WorkflowTestEnvironment::inMemory([
            'never' => static fn(): string => 'unreachable',
        ]);
        $store = $env->getEventStore();

        try {
            $env->run(
                static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                    $wf->all($wf->timer(Duration::hours(1)), $wf->timer(Duration::hours(2))),
                    Duration::seconds(0.0),
                ),
                'compose-3',
            );
            self::fail("l'échéance devait l'emporter");
        } catch (DeadlineExceededException) {
            // attendu
        }

        $cancelled = $this->cancelledTimers($store, 'compose-3');

        // Les deux minuteurs bornés, et eux seuls : celui de l'échéance a tiré, il n'y a rien
        // à y retirer. Avant que la marche d'annulation ne descende dans le composite, aucun
        // des deux n'était atteint et tous deux restaient en file.
        self::assertCount(2, $cancelled, 'les branches internes doivent être retirées de la file');
    }

    // -------------------------------------------------------------------------
    // some() : le quorum
    // -------------------------------------------------------------------------

    public function testAQuorumSettlesOnTheCountAskedFor(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'price' => static fn(array $p): string => 'quote-' . ($p['n'] ?? '?'),
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                3,
                ...array_map(static fn(int $n): Awaitable => $wf->activityStub(SuiteActivities::class)->price($n), range(1, 8)),
            )),
            'quorum-1',
        );

        self::assertIsArray($result);
        self::assertCount(3, $result);
        // Indexé par la position de déclaration : l'appelant sait lesquels ont répondu.
        self::assertSame([0, 1, 2], array_keys($result));
    }

    public function testAFailingMemberDoesNotCountTowardsTheQuorum(): void
    {
        // Le quorum existe pour survivre à des membres qui tombent : compter les échecs le
        // rendrait strictement pire qu'un all().
        $env = WorkflowTestEnvironment::inMemory([
            'boom' => static fn(): never => throw new \RuntimeException('provider down'),
            'ok' => static fn(array $p): string => 'quote-' . ($p['n'] ?? '?'),
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                2,
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(1),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(2),
            )),
            'quorum-2',
        );

        self::assertSame([1 => 'quote-1', 3 => 'quote-2'], $result);
    }

    public function testAnUnreachableQuorumFailsRatherThanHangs(): void
    {
        // Trois pannes sur quatre : le quorum de deux ne peut plus tomber. Ne rien relever
        // serait une exécution suspendue pour toujours.
        $env = WorkflowTestEnvironment::inMemory([
            'boom' => static fn(): never => throw new \RuntimeException('provider down'),
            'ok' => static fn(): string => 'quote',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/provider down/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                2,
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(),
            )),
            'quorum-3',
        );
    }

    public function testAQuorumThatCanNeverBeReachedIsRefusedAtTheCallSite(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['a' => static fn(): string => 'a']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/never settle/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(3, $wf->activityStub(SuiteActivities::class)->a())),
            'quorum-4',
        );
    }

    public function testARaceWithNoRunnerIsRefused(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one awaitable/');

        $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any()), 'quorum-5');
    }

    /**
     * @return list<string>
     */
    private function cancelledTimers(InMemoryEventStore $store, string $executionId): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof \Gplanchat\Durable\Event\TimerCancelled) {
                $out[] = $event->timerId();
            }
        }

        return $out;
    }

    private static function onceOnly(): \Gplanchat\Durable\Activity\ActivityOptions
    {
        return \Gplanchat\Durable\Activity\ActivityOptions::of(\Gplanchat\Durable\Activity\RetryLimit::once());
    }
}
