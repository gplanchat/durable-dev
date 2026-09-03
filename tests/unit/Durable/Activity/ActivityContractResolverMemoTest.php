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
    #[AsActivityMethod(name: 'faire')]
    public function faire(string $quoi): string;
}

/**
 * Le résolveur est sur le chemin chaud : chaque appel d'activité passe par lui.
 *
 * Deux coûts s'y cachaient. Sans pool — et le pool est `null` par défaut — il refaisait la
 * réflexion à chaque appel. Avec un pool, il refaisait un aller-retour au pool à chaque appel, ce
 * qui sur un Redis est un aller-retour réseau pour une donnée dérivée du code, donc immuable dans
 * le processus.
 */
final class ActivityContractResolverMemoTest extends TestCase
{
    public function testLeSecondAppelNInterrogePasLePool(): void
    {
        $pool = new class implements CacheItemPoolInterface {
            public int $getItemCalls = 0;
            /** @var array<string, mixed> */
            private array $values = [];

            public function getItem(string $key): CacheItemInterface
            {
                ++$this->getItemCalls;
                $values = &$this->values;

                return new class($key, $values) implements CacheItemInterface {
                    /** @param array<string, mixed> $values */
                    public function __construct(private string $key, private array &$values) {}
                    public function getKey(): string { return $this->key; }
                    public function get(): mixed { return $this->values[$this->key] ?? null; }
                    public function isHit(): bool { return \array_key_exists($this->key, $this->values); }
                    public function set(mixed $value): static { $this->values[$this->key] = $value; return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(\DateInterval|int|null $time): static { return $this; }
                };
            }

            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(string $key): bool { return false; }
            public function clear(): bool { return true; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
        };

        $resolver = new ActivityContractResolver($pool);

        $premier = $resolver->resolveActivityMethods(MemoContract::class);
        $appresLePremier = $pool->getItemCalls;
        $second = $resolver->resolveActivityMethods(MemoContract::class);

        self::assertSame($premier, $second);
        self::assertSame(
            $appresLePremier,
            $pool->getItemCalls,
            'une donnée dérivée du code ne change pas dans le processus : le second appel doit être servi de mémoire',
        );
    }

    public function testSansPoolLeResultatResteLeMeme(): void
    {
        $resolver = new ActivityContractResolver();

        self::assertSame(
            ['faire' => 'memo.faire'],
            $resolver->resolveActivityMethods(MemoContract::class),
        );
        self::assertSame(
            ['faire' => 'memo.faire'],
            $resolver->resolveActivityMethods(MemoContract::class),
        );
    }
}
