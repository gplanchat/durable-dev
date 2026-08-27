<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;
use Illuminate\Database\Capsule\Manager;

/**
 * `illuminate/database` s'utilise sans application Laravel autour — c'est ce que Capsule est, et
 * les adaptateurs ne touchent qu'une `Connection`. Aucun conteneur, aucun service provider :
 * la surface est celle qu'une vraie application leur passerait.
 *
 * Le palier replay est joint : ce journal peut piloter un workflow en ligne, donc il se
 * différencie d'avec la référence in-memory plutôt que de se contenter du contrat.
 *
 * @see DUR041
 */
final class IlluminateEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $capsule->getConnection();

        return new IlluminateEventStore($connection, new DurableSchema($connection));
    }
}
