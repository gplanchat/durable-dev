<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Worker\InboundUpdate;
use Gplanchat\Bridge\Temporal\Worker\UpdateProtocol;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Workflow\PendingUpdate;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Update\V1\Meta;
use Temporal\Api\Update\V1\Request;
use Temporal\Api\Update\V1\Response;

/**
 * A failed update tells the server which exception class failed, as an activity does (#1008).
 */
final class UpdateFailureTypeTest extends TestCase
{
    public function testTheResponseOfAFailedUpdateCarriesTheFailureClassAsApplicationFailureType(): void
    {
        $pending = new PendingUpdate('approve');
        $pending->handled = true;
        $pending->failure = new FailureEnvelope(\DomainException::class, 'nope');
        $request = (new Request())->setMeta(new Meta(['update_id' => 'upd-a']));

        $reply = UpdateProtocol::reply([new InboundUpdate($pending, 'msg-1', 3, $request)]);

        $response = new Response();
        $response->mergeFromString($reply['messages'][1]->getBody()->getValue());
        $failure = $response->getOutcome()?->getFailure();
        self::assertSame('nope', $failure?->getMessage());
        self::assertSame(\DomainException::class, $failure->getApplicationFailureInfo()?->getType());
    }
}
