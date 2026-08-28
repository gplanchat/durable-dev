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
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\XmlDumper;

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
        self::assertSame('billing', self::nom($calls[0][1][0], NexusService::class));
        self::assertSame('verify', self::nom($calls[0][1][1], NexusOperationName::class));
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
            ->addTag('durable.workflow')
            // La balise que `DurableBundle::build()` pose depuis #[FulfilsNexusOperation]. Le test
            // la pose à la main parce qu'il n'y a pas d'autoconfiguration sur un ContainerBuilder nu.
            ->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                'contract' => BillingContractFixture::class,
                'operation' => 'charge',
            ]);

        (new NexusHandlerPass())->process($container);

        $calls = $container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls();
        $methods = array_column($calls, 0);

        self::assertContains('register', $methods, 'l’opération implémentée s’enregistre normalement');
        self::assertContains('registerFulfilment', $methods, 'la différée se déclare, pour que le worker sache quel workflow démarrer');

        $fulfilment = $calls[array_search('registerFulfilment', $methods, true)];
        self::assertSame('charge', self::nom($fulfilment[1][1], NexusOperationName::class));
        // Le **type** de workflow, pas le FQCN : c'est ce nom que le serveur connaît.
        self::assertSame('ChargeWorkflowFixture', $fulfilment[1][2]);
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

    /**
     * Le trou que les autres tests laissaient : ils vérifient que l'appel est **ajouté** à la
     * définition, jamais qu'il s'exécute. Entre les deux vivaient deux `TypeError` — la charge
     * entière passée en argument #1, et un retour ordinaire là où `dispatch()` attend un
     * {@see NexusOperationResponse}. Le conteneur est donc compilé, et l'opération vraiment
     * appelée.
     */
    public function testTheRegisteredHandlerIsActuallyCallableWithANexusPayload(): void
    {
        $container = $this->containerWithRegistry();
        $container->getDefinition('durable.temporal.nexus_registry')
            ->setFactory([NexusOperationRegistry::class, 'routedBy'])
            ->setArguments(['temporal'])
            ->setPublic(true);
        $container->register('app.billing', BillingFixture::class)
            ->setPublic(true)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingServedFixture::class]);

        $container->addCompilerPass(new NexusHandlerPass());
        $container->compile();

        /** @var NexusOperationRegistry $registry */
        $registry = $container->get('durable.temporal.nexus_registry');
        $response = $registry->dispatch(
            NexusService::named('billing'),
            NexusOperationName::named('verify'),
            ['order' => 'CMD-1'],
        );

        // La charge est clée par nom de paramètre — c'est ce que `NexusStub` écrit —, et le
        // gestionnaire rend le type que son contrat déclare. L'emballage est l'affaire de la
        // plomberie, pas de celui qui écrit le gestionnaire.
        self::assertTrue($response->isImmediate);
        self::assertSame('ok:CMD-1', $response->result);
    }

    /**
     * Le mode d'échec : un conteneur qui compile et une application qui ne démarre pas.
     *
     * En mode dev, Symfony réécrit le conteneur en XML à chaque réchauffage. Un objet-valeur passé
     * tel quel en argument d'appel de méthode n'est pas sérialisable, et le message qui sort
     * — « Unable to dump a service container if a parameter is an object or a resource » — ne parle
     * ni de Nexus, ni de la passe qui l'a posé. Ce test est la seule chose qui l'attrape avant que
     * quelqu'un ne vide son cache.
     */
    public function testTheContainerItLeavesBehindIsStillDumpable(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingContractFixture::class]);
        $container->register('app.charge', ChargeWorkflowFixture::class)
            ->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                'contract' => BillingContractFixture::class,
                'operation' => 'charge',
            ]);

        (new NexusHandlerPass())->process($container);

        $xml = (new XmlDumper($container))->dump();
        self::assertStringContainsString('billing', $xml);
    }

    private static function nom(mixed $argument, string $classeAttendue): string
    {
        self::assertInstanceOf(Definition::class, $argument, 'un objet-valeur voyage en définition, pas en instance');
        self::assertSame($classeAttendue, $argument->getClass());

        return $argument->getArgument(0);
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
        return 'ok:' . $order;
    }
}

final class NotAHandlerFixture {}

#[FulfilsNexusOperation(BillingContractFixture::class, 'charge')]
final class ChargeWorkflowFixture {}
