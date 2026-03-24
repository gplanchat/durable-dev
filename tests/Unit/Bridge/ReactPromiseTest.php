<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit\Bridge;

use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Bridge\ReactPromise;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReactPromise::class)]
#[Group('bridge')]
final class ReactPromiseTest extends TestCase
{
    #[Test]
    public function toReactPromiseResolvesWithValue(): void
    {
        $awaitable = Deferred::resolved(42);
        $promise = ReactPromise::toReactPromise($awaitable);

        $result = null;
        $promise->then(static function ($v) use (&$result) {
            $result = $v;
        });

        self::assertSame(42, $result);
    }
}
