<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Google\Protobuf\Any;
use Google\Protobuf\Descriptor;
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
    private const PAYLOAD = 'temporal.api.common.v1.Payload';
    private const ANY = 'google.protobuf.Any';

    /** @var array<class-string<Message>, list<array{string, string}>> */
    private static array $payloadFields = [];

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
        foreach (self::payloadFieldsOf($message::class) as [$getter, $setter]) {
            $value = $message->{$getter}();
            if ($value instanceof Payload) {
                $message->{$setter}($transform($value));
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

    /**
     * The fields of a message class that can lead to a payload, as getter and setter names: read
     * from the descriptors once per class, then walked without them (#764). A field whose type holds
     * no payload at any depth is left out; so are search attributes.
     *
     * @param class-string<Message> $class
     *
     * @return list<array{string, string}>
     */
    private static function payloadFieldsOf(string $class): array
    {
        if (isset(self::$payloadFields[$class])) {
            return self::$payloadFields[$class];
        }
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($class);
        $fields = [];
        if (self::SEARCH_ATTRIBUTES !== $descriptor->getFullName()) {
            for ($index = 0; $index < $descriptor->getFieldCount(); ++$index) {
                $field = $descriptor->getField($index);
                if (GPBType::MESSAGE === $field->getType() && self::leadsToAPayload($field->getMessageType(), [])) {
                    $name = str_replace('_', '', ucwords($field->getName(), '_'));
                    $fields[] = ['get' . $name, 'set' . $name];
                }
            }
        }

        return self::$payloadFields[$class] = $fields;
    }

    /**
     * An `Any` may pack anything, so it counts as a payload. A map field's type is its entry, whose
     * value field this follows like any other. A type met again on the way down is a cycle
     * (`Failure.cause`): it adds nothing the first visit does not already check.
     *
     * @param array<string, true> $visiting
     */
    private static function leadsToAPayload(Descriptor $descriptor, array $visiting): bool
    {
        $name = $descriptor->getFullName();
        if (self::PAYLOAD === $name || self::ANY === $name) {
            return true;
        }
        if (self::SEARCH_ATTRIBUTES === $name || isset($visiting[$name])) {
            return false;
        }
        $visiting[$name] = true;
        for ($index = 0; $index < $descriptor->getFieldCount(); ++$index) {
            $field = $descriptor->getField($index);
            if (GPBType::MESSAGE === $field->getType() && self::leadsToAPayload($field->getMessageType(), $visiting)) {
                return true;
            }
        }

        return false;
    }
}
