<?php

declare(strict_types=1);

namespace integration\Temporal;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The retry the 1.20 job leans on (#718), without a server: it rides out the mapping lag, and a
 * mapping that never comes still fails the suite with the attribute's name.
 */
final class FreshNamespaceOnceMappedTest extends TestCase
{
    use FreshNamespace;

    private const UNMAPPED = 'BadSearchAttributes: Namespace durable-conformance-x has no mapping defined for search attribute DurableExecutionId';

    public function testItRetriesWhileTheMappingIsMissing(): void
    {
        $calls = 0;
        $result = self::onceMapped('A continue-as-new', static function () use (&$calls): string {
            if (++$calls < 3) {
                throw new \RuntimeException(self::UNMAPPED, 3);
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(3, $calls);
    }

    public function testAMappingThatNeverComesFailsNamingTheAttribute(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/^A continue-as-new .*DurableExecutionId/');

        self::onceMapped('A continue-as-new', static fn() => throw new \RuntimeException(self::UNMAPPED, 3), 0.3);
    }

    public function testAnyOtherErrorGoesThroughAtOnce(): void
    {
        $calls = 0;
        $this->expectExceptionObject(new \RuntimeException('unavailable', 14));

        try {
            self::onceMapped('A start', static function () use (&$calls): never {
                ++$calls;

                throw new \RuntimeException('unavailable', 14);
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }
}
