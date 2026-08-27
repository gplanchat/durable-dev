<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Testing\ChildWorkflowParentLinkStoreConformanceTestCase;
use Illuminate\Database\Capsule\Manager;

/**
 * `illuminate/database` s'utilise sans application Laravel autour — c'est ce que Capsule est, et
 * les adaptateurs ne touchent qu'une `Connection`. Aucun conteneur, aucun service provider :
 * la surface est celle qu'une vraie application leur passerait.
 *
 * @see DUR041
 */
final class IlluminateChildWorkflowParentLinkStoreConformanceTest extends ChildWorkflowParentLinkStoreConformanceTestCase
{
    protected function createParentLinkStore(): ChildWorkflowParentLinkStoreInterface
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $capsule->getConnection();

        return new IlluminateChildWorkflowParentLinkStore($connection, new DurableSchema($connection));
    }
}
