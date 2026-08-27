<?php

declare(strict_types=1);

namespace integration\Durable\Messenger;

use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * @internal
 */
#[CoversClass(MessengerActivityTransport::class)]
final class MessengerActivityTransportTest extends TestCase
{
    #[Test]
    public function scheduleAndCompleteViaMessenger(): void
    {
        $eventStore = new InMemoryEventStore();
        $symfonyTransport = new InMemoryTransport();
        $activityTransport = new MessengerActivityTransport($symfonyTransport, $symfonyTransport);
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('echo', fn(array $p) => $p['v'] ?? 'ok');

        $runtime = new ExecutionRuntime($eventStore, $activityTransport, $activityExecutor);
        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = (string) Uuid::v7();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activityStub(SuiteActivities::class)->echoValue('hello messenger'));
        });

        self::assertSame('hello messenger', $result);

        $events = iterator_to_array($eventStore->readStream($executionId));
        // Le journal en comptait quatre quand ce test a été écrit. `ActivityTaskStarted` et
        // `ActivityTaskCompleted` s'y sont ajoutés depuis : la tentative d'exécution est
        // désormais consignée à part de l'issue de l'activité, ce qui est ce qui permet de
        // distinguer une retentative d'un premier essai. La séquence est écrite en entier plutôt
        // que comptée, pour qu'un événement de plus se voie plutôt que de faire tomber un nombre.
        // Sans ces deux lignes, le test est vert avec **n'importe quel** transport : mesuré, en
        // remplaçant `MessengerActivityTransport` par `InMemoryActivityTransport`. Il portait un
        // `#[CoversClass]` qu'il n'honorait pas — c'est le journal du moteur qu'il observait, pas
        // le passage par Messenger. Ce qui distingue ce transport des autres, c'est qu'une
        // enveloppe part sur le transport Symfony et y est acquittée.
        self::assertCount(1, $symfonyTransport->getSent(), 'une enveloppe part sur le transport Symfony');
        self::assertCount(1, $symfonyTransport->getAcknowledged(), 'et elle y est acquittée');

        self::assertSame([
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityTaskStarted::class,
            ActivityTaskCompleted::class,
            ActivityCompleted::class,
            ExecutionCompleted::class,
        ], array_map(static fn(object $e): string => $e::class, $events));
    }
}
