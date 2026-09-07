<?php

declare(strict_types=1);

/*
 * Probe — is `LockManagerInterface` shared across processes?
 *
 * The whole module rests on it: two `queue:consumers:start` can dequeue two
 * resumes of one same execution, and only a shared lock serializes them.
 * `Magento\Framework\Lock\Backend\Database` *should* be one — a `GET_LOCK` on
 * the application database — but "should" is exactly what a probe exists to
 * check. It measures with two processes, it does not read the class: what is
 * **configured** on the host is what decides, not what the framework ships by
 * default.
 *
 *   php probe-lock.php which          what the container actually wired
 *   php probe-lock.php hold <n> <s>   takes lock <n> and holds it <s> s
 *   php probe-lock.php try  <n>       tries <n> without waiting, returns 0/1
 *
 * `try` exits 0 if it got the lock, 1 otherwise: enough to chain the two
 * processes from a shell.
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$locks = $bootstrap->getObjectManager()->get(\Magento\Framework\Lock\LockManagerInterface::class);

$mode = $argv[1] ?? 'which';
$name = $argv[2] ?? 'durable-probe';

switch ($mode) {
    case 'which':
        $env = require __DIR__ . '/app/etc/env.php';
        echo 'configured : lock.provider = ', $env['lock']['provider'] ?? '(absent, the framework default)', "\n";
        echo 'instantiated: ', get_class($locks), "\n";
        // The container returns a `Lock\\Proxy`: it says nothing of the backend
        // until it has been made to work. One call forces it to build it, and
        // reflection names it. A module that would refuse a non-shared lock at
        // start-up will have to go through this — get_class() lies.
        $locks->isLocked($name);
        foreach ((new \ReflectionObject($locks))->getProperties() as $property) {
            $value = $property->getValue($locks);
            if ($value instanceof \Magento\Framework\Lock\LockManagerInterface) {
                echo 'behind      : ', get_class($value), "\n";
            }
        }
        echo 'pid        : ', getmypid(), "\n";
        break;

    case 'hold':
        $seconds = (int) ($argv[3] ?? 5);
        $got = $locks->lock($name, 0);
        echo getmypid(), " hold  $name -> ", $got ? 'TAKEN' : 'REFUSED', "\n";
        if (!$got) {
            exit(1);
        }
        sleep($seconds);
        $locks->unlock($name);
        echo getmypid(), " hold  $name -> released after {$seconds}s\n";
        break;

    case 'try':
        $got = $locks->lock($name, 0);
        echo getmypid(), " try   $name -> ", $got ? 'TAKEN' : 'REFUSED', "\n";
        if ($got) {
            $locks->unlock($name);
        }
        exit($got ? 0 : 1);

    default:
        fwrite(STDERR, "usage: php probe-lock.php which|hold <name> <seconds>|try <name>\n");
        exit(2);
}
