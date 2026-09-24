<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Exception;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use PHPUnit\Framework\TestCase;

/**
 * A host catches "a Durable error" with one type, as it does with any Symfony component (#330).
 *
 * The control-flow signals stay out: they end a pass of workflow code on purpose, and a
 * `catch (ExceptionInterface)` in that code must not swallow them.
 */
final class EveryDurableErrorIsCatchableAsOneTest extends TestCase
{
    private const CONTROL_FLOW = [
        WorkflowSuspendedException::class,
        ContinueAsNewRequested::class,
        ChildWorkflowStartDeferred::class,
    ];

    public function testEveryThrowableInTheCoreImplementsTheMarkerExceptTheControlFlowSignals(): void
    {
        $root = \dirname(__DIR__, 4) . '/src/Durable';
        $seen = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            // Only files that declare a throwable are loaded: the rest may need optional packages.
            if (!str_ends_with($file->getFilename(), '.php')
                || !preg_match('/^(?:final |abstract )?class \w+ extends \\\\?\w*(?:Exception|Error)\b/m', (string) file_get_contents($file->getPathname()))) {
                continue;
            }
            $class = 'Gplanchat\Durable\\' . str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
            self::assertTrue(is_a($class, \Throwable::class, true), $class);
            ++$seen;

            if (\in_array($class, self::CONTROL_FLOW, true)) {
                self::assertFalse(is_a($class, ExceptionInterface::class, true), $class . ' is control flow, not an error.');
                continue;
            }
            self::assertTrue(is_a($class, ExceptionInterface::class, true), $class . ' cannot be caught as a Durable error.');
        }

        self::assertGreaterThanOrEqual(18, $seen, 'the scan found the throwables');
    }
}
