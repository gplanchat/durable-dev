<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use PHPUnit\Framework\TestCase;

final class NexusContractResolverTest extends TestCase
{
    public function testTheServiceNameComesFromTheContract(): void
    {
        self::assertSame('facturation', (new NexusContractResolver())->serviceName(ContratServi::class));
    }

    public function testAnnotatedMethodsBecomeOperations(): void
    {
        $operations = (new NexusContractResolver())->operations(ContratServi::class);

        self::assertSame(['verifier' => 'verifier'], $operations);
    }

    public function testAnInheritedOperationIsPartOfTheExtendingContract(): void
    {
        // La différence qui compte avec le résolveur d'activité, qui ignore l'héritage. Ici le
        // contrat complet **étend** le contrat servi : c'est ce qui permet au gestionnaire de
        // n'implémenter que l'immédiat sans écrire une méthode vide. Sauter les méthodes héritées
        // ferait disparaître `verifier` de la vue de l'appelant — une opération déclarée, servie,
        // et que le stub ne saurait pas appeler.
        $operations = (new NexusContractResolver())->operations(ContratComplet::class);

        self::assertSame(
            ['encaisser' => 'encaisser', 'verifier' => 'verifier'],
            $operations,
        );
    }

    public function testAMethodWithoutTheAttributeIsNotAnOperation(): void
    {
        self::assertArrayNotHasKey('interne', (new NexusContractResolver())->operations(ContratAvecMethodeNue::class));
    }

    public function testAContractWithoutAServiceNameIsRefused(): void
    {
        // Contrairement à `#[AsActivity]`, qui est optionnel et retombe sur le nom de la méthode.
        // Ici le nom de service **adresse** une tâche : un repli sur le nom court de l'interface
        // produirait un nom que l'endpoint de l'appelant ne reconnaîtrait pas, et une opération qui
        // attend un gestionnaire dont le nom ne correspondra jamais.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/AsNexusService/');

        (new NexusContractResolver())->serviceName(ContratSansNom::class);
    }

    public function testTwoOperationsSharingAnameAreRefused(): void
    {
        // Le routage se fait par (service, opération) : deux méthodes du même nom d'opération
        // rendraient l'aiguillage arbitraire, et le perdant ne serait jamais appelé.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/encaisser/');

        (new NexusContractResolver())->operations(ContratAmbigu::class);
    }
}

#[AsNexusService('facturation')]
interface ContratServi
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratComplet extends ContratServi
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratAvecMethodeNue
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;

    public function interne(): void;
}

interface ContratSansNom
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratAmbigu
{
    #[AsNexusOperation('encaisser')]
    public function encaisserParCarte(string $ordre): string;

    #[AsNexusOperation('encaisser')]
    public function encaisserParVirement(string $ordre): string;
}
