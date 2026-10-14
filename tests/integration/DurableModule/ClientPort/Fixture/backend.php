<?php

declare(strict_types=1);

/*
 * The database backend of the client port test, built the way `RuntimeFactory::database()` builds
 * it, on `DURABLE_TEST_MYSQL` and without Magento's application. The worker and the client
 * scenario are separate processes and each calls this.
 */

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\SystemClock;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\DurableModule\Runtime\DatabaseBackend;
use Gplanchat\DurableModule\Schema\JournalSchema;
use integration\DurableModule\ClientPort\Fixture\PaidOrderWorkflow;
use Magento\Framework\App\DeploymentConfig;

require_once __DIR__ . '/../../ResumeLock/Fixture/bootstrap.php';
require_once __DIR__ . '/PaidOrderWorkflow.php';
require_once __DIR__ . '/PaidOrderRepository.php';

function durable_client_backend(): DatabaseBackend
{
    $connection = durable_test_connection();
    (new JournalSchema($connection))->setup();
    $registry = new WorkflowRegistry();
    $registry->registerClass(PaidOrderWorkflow::class);
    // No env.php here: every `durable/queue/*` setting answers its default.
    $config = new class extends DeploymentConfig {
        public function __construct() {}

        public function get($key = null, $defaultValue = null)
        {
            return $defaultValue;
        }
    };

    return new DatabaseBackend($connection, $config, new SystemClock(), $registry, new RegistryActivityExecutor(), new NullActivityHeartbeatSender(), 0);
}
