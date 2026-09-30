<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The module only *suggests* the Temporal bridge, so it must install without it (#725).
 *
 * Magento's `ClassReader` reflects every constructor parameter type of what `di.xml` wires,
 * optional ones included, and loads each class it names. One bridge type there and
 * `setup:install` dies on `Class "Gplanchat\Bridge\Temporal\..." does not exist` on a host that
 * never asked for Temporal. The bridge is on this test's autoloader, so the check reads the
 * declared names rather than trying to load them — the same reading Magento does.
 */
final class TheRuntimeFactoryInstallsWithoutTheTemporalBridgeTest extends TestCase
{
    public function testItsConstructorNamesNoTemporalBridgeType(): void
    {
        $constructor = (new \ReflectionClass(RuntimeFactory::class))->getConstructor();
        self::assertNotNull($constructor);

        $bridgeTypes = [];
        foreach ($constructor->getParameters() as $parameter) {
            foreach (self::names($parameter->getType()) as $name) {
                if (str_starts_with(ltrim($name, '\\'), 'Gplanchat\\Bridge\\Temporal\\')) {
                    $bridgeTypes[] = \sprintf('$%s: %s', $parameter->getName(), $name);
                }
            }
        }

        self::assertSame([], $bridgeTypes);
    }

    /**
     * The parameter is `?object` now, so the type is checked where it is used: a `di.xml` that
     * hands something else is told so by name, not silently answered with curl.
     */
    public function testAJsonGatewayThatIsNotAPsr18HttpIsRefusedByName(): void
    {
        $factory = new RuntimeFactory(
            temporalDsn: 'temporal+http://127.0.0.1:7243?namespace=default',
            jsonGateway: new \stdClass(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonGateway must be a Gplanchat\Bridge\Temporal\Http\Psr18Http, stdClass given');

        $factory->catalog();
    }

    public function testACodecThatIsNotAPayloadCodecIsRefusedByName(): void
    {
        $factory = new RuntimeFactory(
            temporalDsn: 'temporal+http://127.0.0.1:7243?namespace=default',
            codec: new \stdClass(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('codec must be a Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface, stdClass given');

        $factory->catalog();
    }

    /** @return list<string> */
    private static function names(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(self::names(...), $type->getTypes()));
        }

        return [];
    }
}
