<?php

declare(strict_types=1);

/*
 * Sonde — `LockManagerInterface` est-il partagé entre processus ?
 *
 * Tout le module repose là-dessus : deux `queue:consumers:start` peuvent
 * dépiler deux reprises d'une même exécution, et seul un verrou partagé les
 * sérialise. `Magento\Framework\Lock\Backend\Database` *devrait* l'être — un
 * `GET_LOCK` sur la base applicative — mais « devrait » est exactement ce
 * qu'une sonde existe pour vérifier. Elle mesure à deux processus, elle ne lit
 * pas la classe : c'est ce qui est **configuré** sur l'hôte qui décide, pas ce
 * que le framework livre par défaut.
 *
 *   php probe-lock.php which          ce que le conteneur a réellement câblé
 *   php probe-lock.php hold <n> <s>   prend le verrou <n> et le tient <s> s
 *   php probe-lock.php try  <n>       tente <n> sans attendre, rend 0/1
 *
 * `try` sort en 0 s'il a obtenu le verrou, 1 sinon : de quoi enchaîner les
 * deux processus depuis un shell.
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$locks = $bootstrap->getObjectManager()->get(\Magento\Framework\Lock\LockManagerInterface::class);

$mode = $argv[1] ?? 'which';
$name = $argv[2] ?? 'durable-probe';

switch ($mode) {
    case 'which':
        $env = require __DIR__ . '/app/etc/env.php';
        echo 'configuré  : lock.provider = ', $env['lock']['provider'] ?? '(absent, défaut du framework)', "\n";
        echo 'instancié  : ', get_class($locks), "\n";
        // Le conteneur rend une `Lock\\Proxy` : elle ne dit rien du backend tant
        // qu'on ne l'a pas fait travailler. Un appel la force à le construire,
        // et la réflexion le nomme. Un module qui voudrait refuser un verrou
        // non partagé au démarrage devra passer par là — get_class() ment.
        $locks->isLocked($name);
        foreach ((new \ReflectionObject($locks))->getProperties() as $property) {
            $value = $property->getValue($locks);
            if ($value instanceof \Magento\Framework\Lock\LockManagerInterface) {
                echo 'derrière   : ', get_class($value), "\n";
            }
        }
        echo 'pid        : ', getmypid(), "\n";
        break;

    case 'hold':
        $seconds = (int) ($argv[3] ?? 5);
        $got = $locks->lock($name, 0);
        echo getmypid(), " hold  $name -> ", $got ? 'PRIS' : 'REFUSÉ', "\n";
        if (!$got) {
            exit(1);
        }
        sleep($seconds);
        $locks->unlock($name);
        echo getmypid(), " hold  $name -> relâché après {$seconds}s\n";
        break;

    case 'try':
        $got = $locks->lock($name, 0);
        echo getmypid(), " try   $name -> ", $got ? 'PRIS' : 'REFUSÉ', "\n";
        if ($got) {
            $locks->unlock($name);
        }
        exit($got ? 0 : 1);

    default:
        fwrite(STDERR, "usage: php probe-lock.php which|hold <nom> <secondes>|try <nom>\n");
        exit(2);
}
