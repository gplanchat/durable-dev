<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Codec;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalPolicyMapper;
use Gplanchat\Durable\SearchAttributes;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * Plain `json_encode()` writes 30.0 as 30, and it comes back as an int (#826, after #759 on the
 * DBAL and Illuminate journals).
 */
final class JsonPlainPayloadTest extends TestCase
{
    public function testAnIntegralFloatReadsBackAsAFloat(): void
    {
        $payload = JsonPlainPayload::encode(['price' => 30.0, 'quantity' => 3]);

        self::assertSame('{"price":30.0,"quantity":3}', $payload->getData());
        self::assertSame(['price' => 30.0, 'quantity' => 3], JsonPlainPayload::decode($payload));
    }

    public function testADoubleSearchAttributeKeepsItsFractionAndAnIntOneDoesNotGainOne(): void
    {
        $request = new StartWorkflowExecutionRequest();
        TemporalPolicyMapper::applySearchAttributes(
            SearchAttributes::none()->double('DurableRatio', 30)->int('DurableAmount', 30),
            $request,
        );
        $fields = $request->getSearchAttributes()?->getIndexedFields();

        self::assertNotNull($fields);
        self::assertSame('30.0', $fields->offsetGet('DurableRatio')->getData());
        self::assertSame('Double', $fields->offsetGet('DurableRatio')->getMetadata()->offsetGet('type'));
        self::assertSame('30', $fields->offsetGet('DurableAmount')->getData());
    }
}
