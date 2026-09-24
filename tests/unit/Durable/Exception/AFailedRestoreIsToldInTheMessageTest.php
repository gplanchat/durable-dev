<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Exception;

use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use PHPUnit\Framework\TestCase;

final class UnrestorableFailure extends \RuntimeException implements DeclaredActivityFailureInterface
{
    public function toActivityFailureContext(): array
    {
        return [];
    }

    public static function restoreFromActivityFailureContext(array $context): static
    {
        throw new \LogicException('payload shape changed');
    }
}

/**
 * C-14 (#329): a declared failure that cannot be restored falls back to the generic exception. Why
 * it fell back went to `error_log()`, where a host that logs elsewhere never saw it; it is now in
 * the message of the exception the workflow receives.
 */
final class AFailedRestoreIsToldInTheMessageTest extends TestCase
{
    public function testTheFallbackNamesTheDeclaredClassAndWhyItsRestoreFailed(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);

        try {
            $thrown = DurableActivityFailedException::toThrowable(new ActivityFailed('exec-1', 'act-1', UnrestorableFailure::class, 'refused', 0, [
                '_durable_declared' => true,
                '_durable_declared_class' => UnrestorableFailure::class,
                '_durable_declared_payload' => [],
            ], '', [], 'charge', 1));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertInstanceOf(DurableActivityFailedException::class, $thrown);
        self::assertStringContainsString(UnrestorableFailure::class, $thrown->getMessage());
        self::assertStringContainsString('payload shape changed', $thrown->getMessage());
        self::assertSame('', (string) file_get_contents($log), 'nothing written to error_log');
        unlink($log);
    }
}
