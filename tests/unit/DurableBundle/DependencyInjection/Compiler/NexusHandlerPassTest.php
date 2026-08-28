<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\NexusHandlerPass;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NexusHandlerPass::class)]
final class NexusHandlerPassTest extends TestCase
{
    public function testEachOperationOfTheContractIsRegistered(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingServedFixture::class]);

        (new NexusHandlerPass())->process($container);

        $calls = $container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertEquals(NexusService::named('billing'), $calls[0][1][0]);
        self::assertEquals(NexusOperationName::named('verify'), $calls[0][1][1]);
    }

    public function testAnOperationNobodyCoversIsRefusedAtStartup(): void
    {
        // Le cœur de cette passe. `charge` est déclarée par le contrat de l'appelant, aucun
        // gestionnaire ne l'implémente et aucun workflow ne la réclame : elle serait servie par
        // personne, et l'appelant attendrait un résultat que rien ne produira. Comme il n'y a
        // aucune requête à faire échouer plus tard, le refus a lieu au montage ou nulle part.
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingContractFixture::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/charge/');
        $this->expectExceptionMessageMatches('/no workflow claims it/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAWorkflowThatClaimsTheOperationCoversIt(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingContractFixture::class]);
        $container->register('app.charge', ChargeWorkflowFixture::class)
            ->addTag('durable.workflow');

        (new NexusHandlerPass())->process($container);

        $calls = $container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls();
        self::assertCount(1, $calls, 'seule l’opération implémentée s’enregistre ; la différée est portée par le workflow');
    }

    public function testAHandlerThatServesNothingIsRefused(): void
    {
        // Une classe qui n'implémente aucune opération du contrat se fait prendre par la
        // couverture, opération par opération. Il n'y a pas de contrôle `is_a` : la balise peut
        // nommer le contrat complet, dont le gestionnaire n'implémente que la part servie.
        $container = $this->containerWithRegistry();
        $container->register('app.billing', NotAHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingServedFixture::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not implement verify\(\)/');

        (new NexusHandlerPass())->process($container);
    }

    public function testATagWithoutItsContractIsRefused(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/contract/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAHandlerOnABackendThatCannotRouteIsRefusedAtStartup(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingServedFixture::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot route Nexus operations/');
        $this->expectExceptionMessageMatches('/app\.billing/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAContainerWithNoHandlerAtAllIsLeftAlone(): void
    {
        $container = new ContainerBuilder();

        (new NexusHandlerPass())->process($container);

        self::assertFalse($container->hasDefinition('durable.temporal.nexus_registry'));
    }

    private function containerWithRegistry(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('durable.temporal.nexus_registry', NexusOperationRegistry::class);

        return $container;
    }
}

#[AsNexusService('billing')]
interface BillingServedFixture
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): string;
}

#[AsNexusService('billing')]
interface BillingContractFixture extends BillingServedFixture
{
    #[AsNexusOperation('charge')]
    public function charge(string $order, int $amount): string;
}

final class BillingFixture implements BillingServedFixture
{
    public function verify(string $order): string
    {
        return 'ok';
    }
}

final class NotAHandlerFixture {}

#[FulfilsNexusOperation(BillingContractFixture::class, 'charge')]
final class ChargeWorkflowFixture {}
