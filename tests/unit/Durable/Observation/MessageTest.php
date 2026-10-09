<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testAMessageIsAKeyAndItsParameters(): void
    {
        $message = new Message('run.waiting_for_worker', ['elapsed' => '5 min']);

        self::assertSame('run.waiting_for_worker', $message->key);
        self::assertSame(['elapsed' => '5 min'], $message->params);
    }

    public function testParametersDefaultToNone(): void
    {
        self::assertSame([], (new Message('backend.not_configured'))->params);
    }
}
