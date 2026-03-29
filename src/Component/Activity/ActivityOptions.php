<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Options de planification d'activité (retry, gestion d'erreur).
 *
 * Aligné sur les concepts Temporal (RetryOptions, ActivityOptions).
 */
final readonly class ActivityOptions
{
    public function __construct(
        /** Nombre max de tentatives (0 = illimité, délégation au worker). */
        public int $maxAttempts = 0,
        /** Délai initial avant première retentative (secondes). */
        public float $initialIntervalSeconds = 1.0,
        /** Coefficient d'exponential backoff. */
        public float $backoffCoefficient = 2.0,
        /** Délai max entre deux retentatives (secondes). */
        public ?float $maximumIntervalSeconds = null,
        /** Exceptions qui ne déclenchent pas de retry (class-string[]). */
        public array $nonRetryableExceptions = [],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
        );
    }

    public function withInitialInterval(float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $seconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
        );
    }

    public function withBackoffCoefficient(float $coefficient): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $coefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
        );
    }

    public function withMaximumInterval(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $seconds,
            $this->nonRetryableExceptions,
        );
    }

    /**
     * @param class-string[] $exceptions
     */
    public function withNonRetryableExceptions(array $exceptions): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $exceptions,
        );
    }

    /**
     * @return array<string, mixed> Métadonnées pour ActivityScheduled / ActivityMessage
     */
    public function toMetadata(): array
    {
        $meta = [
            'activity_options' => [
                'max_attempts' => $this->maxAttempts,
                'initial_interval_seconds' => $this->initialIntervalSeconds,
                'backoff_coefficient' => $this->backoffCoefficient,
                'non_retryable_exceptions' => $this->nonRetryableExceptions,
            ],
        ];
        if (null !== $this->maximumIntervalSeconds) {
            $meta['activity_options']['maximum_interval_seconds'] = $this->maximumIntervalSeconds;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): ?self
    {
        $opts = $metadata['activity_options'] ?? null;
        if (!\is_array($opts)) {
            return null;
        }

        return new self(
            (int) ($opts['max_attempts'] ?? 0),
            (float) ($opts['initial_interval_seconds'] ?? 1.0),
            (float) ($opts['backoff_coefficient'] ?? 2.0),
            isset($opts['maximum_interval_seconds']) ? (float) $opts['maximum_interval_seconds'] : null,
            \is_array($opts['non_retryable_exceptions'] ?? null) ? $opts['non_retryable_exceptions'] : [],
        );
    }

    public function isNonRetryable(\Throwable $e): bool
    {
        foreach ($this->nonRetryableExceptions as $exceptionClass) {
            if (is_a($e, $exceptionClass)) {
                return true;
            }
        }

        return false;
    }
}
