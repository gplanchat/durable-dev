<?php

declare(strict_types=1);

/*
 * Option B, probed: Magento's own ResourceConnection, with env.php db/connection/durable.
 * Run from mageos/: php -d memory_limit=2G ../probe-b.php <which|claim <s>|fenced-read|nesting|ddl>
 */

require getcwd() . '/app/bootstrap.php';

$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
// getConnection() takes a *resource* name and falls back to `default` when env.php has no such
// resource: the journal would land in the shop's database without a word. By connection name:
$durable = $resource->getConnectionByName('durable');
$say = static fn(string $line) => print(sprintf("%s pid=%d %s\n", date('H:i:s'), getmypid(), $line));
$heads = 'durable_execution_heads';
$id = 'probe-b';

switch ($argv[1] ?? 'which') {
    case 'which':
        foreach (['default' => $resource->getConnection(), "getConnection('durable')" => $resource->getConnection('durable'), "getConnectionByName('durable')" => $durable] as $name => $c) {
            $say(sprintf('%-30s %s %s', $name, get_class($c), json_encode($c->fetchRow('SELECT CONNECTION_ID() id, DATABASE() db, @@hostname host'))));
        }
        break;
    case 'claim': // DbalEventStore::claimPass(), through Magento's adapter: the UPDATE holds the row.
        $durable->query("INSERT IGNORE INTO $heads (execution_id, epoch) VALUES (?, 0)", [$id]);
        $durable->beginTransaction();
        $durable->query("UPDATE $heads SET epoch = epoch + 1 WHERE execution_id = ?", [$id]);
        $say('claim holds the heads row, epoch now ' . $durable->fetchOne("SELECT epoch FROM $heads WHERE execution_id = ?", [$id]));
        sleep((int) ($argv[2] ?? 4));
        $durable->commit();
        $say('claim committed');
        break;
    case 'fenced-read': // appendFencedUnderSharedLock()'s read: must wait for the claim.
        $t = microtime(true);
        $durable->beginTransaction();
        $epoch = $durable->fetchOne("SELECT epoch FROM $heads WHERE execution_id = ? LOCK IN SHARE MODE", [$id]);
        $durable->commit();
        $say(sprintf('shared-lock read epoch=%s after waiting %.2f s', $epoch, microtime(true) - $t));
        break;
    case 'nesting': // DBAL nests with savepoints; Magento counts levels and refuses an inner rollback.
        $durable->beginTransaction();
        $durable->beginTransaction();
        try {
            $durable->rollBack();
            $durable->commit();
            $say('inner rollback then outer commit: accepted');
        } catch (\Throwable $e) {
            $say('inner rollback then outer commit: ' . get_class($e) . ': ' . $e->getMessage());
            while ($durable->getTransactionLevel() > 0) {
                $durable->rollBack();
            }
        }
        break;
    case 'ddl': // DurableSchema::ensure() creates tables on first write; Magento's adapter forbids DDL in a transaction.
        $durable->beginTransaction();
        try {
            $durable->createTable($durable->newTable('durable_probe_ddl')->addColumn('id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, null, ['primary' => true, 'nullable' => false]));
            $say('DDL inside a transaction: accepted');
        } catch (\Throwable $e) {
            $say('DDL inside a transaction: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $durable->rollBack();
        break;
}
