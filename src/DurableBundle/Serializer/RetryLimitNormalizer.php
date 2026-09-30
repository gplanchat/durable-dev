<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Serializer;

use Gplanchat\Durable\Activity\RetryLimit;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * A RetryLimit as its wire value: the attempts allowed, 0 for unlimited, as on Temporal.
 *
 * The ObjectNormalizer cannot take it: it reads `isUnlimited()` as an `unlimited` property, then
 * reads that property through the static `RetryLimit::unlimited()`, a new RetryLimit on every
 * call, and recurses without end (#643).
 */
final readonly class RetryLimitNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): int
    {
        \assert($data instanceof RetryLimit);

        return $data->toWireValue();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RetryLimit;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): RetryLimit
    {
        if (!\is_int($data)) {
            throw new UnexpectedValueException(\sprintf('A retry limit is a number of attempts, %s given.', get_debug_type($data)));
        }

        return RetryLimit::fromWireValue($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return RetryLimit::class === $type;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [RetryLimit::class => true];
    }
}
