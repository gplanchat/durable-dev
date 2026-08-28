<?php

declare(strict_types=1);

/*
 * Sonde — que laisse un consommateur qui meurt au milieu d'un message ?
 *
 * Le banc n'a pas d'AMQP : `compose.yaml` monte MySQL, OpenSearch, Redis et
 * Temporal, rien d'autre. C'est donc `Magento\MysqlMq` qui répond, et sa
 * redélivrance n'a pas les règles d'AMQP — les déduire de la documentation
 * d'AMQP serait exactement l'erreur que le §1.3 existe pour éviter.
 *
 *   php probe-queue.php publish <étiquette> <secondes>   met un message qui traîne
 *   php probe-queue.php state                            l'état des messages, en clair
 *   php probe-queue.php recover                          la tâche cron qui rattrape les IN_PROGRESS
 *   php probe-queue.php unlock                           la tâche cron qui vide `queue_lock`
 *   php probe-queue.php config                           les réglages qui décident de la reprise
 *
 * Puis, dans un autre terminal :
 *   php bin/magento queue:consumers:start durable.probe --max-messages=1
 * et on le tue entre le DÉBUT et la FIN de `var/log/durable-probe.log`.
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
        echo "publié : $label:$seconds\n";
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
            echo "aucun message dans durable_probe\n";
            break;
        }
        foreach ($rows as $row) {
            printf(
                "%-14s essais=%-3d maj=%s  %s\n",
                $statuses[(int) $row['status']] ?? $row['status'],
                (int) $row['number_of_trials'],
                $row['updated_at'],
                $row['body'],
            );
        }
        printf("(maintenant, côté base : %s)\n", $db->fetchOne('SELECT NOW()'));
        break;

    case 'recover':
        // Exactement ce que la tâche cron `mysqlmq_clean_messages` appelle —
        // `etc/crontab.xml` de Magento_MysqlMq la déclare sur cette classe et
        // cette méthode, à 6h30 et 15h30. On appelle son point d'entrée, pas
        // son ordonnanceur : la sonde mesure l'effet, elle ne réimplémente rien.
        $om->get(\Magento\MysqlMq\Model\Observer::class)->cleanupMessages();
        echo "mysqlmq_clean_messages exécutée\n";
        break;

    case 'unlock':
        // La tâche cron `messagequeue_clean_outdated_locks`, toutes les heures.
        // Elle vide `queue_lock` — et c'est elle, pas la reprise, qui décide si
        // un message redélivré sera traité ou acquitté sans rien faire.
        $om->get(\Magento\Framework\MessageQueue\Lock\WriterInterface::class)->releaseOutdatedLocks();
        echo "messagequeue_clean_outdated_locks exécutée\n";
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
        fwrite(STDERR, "usage: php probe-queue.php publish <étiquette> <secondes>|state|config\n");
        exit(2);
}
