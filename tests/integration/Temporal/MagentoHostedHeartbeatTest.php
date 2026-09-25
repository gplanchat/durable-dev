<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * The heartbeat scenarios with the activities hosted by the Magento integration's activity worker
 * (see Hosts/magento-activity.php).
 */
final class MagentoHostedHeartbeatTest extends HeartbeatOnAHostTestCase
{
    protected function activityWorkerRole(): string
    {
        return 'magento-activity';
    }
}
