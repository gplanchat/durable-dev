<?php

declare(strict_types=1);

namespace unit\Gplanchat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The core and the bridges ship without the Symfony bundle; the bundle wires them, never the
 * other way round. One reference upwards is a class a Laravel or Magento host cannot load (#345, #275).
 * Comments may still point at the bundle: a `{@see}` loads nothing.
 */
final class NoBundleImportBelowTheBundleTest extends TestCase
{
    public function testNeitherTheCoreNorABridgeReferencesTheBundle(): void
    {
        $offenders = [];
        foreach (['src/Durable', 'src/Bridge'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ('php' === $file->getExtension() && self::referencesTheBundle((string) file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function sources(): iterable
    {
        yield 'use statement' => ['use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;', true];
        yield 'use with a leading backslash' => ['use \Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;', true];
        yield 'group use' => ['use Gplanchat\Durable\{Bundle\Profiler\DurableExecutionTrace};', true];
        yield 'group use, spaced' => ['use Gplanchat\\Durable\\{ Bundle\\Profiler\\DurableExecutionTrace, Port\\X };', true];
        yield 'inline fully qualified name' => ['function f(?\Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace $t) {}', true];
        yield 'relative to the core namespace' => ["namespace Gplanchat\\Durable;\nfunction f(?Bundle\\Profiler\\DurableExecutionTrace \$t) {}", true];
        yield 'namespace-relative name' => ["namespace Gplanchat\\Durable;\nfunction f(?namespace\\Bundle\\Profiler\\DurableExecutionTrace \$t) {}", true];
        yield 'docblock link' => ["/** {@see \\Gplanchat\\Durable\\Bundle\\Profiler\\DurableExecutionTrace} */\nfinal class A {}", false];
        yield 'line comment' => ['// the bundle wires Gplanchat\Durable\Bundle\DurableBundle', false];
        yield 'relative name outside the core namespace' => ["namespace Gplanchat\\Bridge\\Temporal;\nfunction f(?Bundle\\Thing \$t) {}", false];
    }

    #[DataProvider('sources')]
    public function testTheGuardSeesEveryFormOfReference(string $code, bool $flagged): void
    {
        self::assertSame($flagged, self::referencesTheBundle("<?php\n" . $code));
    }

    /**
     * Comments and whitespace dropped, the rest glued back: every spelling of a name, grouped or
     * not, then reads as one string.
     */
    private static function referencesTheBundle(string $source): bool
    {
        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE, \T_OPEN_TAG])) {
                $code .= $token->text;
            }
        }

        if (1 === preg_match('/Gplanchat\\\\Durable\\\\\{?Bundle\\\\/', $code)) {
            return true;
        }

        // Inside `namespace Gplanchat\Durable`, `Bundle\X` and `namespace\Bundle\X` name the bundle too.
        return 1 === preg_match('/namespaceGplanchat\\\\Durable;/', $code)
            && 1 === preg_match('/(?<![\w\\\\])(namespace\\\\)?Bundle\\\\/', str_replace('namespaceGplanchat\\Durable;', '', $code));
    }
}
