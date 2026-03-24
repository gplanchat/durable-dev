<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;

/**
 * Construit un InMemoryEventStore contenant le journal attendu pour un scénario donné.
 * Les activityId sont des placeholders : la contrainte de journal les ignore lors de la comparaison.
 *
 * Les méthodes « After… » modélisent des états intermédiaires (exécution distribuée pas à pas).
 */
final class DistributedWorkflowExpectedJournal
{
    private const PLACEHOLDER_ACTIVITY_PREFIX = '00000000-0000-7000-8000-';

    public static function singleGreetActivity(ExecutionId $executionId, string $greetingResult): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $aid = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $aid, 'greet', ['name' => 'Durable']));
        $store->append(new ActivityCompleted($id, $aid, $greetingResult));
        $store->append(new ExecutionCompleted($id, $greetingResult));

        return $store;
    }

    /** Après le premier await : une activité en file, pas encore ActivityCompleted. */
    public static function singleGreetActivityAfterFirstScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $aid = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $aid, 'greet', ['name' => 'Durable']));

        return $store;
    }

    /** Après exécution de l'activité, avant resume : pas encore ExecutionCompleted. */
    public static function singleGreetActivityAfterFirstCompleted(
        ExecutionId $executionId,
        string $greetingResult,
    ): InMemoryEventStore {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $aid = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $aid, 'greet', ['name' => 'Durable']));
        $store->append(new ActivityCompleted($id, $aid, $greetingResult));

        return $store;
    }

    public static function threeSequentialDoubles(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));
        $a2 = self::placeholderActivityId(2);
        $store->append(new ActivityScheduled($id, $a2, 'double', ['x' => 10]));
        $store->append(new ActivityCompleted($id, $a2, 20));
        $a3 = self::placeholderActivityId(3);
        $store->append(new ActivityScheduled($id, $a3, 'double', ['x' => 20]));
        $store->append(new ActivityCompleted($id, $a3, 40));
        $store->append(new ExecutionCompleted($id, 40));

        return $store;
    }

    public static function threeSequentialDoublesAfterFirstScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));

        return $store;
    }

    public static function threeSequentialDoublesAfterFirstCompleted(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));

        return $store;
    }

    public static function threeSequentialDoublesAfterSecondScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));
        $a2 = self::placeholderActivityId(2);
        $store->append(new ActivityScheduled($id, $a2, 'double', ['x' => 10]));

        return $store;
    }

    public static function threeSequentialDoublesAfterSecondCompleted(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));
        $a2 = self::placeholderActivityId(2);
        $store->append(new ActivityScheduled($id, $a2, 'double', ['x' => 10]));
        $store->append(new ActivityCompleted($id, $a2, 20));

        return $store;
    }

    public static function threeSequentialDoublesAfterThirdScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));
        $a2 = self::placeholderActivityId(2);
        $store->append(new ActivityScheduled($id, $a2, 'double', ['x' => 10]));
        $store->append(new ActivityCompleted($id, $a2, 20));
        $a3 = self::placeholderActivityId(3);
        $store->append(new ActivityScheduled($id, $a3, 'double', ['x' => 20]));

        return $store;
    }

    public static function threeSequentialDoublesAfterThirdCompleted(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $a1 = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $a1, 'double', ['x' => 5]));
        $store->append(new ActivityCompleted($id, $a1, 10));
        $a2 = self::placeholderActivityId(2);
        $store->append(new ActivityScheduled($id, $a2, 'double', ['x' => 10]));
        $store->append(new ActivityCompleted($id, $a2, 20));
        $a3 = self::placeholderActivityId(3);
        $store->append(new ActivityScheduled($id, $a3, 'double', ['x' => 20]));
        $store->append(new ActivityCompleted($id, $a3, 40));

        return $store;
    }

    public static function identityActivityScheduledPayload(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $aid = self::placeholderActivityId(1);
        $store->append(new ActivityScheduled($id, $aid, 'identity', ['key' => 'value']));
        $store->append(new ActivityCompleted($id, $aid, ['key' => 'value']));
        $store->append(new ExecutionCompleted($id, ['key' => 'value']));

        return $store;
    }

    public static function threeParallelIdActivities(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'id', ['id' => 'A']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'id', ['id' => 'B']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(3), 'id', ['id' => 'C']));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'A'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(2), 'B'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(3), 'C'));
        $store->append(new ExecutionCompleted($id, ['A', 'B', 'C']));

        return $store;
    }

    /**
     * Après all() : les trois activités sont planifiées avant le premier await (file à 3 messages).
     */
    public static function threeParallelIdActivitiesAfterAllScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'id', ['id' => 'A']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'id', ['id' => 'B']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(3), 'id', ['id' => 'C']));

        return $store;
    }

    public static function threeParallelIdActivitiesAfterFirstDrain(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'id', ['id' => 'A']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'id', ['id' => 'B']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(3), 'id', ['id' => 'C']));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'A'));

        return $store;
    }

    public static function threeParallelIdActivitiesAfterSecondDrain(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'id', ['id' => 'A']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'id', ['id' => 'B']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(3), 'id', ['id' => 'C']));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'A'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(2), 'B'));

        return $store;
    }

    public static function threeParallelIdActivitiesAfterThirdDrain(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'id', ['id' => 'A']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'id', ['id' => 'B']));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(3), 'id', ['id' => 'C']));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'A'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(2), 'B'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(3), 'C'));

        return $store;
    }

    /**
     * File FIFO : « first » est traitée en premier ; any() retourne « first ».
     * Le worker vide toute la file : les deux activités produisent un ActivityCompleted.
     */
    public static function competitiveFirstWinsFifo(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'first', []));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'second', []));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'first'));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(2), 'second'));
        $store->append(new ExecutionCompleted($id, 'first'));

        return $store;
    }

    /** Les deux activités sont planifiées avant any() / await (file à 2 messages). */
    public static function competitiveAnyAfterBothScheduled(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'first', []));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'second', []));

        return $store;
    }

    /** Après un drain FIFO : seule la première activité est complétée. */
    public static function competitiveAnyAfterFirstActivityDrained(ExecutionId $executionId): InMemoryEventStore
    {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'first', []));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'second', []));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'first'));

        return $store;
    }

    /**
     * Après reprise : any() voit la première activité déjà résolue dans l'historique et se termine
     * sans attendre la seconde — la deuxième peut rester en file si le worker n'a traité qu'un message.
     */
    public static function competitiveAnyCompletedAfterFirstWinsWithoutSecondActivityRun(
        ExecutionId $executionId,
    ): InMemoryEventStore {
        $id = $executionId->toString();
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(1), 'first', []));
        $store->append(new ActivityScheduled($id, self::placeholderActivityId(2), 'second', []));
        $store->append(new ActivityCompleted($id, self::placeholderActivityId(1), 'first'));
        $store->append(new ActivityCancelled($id, self::placeholderActivityId(2), ActivityCancellationReason::RACE_SUPERSEDED));
        $store->append(new ExecutionCompleted($id, 'first'));

        return $store;
    }

    private static function placeholderActivityId(int $n): string
    {
        return self::PLACEHOLDER_ACTIVITY_PREFIX.str_pad((string) $n, 12, '0', \STR_PAD_LEFT);
    }
}
