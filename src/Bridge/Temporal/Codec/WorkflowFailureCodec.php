<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Gplanchat\Durable\Exception\WorkflowFailedException;
use Temporal\Api\Failure\V1\Failure;

/**
 * Reads back the failure a workflow closed with, as the exception the journal backends raise for
 * it ({@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle::onFailed()}).
 *
 * The worker writes the classified failure ({@see \Gplanchat\Durable\Event\WorkflowExecutionFailed})
 * into the `details` of the ApplicationFailureInfo. A failure this class cannot rebuild comes
 * back as {@see WorkflowFailedException}, with the message the server recorded.
 *
 * @internal
 */
final class WorkflowFailureCodec
{
    private function __construct() {}

    public static function toThrowable(string $executionId, ?Failure $failure): \Throwable
    {
        $message = $failure?->getMessage() ?? '(unknown failure)';
        $details = self::details($failure);

        return (null === $details ? null : self::workflowException($details))
            ?? new WorkflowFailedException($executionId, $message);
    }

    /**
     * The classified failure the worker wrote, or null when the failure carries none.
     *
     * @return array<string, mixed>|null
     */
    public static function details(?Failure $failure): ?array
    {
        $details = $failure?->getApplicationFailureInfo()?->getDetails();
        if (null === $details || 0 === $details->getPayloads()->count()) {
            return null;
        }
        $decoded = JsonPlainPayload::decode($details->getPayloads()[0]);

        return \is_array($decoded) && isset($decoded['kind']) ? $decoded : null;
    }

    /**
     * The workflow's own exception, built through its constructor as `(message, code)`. Null when
     * the class does not load here, is not a Throwable, or its constructor gives another message.
     *
     * @param array<string, mixed> $details
     */
    private static function workflowException(array $details): ?\Throwable
    {
        $class = (string) ($details['failureClass'] ?? '');
        $message = (string) ($details['failureMessage'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
            return null;
        }

        try {
            $exception = (new \ReflectionClass($class))->newInstance($message, (int) ($details['failureCode'] ?? 0));
        } catch (\Throwable) {
            return null;
        }

        return $exception instanceof \Throwable && $exception->getMessage() === $message ? $exception : null;
    }
}
