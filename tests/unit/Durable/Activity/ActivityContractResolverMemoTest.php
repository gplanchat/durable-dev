<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[AsActivity(name: 'memo')]
interface MemoContract
{
    #[AsActivityMethod(name: 'do')]
    public function do(string $what): string;
}

/**
 * The resolver is on the hot path: every activity call goes through it.
 *
 * Two costs hid there. Without a pool — and the pool is `null` by default — it redid the reflection
 * on every call. With a pool, it made a round trip to the pool on every call, which on Redis is a
 * network round trip for data derived from code, hence immutable within the process.
 */
final class ActivityContractResolverMemoTest extends TestCase
{
    public function testTheSecondCallDoesNotAskThePool(): void
    {
        $pool = new class implements CacheItemPoolInterface {
            public int $getItemCalls = 0;
            /** @var array<string, mixed> */
            private array $values = [];

            public function getItem(string $key): CacheItemInterface
            {
                ++$this->getItemCalls;
                $values = &$this->values;

                return new class ($key, $values) implements CacheItemInterface {
                    /** @param array<string, mixed> $values */
                    public function __construct(private string $key, private array &$values) {}
                    public function getKey(): string
                    {
                        return $this->key;
                    }
                    public function get(): mixed
                    {
                        return $this->values[$this->key] ?? null;
                    }
                    public function isHit(): bool
                    {
                        return \array_key_exists($this->key, $this->values);
                    }
                    public function set(mixed $value): static
                    {
                        $this->values[$this->key] = $value;

                        return $this;
                    }
                    public function expiresAt(?\DateTimeInterface $expiration): static
                    {
                        return $this;
                    }
                    public function expiresAfter(\DateInterval|int|null $time): static
                    {
                        return $this;
                    }
                };
            }

            /** @return iterable<string, \Psr\Cache\CacheItemInterface> */
            public function getItems(array $keys = []): iterable
            {
                return [];
            }
            public function hasItem(string $key): bool
            {
                return false;
            }
            public function clear(): bool
            {
                return true;
            }
            public function deleteItem(string $key): bool
            {
                return true;
            }
            public function deleteItems(array $keys): bool
            {
                return true;
            }
            public function save(CacheItemInterface $item): bool
            {
                return true;
            }
            public function saveDeferred(CacheItemInterface $item): bool
            {
                return true;
            }
            public function commit(): bool
            {
                return true;
            }
        };

        $resolver = new ActivityContractResolver($pool);

        $first = $resolver->resolveActivityMethods(MemoContract::class);
        $afterTheFirst = $pool->getItemCalls;
        $second = $resolver->resolveActivityMethods(MemoContract::class);

        self::assertSame($first, $second);
        self::assertSame(
            $afterTheFirst,
            $pool->getItemCalls,
            'data derived from code does not change within the process: the second call must be served from memory',
        );
    }

    public function testWithoutAPoolTheResultStaysTheSame(): void
    {
        $resolver = new ActivityContractResolver();

        self::assertSame(
            ['do' => 'memo.do'],
            $resolver->resolveActivityMethods(MemoContract::class),
        );
        self::assertSame(
            ['do' => 'memo.do'],
            $resolver->resolveActivityMethods(MemoContract::class),
        );
    }
}
