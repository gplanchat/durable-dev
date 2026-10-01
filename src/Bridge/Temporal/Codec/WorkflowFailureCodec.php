<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowFailedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
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
    /** The prefix {@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle::onFailed()} gives each. */
    private const ACTIVITY_KINDS = [
        WorkflowExecutionFailed::KIND_UNHANDLED_CATASTROPHIC_ACTIVITY => 'Workflow did not handle catastrophic activity failure: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY => 'Workflow did not handle activity failure: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY_SUPERSEDED => 'Workflow did not handle superseded activity: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_DECLARED_ACTIVITY => 'Workflow did not handle declared activity failure: ',
    ];

    private function __construct() {}

    public static function toThrowable(string $executionId, ?Failure $failure): \Throwable
    {
        $message = $failure?->getMessage() ?? '(unknown failure)';
        $details = self::details($failure);

        if (null === $details) {
            return new WorkflowFailedException($executionId, $message);
        }

        $kind = (string) $details['kind'];
        if (isset(self::ACTIVITY_KINDS[$kind])) {
            return new DurableWorkflowAlgorithmFailureException(
                self::ACTIVITY_KINDS[$kind] . (string) ($details['failureMessage'] ?? ''),
                0,
                self::activityException($executionId, $kind, $details['cause'] ?? null) ?? new WorkflowFailedException($executionId, $message),
            );
        }

        try {
            $rebuilt = match ($kind) {
                WorkflowExecutionFailed::KIND_UNHANDLED_NEXUS_OPERATION => self::nexusException($details),
                WorkflowExecutionFailed::KIND_DEADLINE_EXCEEDED => new DeadlineExceededException(
                    Duration::seconds((float) ($details['context']['deadlineSeconds'] ?? 0)),
                    (string) ($details['context']['awaited'] ?? ''),
                ),
                default => self::workflowException($details),
            };
        } catch (\Throwable) {
            $rebuilt = null;
        }

        return null !== $rebuilt && $rebuilt->getMessage() === ($details['failureMessage'] ?? null)
            ? $rebuilt
            : new WorkflowFailedException($executionId, $message);
    }

    /**
     * What the workflow's failure needs, beyond its message, to be rebuilt where the caller
     * waits: the activity's own exception, or the envelope of a Nexus failure.
     *
     * @return array<string, mixed>|null
     */
    public static function cause(\Throwable $reason): ?array
    {
        return match (true) {
            $reason instanceof DurableCatastrophicActivityFailureException => $reason->event()->payload(),
            $reason instanceof DurableActivityFailedException => [
                'activityId' => $reason->activityId(),
                'activityName' => $reason->activityName(),
                'attempt' => $reason->attempt(),
                'envelope' => $reason->envelope()->toArray(),
            ],
            $reason instanceof ActivitySupersededException => [
                'activityId' => $reason->activityId(),
                'cancellationReason' => $reason->cancellationReason(),
            ],
            $reason instanceof DurableNexusOperationFailedException => ['envelope' => $reason->envelope()->toArray()],
            $reason instanceof DeclaredActivityFailureInterface => ['envelope' => FailureEnvelope::fromThrowable($reason)->toArray()],
            default => null,
        };
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
     * The activity's exception, as the workflow received it. Null for a history written before
     * the worker recorded it.
     */
    private static function activityException(string $executionId, string $kind, mixed $cause): ?\Throwable
    {
        if (!\is_array($cause)) {
            return null;
        }

        try {
            return match ($kind) {
                WorkflowExecutionFailed::KIND_UNHANDLED_CATASTROPHIC_ACTIVITY => new DurableCatastrophicActivityFailureException(
                    ActivityCatastrophicFailure::fromStoredPayload(ExecutionId::fromString($executionId), $cause),
                ),
                WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY_SUPERSEDED => new ActivitySupersededException(
                    (string) $cause['activityId'],
                    (string) $cause['cancellationReason'],
                ),
                default => DurableActivityFailedException::toThrowable(ActivityFailed::fromEnvelope(
                    ExecutionId::fromString($executionId),
                    (string) ($cause['activityId'] ?? ''),
                    self::envelope($cause['envelope'] ?? null),
                    (string) ($cause['activityName'] ?? ''),
                    (int) ($cause['attempt'] ?? 0),
                )),
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $details */
    private static function nexusException(array $details): ?DurableNexusOperationFailedException
    {
        $context = \is_array($details['context'] ?? null) ? $details['context'] : [];
        $kind = NexusOperationFailureKind::tryFrom((string) ($context['nexusKind'] ?? ''));
        $cause = $details['cause'] ?? null;
        if (null === $kind || !\is_array($cause)) {
            return null;
        }

        return new DurableNexusOperationFailedException(
            (string) ($context['endpoint'] ?? ''),
            (string) ($context['service'] ?? ''),
            (string) ($context['operation'] ?? ''),
            $kind,
            self::envelope($cause['envelope'] ?? null),
            \is_string($context['retryBehaviour'] ?? null) ? $context['retryBehaviour'] : null,
        );
    }

    private static function envelope(mixed $envelope): FailureEnvelope
    {
        if (!\is_array($envelope)) {
            throw new \UnexpectedValueException('The failure carries no envelope.');
        }

        /** @var list<array{class: string, message: string, code: int}> $previousChain */
        $previousChain = \is_array($envelope['previousChain'] ?? null) ? $envelope['previousChain'] : [];

        return new FailureEnvelope(
            (string) ($envelope['class'] ?? ''),
            (string) ($envelope['message'] ?? ''),
            (int) ($envelope['code'] ?? 0),
            \is_array($envelope['context'] ?? null) ? $envelope['context'] : [],
            \is_string($envelope['trace'] ?? null) ? $envelope['trace'] : null,
            $previousChain,
        );
    }

    /**
     * The workflow's own exception, built through its constructor as `(message, code)`. Null when
     * the class does not load here or is not a Throwable; a constructor that throws, or gives
     * another message, is caught by {@see toThrowable()}.
     *
     * @param array<string, mixed> $details
     */
    private static function workflowException(array $details): ?\Throwable
    {
        $class = (string) ($details['failureClass'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
            return null;
        }

        return (new \ReflectionClass($class))->newInstance((string) ($details['failureMessage'] ?? ''), (int) ($details['failureCode'] ?? 0));
    }
}
