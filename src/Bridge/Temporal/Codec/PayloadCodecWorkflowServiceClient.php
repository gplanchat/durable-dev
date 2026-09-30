<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Google\Protobuf\Any;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Temporal\Api\Common\V1\Payload;

/**
 * Applies a {@see PayloadCodecInterface} where every RPC passes (DUR055): the payloads of a request
 * are encoded on the way out, those of a response decoded on the way in.
 *
 * The walk reads the generated descriptors, so a message a later API version adds is covered with
 * no list to keep. Search attributes are skipped by message type: the server must index them.
 * An `Any` is unpacked, walked and packed again: the update protocol carries its requests and
 * results that way. This is where Temporal's Go SDK puts its gRPC codec interceptor, and what it
 * skips.
 *
 * The request is copied, which costs its size once more; the response is decoded in place, so the
 * inner client must hand out a response of its own, not a shared one.
 */
final class PayloadCodecWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    private const SEARCH_ATTRIBUTES = 'temporal.api.common.v1.SearchAttributes';

    public function __construct(
        private readonly WorkflowServiceClientInterface $inner,
        private readonly PayloadCodecInterface $codec,
    ) {}

    /**
     * @template T of Message
     *
     * @param class-string<T>      $responseClass
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     *
     * @return T
     */
    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        // A copy: a caller that sends the same request again must not find it encoded twice.
        $copy = new ($request::class)();
        $copy->mergeFromString($request->serializeToString());
        $this->walk($copy, $this->codec->encode(...));

        $response = $this->inner->{$rpc}($copy, $metadata, $options);
        \assert($response instanceof $responseClass);
        $this->walk($response, $this->codec->decode(...));

        return $response;
    }

    /**
     * @param \Closure(Payload): Payload $transform
     */
    private function walk(Message $message, \Closure $transform): void
    {
        if ($message instanceof Any) {
            // An unknown type throws here rather than leaving its payloads untouched.
            $packed = $message->unpack();
            $this->walk($packed, $transform);
            $message->pack($packed);

            return;
        }
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);
        if (self::SEARCH_ATTRIBUTES === $descriptor->getFullName()) {
            return;
        }

        for ($index = 0; $index < $descriptor->getFieldCount(); ++$index) {
            $field = $descriptor->getField($index);
            if (GPBType::MESSAGE !== $field->getType()) {
                continue;
            }
            $name = str_replace('_', '', ucwords($field->getName(), '_'));
            $value = $message->{'get' . $name}();
            if ($value instanceof Payload) {
                $message->{'set' . $name}($transform($value));
            } elseif ($value instanceof Message) {
                $this->walk($value, $transform);
            } elseif (is_iterable($value)) {
                // A repeated field or a map: both are replaced item by item, keys kept.
                foreach ($value as $key => $item) {
                    if ($item instanceof Payload) {
                        $value[$key] = $transform($item);
                    } elseif ($item instanceof Message) {
                        $this->walk($item, $transform);
                    }
                }
            }
        }
    }
}
