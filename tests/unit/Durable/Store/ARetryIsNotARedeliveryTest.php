<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Une **reprise** n'est pas une **redélivrance**, et les confondre a coûté toutes les reprises
 * d'activité du backend Temporal.
 *
 * Le worker demande au journal, avant de traiter, si la tâche qu'il vient de recevoir a déjà été
 * tranchée — pour ne pas réexécuter une tâche que le serveur redélivre après une réponse perdue.
 * Posée sans le rang de la tentative, la question rendait l'échec de la première à toutes les
 * suivantes : trois tentatives brûlées en deux secondes, le même message d'échec recopié, et le
 * code de l'activité rappelé une seule fois.
 *
 * Ce qui sépare les deux cas n'est pas la nature de l'issue, c'est **la tentative qui l'a écrite**.
 */
final class ARetryIsNotARedeliveryTest extends TestCase
{
    public function testAFailureFromAnEarlierAttemptDoesNotSettleThisOne(): void
    {
        // Le cas de l'issue #218 : la tentative 1 a échoué et sa reprise est planifiée. La
        // tentative 2 doit **s'exécuter**, pas se voir répondre l'échec de la précédente.
        $store = $this->journalWith($this->failedOnAttempt(1, ActivityRetryState::InProgress));

        self::assertNull($this->settledFor($store, attempt: 2));
    }

    public function testAFailureFromThisVeryAttemptSettlesIt(): void
    {
        // La raison d'être de la garde, qui doit survivre au correctif : le serveur redélivre la
        // même tentative parce que la réponse du worker s'est perdue. La réexécuter rejouerait un
        // effet de bord déjà produit.
        $store = $this->journalWith($this->failedOnAttempt(2, ActivityRetryState::InProgress));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    public function testAnActivityThatSucceededIsSettledWhateverTheAttempt(): void
    {
        // Une activité terminée ne se rejoue pas : le rang n'entre pas en ligne de compte.
        $store = $this->journalWith(new ActivityCompleted('exec-1', 'act-1', 'receipt'));

        self::assertInstanceOf(ActivityCompleted::class, $this->settledFor($store, attempt: 7));
    }

    public function testAFailureWithoutARetryStateStaysSettled(): void
    {
        // `null` est un journal antérieur au discriminant, pas une reprise en cours. Dans le doute,
        // on ne rejoue pas un effet de bord — l'inverse rendrait le correctif plus dangereux que
        // le défaut qu'il corrige.
        $store = $this->journalWith($this->failedOnAttempt(1, null));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    public function testATerminalFailureStaysSettledForEveryLaterAttempt(): void
    {
        $store = $this->journalWith($this->failedOnAttempt(1, ActivityRetryState::NonRetryableFailure));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    private function failedOnAttempt(int $attempt, ?ActivityRetryState $retryState): ActivityFailed
    {
        return new ActivityFailed(
            'exec-1',
            'act-1',
            \RuntimeException::class,
            'the payment gateway did not answer',
            activityName: 'charge',
            failureAttempt: $attempt,
            retryState: $retryState,
        );
    }

    private function journalWith(\Gplanchat\Durable\Event\Event $event): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append($event);

        return $store;
    }

    private function settledFor(InMemoryEventStore $store, int $attempt): ?object
    {
        return ActivityEventJournal::settledOutcomeForDelivery($store, 'exec-1', 'act-1', $attempt);
    }
}
