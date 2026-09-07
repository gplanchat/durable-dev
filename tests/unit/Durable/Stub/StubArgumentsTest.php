<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Stub;

use Gplanchat\Durable\Stub\StubArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A `__call` stub turns PHP arguments into a named payload. The three ways of getting it wrong are
 * here, and all three were silent.
 */
#[CoversClass(StubArguments::class)]
final class StubArgumentsTest extends TestCase
{
    public function testPositionalArgumentsLandOnTheirParameter(): void
    {
        self::assertSame(['text' => 'bonjour', 'times' => 3, 'tag' => 'défaut'], $this->map(['bonjour', 3]));
    }

    /**
     * The defect that cost an afternoon: PHP passes named arguments to `__call` in an array with
     * **string keys**. Matched by index, they all disappeared — and every parameter fell back to
     * its default value, with no exception and no trace.
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
     * `??` confused "absent" and "null": passing `null` explicitly returned the default value,
     * that is to say the opposite of what was asked for.
     */
    public function testAnExplicitNullIsNotTheDefault(): void
    {
        self::assertNull($this->map(['text' => 'x', 'tag' => null])['tag']);
        self::assertNull($this->map(['x', 1, null])['tag']);
    }

    /**
     * A typo in an argument name must not be indistinguishable from a deliberate default value.
     * PHP throws on an ordinary call; so does the stub.
     */
    public function testAnUnknownNamedArgumentIsRefused(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/Unknown named parameter \$tags/');

        $this->map(['text' => 'x', 'tags' => 'perso']);
    }

    /**
     * A required parameter that is not supplied throws, as PHP would throw `ArgumentCountError` on
     * the corresponding ordinary call.
     *
     * Letting it stand as `null` carried the fault all the way into the journal, where it replays
     * identically on every pass: `$text` is declared `string`, so a payload carrying `null` is
     * refused on arrival anyway — but one replay pass later, in a worker, far from the offending
     * call.
     */
    public function testAMissingRequiredParameterThrows(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/Missing required argument \$text/');

        $this->map([]);
    }

    /**
     * Supplying the same parameter positionally and then by name: PHP refuses ("Named parameter $x
     * overwrites previous argument"), the stub silently chose the positional one.
     */
    public function testAParameterServedTwiceThrows(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/\$text.*both positionally and by name/');

        $this->map([0 => 'positionnel', 'text' => 'nommé']);
    }

    /**
     * The neighbouring case, which has to keep passing: an optional parameter that is not supplied
     * takes its default value, and an explicit `null` stays `null`.
     */
    public function testAnOptionalParameterKeepsItsDefaultAndAcceptsNull(): void
    {
        self::assertSame(1, $this->map(['text' => 'x'])['times']);
        self::assertNull($this->map(['text' => 'x', 'tag' => null])['tag']);
    }

    /**
     * @param array<int|string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function map(array $arguments): array
    {
        $contract = new class {
            public function greet(string $text, int $times = 1, ?string $tag = 'défaut'): void {}
        };

        return StubArguments::toPayload(new \ReflectionMethod($contract, 'greet'), $arguments);
    }
}
