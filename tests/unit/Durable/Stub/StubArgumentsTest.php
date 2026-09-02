<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Stub;

use Gplanchat\Durable\Stub\StubArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un stub `__call` transforme des arguments PHP en charge nommée. Les trois façons de se tromper
 * sont ici, et les trois étaient silencieuses.
 */
#[CoversClass(StubArguments::class)]
final class StubArgumentsTest extends TestCase
{
    public function testPositionalArgumentsLandOnTheirParameter(): void
    {
        self::assertSame(['text' => 'bonjour', 'times' => 3, 'tag' => 'défaut'], $this->map(['bonjour', 3]));
    }

    /**
     * Le défaut qui a coûté un après-midi : PHP passe les arguments nommés à `__call` dans un
     * tableau à **clés de chaînes**. Appariés par indice, ils disparaissaient tous — et chaque
     * paramètre retombait sur sa valeur par défaut, sans exception ni trace.
     */
    public function testNamedArgumentsLandOnTheirParameter(): void
    {
        self::assertSame(['text' => 'bonjour', 'times' => 1, 'tag' => 'perso'], $this->map(['tag' => 'perso', 'text' => 'bonjour']));
    }

    public function testPositionalAndNamedArgumentsMix(): void
    {
        self::assertSame(['text' => 'bonjour', 'times' => 1, 'tag' => 'perso'], $this->map(['bonjour', 'tag' => 'perso']));
    }

    /**
     * `??` confondait « absent » et « null » : passer explicitement `null` rendait la valeur par
     * défaut, c'est-à-dire l'inverse de ce qui était demandé.
     */
    public function testAnExplicitNullIsNotTheDefault(): void
    {
        self::assertNull($this->map(['text' => 'x', 'tag' => null])['tag']);
        self::assertNull($this->map(['x', 1, null])['tag']);
    }

    /**
     * Une faute de frappe dans un nom d'argument ne doit pas être indiscernable d'une valeur par
     * défaut voulue. PHP lève sur un appel ordinaire ; le stub aussi.
     */
    public function testAnUnknownNamedArgumentIsRefused(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/Unknown named parameter \$tags/');

        $this->map(['text' => 'x', 'tags' => 'perso']);
    }

    /**
     * Un paramètre sans défaut et non fourni vaut `null` : c'est le comportement d'avant, et la
     * validation reste au gestionnaire.
     */
    public function testAMissingRequiredParameterIsNull(): void
    {
        self::assertNull($this->map([])['text']);
    }

    /**
     * @param array<int|string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function map(array $arguments): array
    {
        $contract = new class {
            public function greet(string $text, int $times = 1, ?string $tag = 'défaut'): void
            {
            }
        };

        return StubArguments::toPayload(new \ReflectionMethod($contract, 'greet'), $arguments);
    }
}
