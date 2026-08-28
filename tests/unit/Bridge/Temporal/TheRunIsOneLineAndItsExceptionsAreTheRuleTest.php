<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Store\TemporalRunHistoryReader;
use PHPUnit\Framework\TestCase;

/**
 * L'exécution occupe une ligne, et **ce qui n'en fait pas partie est l'essentiel de la règle**.
 *
 * `WORKFLOW_EXECUTION_SIGNALED` et la famille `WORKFLOW_EXECUTION_UPDATE_*` commencent par le même
 * préfixe que l'exécution sans en être : un signal reçu et une mise à jour sont des attentes à part
 * entière. Les workflows enfants et externes ne commencent pas par ce préfixe, et c'est tout ce qui
 * leur laisse leurs lignes.
 *
 * Le partage est donc éprouvé **type par type**, à partir de l'énumération du serveur et non de
 * mémoire : c'est ce qui empêche une version ultérieure de replier en silence un signal dans la
 * première ligne, où personne ne le chercherait.
 */
final class TheRunIsOneLineAndItsExceptionsAreTheRuleTest extends TestCase
{
    /** @var list<string> */
    private const THE_RUN_ITSELF = [
        'EVENT_TYPE_WORKFLOW_EXECUTION_STARTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CONTINUED_AS_NEW',
        'EVENT_TYPE_WORKFLOW_EXECUTION_OPTIONS_UPDATED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_PAUSED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UNPAUSED',
        'EVENT_TYPE_WORKFLOW_TASK_SCHEDULED',
        'EVENT_TYPE_WORKFLOW_TASK_STARTED',
        'EVENT_TYPE_WORKFLOW_TASK_COMPLETED',
        'EVENT_TYPE_WORKFLOW_TASK_FAILED',
        'EVENT_TYPE_WORKFLOW_TASK_TIMED_OUT',
    ];

    /** @var list<string> */
    private const A_LINE_OF_ITS_OWN = [
        // Le même préfixe, et pourtant pas l'exécution.
        'EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ADMITTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_REJECTED',
        // Les enfants : ce sont eux que l'auteur a nommément demandé de ne pas replier.
        'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_STARTED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_CANCELED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TERMINATED',
        // Les workflows d'en face.
        'EVENT_TYPE_EXTERNAL_WORKFLOW_EXECUTION_SIGNALED',
        'EVENT_TYPE_EXTERNAL_WORKFLOW_EXECUTION_CANCEL_REQUESTED',
        'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
        // Et le reste, dont chaque occurrence est un fait que l'exploitant lit pour lui-même.
        'EVENT_TYPE_ACTIVITY_TASK_SCHEDULED',
        'EVENT_TYPE_ACTIVITY_TASK_STARTED',
        'EVENT_TYPE_ACTIVITY_TASK_COMPLETED',
        'EVENT_TYPE_ACTIVITY_TASK_FAILED',
        'EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT',
        'EVENT_TYPE_ACTIVITY_TASK_CANCEL_REQUESTED',
        'EVENT_TYPE_ACTIVITY_TASK_CANCELED',
        'EVENT_TYPE_ACTIVITY_PROPERTIES_MODIFIED_EXTERNALLY',
        'EVENT_TYPE_TIMER_STARTED',
        'EVENT_TYPE_TIMER_FIRED',
        'EVENT_TYPE_TIMER_CANCELED',
        'EVENT_TYPE_NEXUS_OPERATION_SCHEDULED',
        'EVENT_TYPE_NEXUS_OPERATION_STARTED',
        'EVENT_TYPE_NEXUS_OPERATION_COMPLETED',
        'EVENT_TYPE_NEXUS_OPERATION_FAILED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCELED',
        'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_COMPLETED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_FAILED',
        'EVENT_TYPE_MARKER_RECORDED',
        'EVENT_TYPE_UPSERT_WORKFLOW_SEARCH_ATTRIBUTES',
        'EVENT_TYPE_WORKFLOW_PROPERTIES_MODIFIED',
        'EVENT_TYPE_WORKFLOW_PROPERTIES_MODIFIED_EXTERNALLY',
    ];

    public function testEveryEventOfTheRunItselfFoldsIntoTheFirstLine(): void
    {
        foreach (self::THE_RUN_ITSELF as $eventType) {
            self::assertTrue($this->belongs($eventType), $eventType . ' appartient à l\'exécution');
        }
    }

    public function testEverythingElseKeepsItsOwnLine(): void
    {
        foreach (self::A_LINE_OF_ITS_OWN as $eventType) {
            self::assertFalse($this->belongs($eventType), $eventType . ' garde sa ligne');
        }
    }

    public function testTheTwoListsCoverEveryTypeTheServerCanSend(): void
    {
        // Un type que le partage ne nomme pas est un type dont personne n'a décidé la ligne. Le
        // test le dit ici plutôt que de laisser un exploitant le découvrir dans une frise.
        $declared = array_merge(self::THE_RUN_ITSELF, self::A_LINE_OF_ITS_OWN);

        $missing = array_diff($this->everyEventTypeTheServerDeclares(), $declared);

        self::assertSame([], array_values($missing), 'types non rangés : ' . implode(', ', $missing));
    }

    public function testEveryFamilyOfFailureIsMarked(): void
    {
        // Un échec par niveau : si un seul suffixe manquait à la règle, une famille entière
        // sortirait de la page en noir alors qu'elle a mal tourné.
        $failures = [
            'EVENT_TYPE_ACTIVITY_TASK_FAILED',
            'EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT',
            'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT',
            'EVENT_TYPE_WORKFLOW_TASK_FAILED',
            'EVENT_TYPE_WORKFLOW_TASK_TIMED_OUT',
            'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT',
            'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
        ];

        foreach ($failures as $eventType) {
            self::assertTrue($this->isFailure($eventType), $eventType . ' a mal tourné');
        }
    }

    public function testNoCancellationIsPaintedAsAFailure(): void
    {
        // Une annulation est une issue, pas une panne. La liste vient de l'énumération du serveur
        // et non de mémoire : un type d'annulation ajouté plus tard tombe dans ce test tout seul,
        // et c'est le seul moyen que le rouge continue de vouloir dire quelque chose.
        $outcomes = array_values(array_filter(
            $this->everyEventTypeTheServerDeclares(),
            static fn(string $type): bool => str_ends_with($type, '_CANCELED')
                || str_ends_with($type, '_CANCEL_REQUESTED')
                || str_ends_with($type, '_CANCEL_REQUEST_COMPLETED')
                || str_ends_with($type, '_TERMINATED'),
        ));

        self::assertNotSame([], $outcomes, "l'énumération du serveur doit en déclarer");

        foreach ($outcomes as $eventType) {
            self::assertFalse($this->isFailure($eventType), $eventType . ' est une issue, pas une panne');
        }
    }

    public function testACancellationThatCouldNotBeDeliveredIsAFailure(): void
    {
        // Le piège que le test précédent a trouvé : ces deux types-là parlent d'annulation et
        // finissent pourtant en `_FAILED`. Ce n'est pas l'annulation qui est une panne, c'est la
        // demande d'annulation qui n'est **pas passée** — l'exécution visée continue de tourner
        // alors que quelqu'un a demandé son arrêt, et c'est exactement le genre de fait qu'on ne
        // veut pas voir sortir en noir.
        self::assertTrue($this->isFailure('EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_FAILED'));
        self::assertTrue($this->isFailure('EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_FAILED'));
    }

    private function isFailure(string $eventType): bool
    {
        $rule = new \ReflectionMethod(TemporalRunHistoryReader::class, 'isFailure');
        $rule->setAccessible(true);

        return (bool) $rule->invoke(null, $eventType);
    }

    private function belongs(string $eventType): bool
    {
        $rule = new \ReflectionMethod(TemporalRunHistoryReader::class, 'belongsToTheRunItself');
        $rule->setAccessible(true);

        return (bool) $rule->invoke(null, $eventType);
    }

    /**
     * @return list<string>
     */
    private function everyEventTypeTheServerDeclares(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(\Temporal\Api\Enums\V1\EventType::class))->getConstants() as $name => $value) {
            if ('EVENT_TYPE_UNSPECIFIED' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
