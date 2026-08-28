<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Demo\Contracts;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationServed;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockServed;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les contrats de la démonstration sont des déclarations : il n'y a pas de logique à éprouver, mais
 * il y a trois manières de les écrire faux, et les trois échouent en silence.
 */
final class DemoNexusContractsTest extends TestCase
{
    /**
     * Le mode d'échec : un nom de service qui diverge entre l'interface servie et celle que
     * l'appelant lit. Le gestionnaire est enregistré sous un nom, l'appelant en adresse un autre,
     * et la tâche part vers un service que personne ne sert. Rien ne lève, rien ne trace.
     */
    public function testTheTwoHalvesOfAContractNameTheSameService(): void
    {
        $resolver = new NexusContractResolver();

        self::assertSame('stock', $resolver->serviceName(StockServed::class));
        self::assertSame('stock', $resolver->serviceName(StockContract::class));
        self::assertSame('facturation', $resolver->serviceName(FacturationServed::class));
        self::assertSame('facturation', $resolver->serviceName(FacturationContract::class));
    }

    /**
     * Le mode d'échec : une opération déclarée sur l'interface servie et invisible depuis le
     * contrat de l'appelant — déclarée, servie, et introuvable. C'est pourquoi le résolveur descend
     * les interfaces parentes, et c'est ce que cette assertion tient.
     */
    public function testTheCallerSeesTheInheritedOperationsToo(): void
    {
        $resolver = new NexusContractResolver();

        self::assertSame(['reserver' => 'reserver'], $resolver->operations(StockContract::class));

        // Trié : l'ordre est celui que rend la réflexion — les méthodes propres avant les héritées —
        // et ce n'est pas quelque chose que le routage lit.
        $facturation = $resolver->operations(FacturationContract::class);
        ksort($facturation);
        self::assertSame(['encaisser' => 'encaisser', 'verifier' => 'verifier'], $facturation);
    }

    /** @return iterable<string, array{class-string}> */
    public static function contracts(): iterable
    {
        yield 'stock' => [StockContract::class];
        yield 'facturation' => [FacturationContract::class];
    }

    /**
     * Le mode d'échec : un objet en paramètre ou en retour. La charge Nexus est du JSON simple,
     * décodé en tableau associatif de l'autre côté de la frontière — un paramètre typé `Ordre`
     * recevrait un tableau, et c'est un `TypeError` au moment où le gestionnaire est appelé, pas à
     * l'écriture du contrat. La contrainte est aussi ce qui permet à un gestionnaire écrit en Go ou
     * en TypeScript de lire les mêmes champs.
     */
    #[DataProvider('contracts')]
    public function testEveryOperationTravelsAsPlainJson(string $contract): void
    {
        $portables = ['bool', 'int', 'float', 'string', 'array'];

        foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
            if ([] === $method->getAttributes(AsNexusOperation::class)) {
                continue;
            }

            $types = [$method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }

            foreach ($types as $type) {
                self::assertInstanceOf(\ReflectionNamedType::class, $type, $method->getName() . '() : chaque type est déclaré');
                self::assertContains($type->getName(), $portables, \sprintf(
                    '%s::%s() emploie %s, qui ne survit pas à l\'aller-retour JSON.',
                    $contract,
                    $method->getName(),
                    $type->getName(),
                ));
            }
        }
    }
}
