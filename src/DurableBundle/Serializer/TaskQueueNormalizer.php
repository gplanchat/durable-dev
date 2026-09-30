<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Serializer;

use Gplanchat\Durable\TaskQueue;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * A TaskQueue as its name. The ObjectNormalizer rebuilds it through its private constructor,
 * without the name (#643).
 */
final readonly class TaskQueueNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        \assert($data instanceof TaskQueue);

        return $data->name();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TaskQueue;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): TaskQueue
    {
        if (!\is_string($data)) {
            throw new UnexpectedValueException(\sprintf('A task queue is a name, %s given.', get_debug_type($data)));
        }

        try {
            return TaskQueue::named($data);
        } catch (\InvalidArgumentException $e) {
            throw new UnexpectedValueException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return TaskQueue::class === $type;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [TaskQueue::class => true];
    }
}
