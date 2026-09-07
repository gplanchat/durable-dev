<?php

declare(strict_types=1);

/*
 * Probe — what does a consumer that dies in the middle of a message leave behind?
 *
 * The bench has no AMQP: `compose.yaml` brings up MySQL, OpenSearch, Redis and
 * Temporal, nothing else. So it is `Magento\MysqlMq` that answers, and its
 * redelivery does not have AMQP's rules — deducing them from AMQP's
 * documentation would be exactly the mistake §1.3 exists to avoid.
 *
 *   php probe-queue.php publish <label> <seconds>   puts a message that lingers
 *   php probe-queue.php state                       the state of the messages, in plain words
 *   php probe-queue.php recover                     the cron task that catches up the IN_PROGRESS
 *   php probe-queue.php unlock                      the cron task that empties `queue_lock`
 *   php probe-queue.php purge                       sets aside the messages of past campaigns
 *   php probe-queue.php config                      the settings that decide the resume
 *
 * Then, in another terminal:
 *   php bin/magento queue:consumers:start durable.probe --max-messages=1
 * and it is killed between the START and the END in `var/log/durable-probe.log`.
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$statuses = [
    2 => 'NEW',
    3 => 'IN_PROGRESS',
    4 => 'COMPLETE',
    5 => 'RETRY_REQUIRED',
    6 => 'ERROR',
    7 => 'TO_BE_DELETED',
];

switch ($argv[1] ?? 'state') {
    case 'publish':
        $label = $argv[2] ?? 'probe';
        $seconds = (int) ($argv[3] ?? 30);
        $om->get(\Magento\Framework\MessageQueue\PublisherInterface::class)
            ->publish('gplanchat.durable.probe', $label . ':' . $seconds);
        echo "published: $label:$seconds\n";
        break;

    case 'state':
        $connection = $om->get(\Magento\Framework\App\ResourceConnection::class);
        $db = $connection->getConnection();
        $rows = $db->fetchAll(
            $db->select()
                ->from(['s' => $connection->getTableName('queue_message_status')], ['status', 'updated_at', 'number_of_trials'])
                ->join(['m' => $connection->getTableName('queue_message')], 's.message_id = m.id', ['body'])
                ->join(['q' => $connection->getTableName('queue')], 's.queue_id = q.id', ['queue' => 'name'])
                ->where('q.name = ?', 'durable_probe')
                ->order('s.id ASC')
        );
        if ($rows === []) {
            echo "no message in durable_probe\n";
            break;
        }
        foreach ($rows as $row) {
            printf(
                "%-14s tries=%-3d updated=%s  %s\n",
                $statuses[(int) $row['status']] ?? $row['status'],
                (int) $row['number_of_trials'],
                $row['updated_at'],
                $row['body'],
            );
        }
        printf("(now, on the database side: %s)\n", $db->fetchOne('SELECT NOW()'));
        break;

    case 'recover':
        // Exactly what the cron task `mysqlmq_clean_messages` calls —
        // Magento_MysqlMq's `etc/crontab.xml` declares it on this class and this
        // method, at 6:30 and 15:30. Its entry point is called, not its
        // scheduler: the probe measures the effect, it reimplements nothing.
        $om->get(\Magento\MysqlMq\Model\Observer::class)->cleanupMessages();
        echo "mysqlmq_clean_messages ran\n";
        break;

    case 'unlock':
        // The cron task `messagequeue_clean_outdated_locks`, every hour. It
        // empties `queue_lock` — and it is that task, not the resume, that
        // decides whether a redelivered message is processed or acknowledged
        // without doing anything.
        $om->get(\Magento\Framework\MessageQueue\Lock\WriterInterface::class)->releaseOutdatedLocks();
        echo "messagequeue_clean_outdated_locks ran\n";
        break;

    case 'purge':
        // Previous campaigns leave messages behind them, and a consumer takes
        // the oldest candidate, not yours: a dirty queue answers beside the
        // question that was asked. Measured the hard way.
        $connection = $om->get(\Magento\Framework\App\ResourceConnection::class);
        $db = $connection->getConnection();
        $ids = $db->fetchCol(
            $db->select()
                ->from(['s' => $connection->getTableName('queue_message_status')], ['id'])
                ->join(['q' => $connection->getTableName('queue')], 's.queue_id = q.id', [])
                ->where('q.name = ?', 'durable_probe')
        );
        if ($ids !== []) {
            $om->get(\Magento\MysqlMq\Model\QueueManagement::class)
                ->changeStatus($ids, \Magento\MysqlMq\Model\QueueManagement::MESSAGE_STATUS_COMPLETE);
        }
        printf("%d probe message(s) taken out of the way\n", count($ids));
        break;

    case 'config':
        $config = $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        foreach ([
            'system/mysqlmq/retry_inprogress_after',
            'system/mysqlmq/successful_messages_lifetime',
            'system/mysqlmq/failed_messages_lifetime',
            'system/mysqlmq/new_messages_lifetime',
        ] as $path) {
            printf("%-48s %s\n", $path, var_export($config->getValue($path), true));
        }
        break;

    default:
        fwrite(STDERR, "usage: php probe-queue.php publish <label> <seconds>|state|config\n");
        exit(2);
}
