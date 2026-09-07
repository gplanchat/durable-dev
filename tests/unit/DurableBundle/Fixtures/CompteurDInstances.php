<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

/**
 * Compte ce que le conteneur a réellement construit.
 */
final class CompteurDInstances
{
    /** @var list<string> */
    private static array $construits = [];

    public static function reset(): void
    {
        self::$construits = [];
    }

    public static function note(string $quoi): void
    {
        self::$construits[] = $quoi;
    }

    /**
     * @return list<string>
     */
    public static function construits(): array
    {
        return self::$construits;
    }

    public static function total(): int
    {
        return \count(self::$construits);
    }
}
