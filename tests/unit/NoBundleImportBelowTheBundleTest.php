<?php

declare(strict_types=1);

namespace unit\Gplanchat;

use PHPUnit\Framework\TestCase;

/**
 * The core and the bridges ship without the Symfony bundle; the bundle wires them, never the
 * other way round. One import upwards is a class a Laravel or Magento host cannot load (#345, #275).
 */
final class NoBundleImportBelowTheBundleTest extends TestCase
{
    public function testNeitherTheCoreNorABridgeImportsTheBundle(): void
    {
        $offenders = [];
        foreach (['src/Durable', 'src/Bridge'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ('php' === $file->getExtension() && preg_match('/^use Gplanchat\\\\Durable\\\\Bundle\\\\/m', (string) file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
