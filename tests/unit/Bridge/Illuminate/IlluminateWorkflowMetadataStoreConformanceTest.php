<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase;
use Illuminate\Database\Capsule\Manager;

/**
 * `illuminate/database` s'utilise sans application Laravel autour — c'est ce que Capsule est, et
 * les adaptateurs ne touchent qu'une `Connection`. Aucun conteneur, aucun service provider :
 * la surface est celle qu'une vraie application leur passerait.
 *
 * @see DUR041
 */
final class IlluminateWorkflowMetadataStoreConformanceTest extends WorkflowMetadataStoreConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $capsule->getConnection();

        return new IlluminateWorkflowMetadataStore($connection, new DurableSchema($connection));
    }
}
