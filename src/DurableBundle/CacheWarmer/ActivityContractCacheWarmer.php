<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\CacheWarmer;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Pre-loads the activity contract metadata into the cache during warmup.
 */
final class ActivityContractCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param list<class-string> $contractClasses
     */
    public function __construct(
        private readonly ActivityContractResolver $resolver,
        private readonly array $contractClasses,
    ) {}

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->contractClasses as $class) {
            $this->resolver->resolveActivityMethods($class);
        }

        return [];
    }

    public function isOptional(): bool
    {
        return true;
    }
}
