<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Serializer;

use Gplanchat\Durable\Duration;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * A Duration as its seconds, "infinity" when nothing bounds it (JSON has no INF).
 *
 * The ObjectNormalizer cannot take it: it reads `isZero()` as a `zero` property, then reads that
 * property through the static `Duration::zero()`, a new Duration on every call, and recurses
 * without end (#643).
 */
final readonly class DurationNormalizer implements NormalizerInterface, DenormalizerInterface
{
    private const INFINITY = 'infinity';

    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): float|string
    {
        \assert($data instanceof Duration);

        return $data->isInfinite() ? self::INFINITY : $data->toSeconds();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Duration;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Duration
    {
        if (self::INFINITY === $data) {
            return Duration::infinity();
        }
        if (!\is_int($data) && !\is_float($data)) {
            throw new UnexpectedValueException(\sprintf('A duration is a number of seconds or "%s", %s given.', self::INFINITY, get_debug_type($data)));
        }

        try {
            return Duration::seconds((float) $data);
        } catch (\InvalidArgumentException $e) {
            throw new UnexpectedValueException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Duration::class === $type;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Duration::class => true];
    }
}
