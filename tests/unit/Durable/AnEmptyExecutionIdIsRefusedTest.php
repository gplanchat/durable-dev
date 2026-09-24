<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;

/**
 * M8 (#329): an empty identifier names no execution, and every store would file it under "".
 */
final class AnEmptyExecutionIdIsRefusedTest extends TestCase
{
    public function testAnEmptyStringIsNotAnExecutionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExecutionId::fromString('');
    }

    public function testAnyOtherStringIs(): void
    {
        self::assertSame('exec-1', ExecutionId::fromString('exec-1')->toString());
    }
}
