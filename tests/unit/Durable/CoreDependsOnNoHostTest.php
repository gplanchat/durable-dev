<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use PHPUnit\Framework\TestCase;

/**
 * Le cœur ne dépend d'aucun hôte, et cette garde existe parce qu'il en dépendait.
 *
 * `gplanchat/durable` ne requiert ni le bundle Symfony ni aucun pont : c'est la promesse du
 * composant, et ce qui rend « le même workflow tourne partout » vrai plutôt qu'aspirationnel. Une
 * seule ligne l'avait rompue — `InMemoryWorkflowRunner` important
 * `Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` — et rien ne le disait : sous
 * Symfony le bundle est là, donc tout marche. La panne n'apparaît que sur un hôte qui ne l'installe
 * pas, au moment d'une reprise, sous la forme d'une erreur fatale de classe introuvable. Magento
 * l'a trouvée en rejouant une commande tuée au milieu.
 *
 * Les `@see` en bloc de documentation sont tolérés : ils ne se chargent pas.
 */
final class CoreDependsOnNoHostTest extends TestCase
{
    private const CORE = __DIR__ . '/../../../src/Durable';

    /**
     * @return iterable<string, array{string}>
     */
    public static function coreFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::CORE));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname() => [$file->getPathname()];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coreFiles')]
    public function testNoCoreFileImportsAHostOrABridge(string $path): void
    {
        $forbidden = [];
        foreach (file($path) ?: [] as $line) {
            if (preg_match('/^use (Gplanchat\\\\Durable\\\\Bundle\\\\|Gplanchat\\\\Bridge\\\\)\S+/', $line, $match)) {
                $forbidden[] = trim($match[0]);
            }
        }

        self::assertSame([], $forbidden, sprintf(
            "%s imports a host package. gplanchat/durable requires neither the Symfony bundle nor any bridge, so this is a fatal error on every host that does not install it — and it looks perfectly healthy under Symfony.",
            basename($path),
        ));
    }
}
