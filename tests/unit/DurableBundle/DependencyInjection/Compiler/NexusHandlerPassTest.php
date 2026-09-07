<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
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
        self::assertSame('billing', self::nameOf($calls[0][1][0], NexusService::class));
        self::assertSame('verify', self::nameOf($calls[0][1][1], NexusOperationName::class));
    }

    public function testAnOperationNobodyCoversIsRefusedAtStartup(): void
    {
        // The core of this compiler pass. `charge` is declared by the caller's contract, no
        // handler implements it and no workflow claims it: it would be served by nobody, and the
        // caller would wait for a result that nothing will produce. Since there is no request to
        // fail later on, the refusal happens at startup or nowhere.
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
            // The tag that `DurableBundle::build()` lays down from #[FulfilsNexusOperation]. The
            // test lays it by hand because a bare ContainerBuilder has no autoconfiguration.
            ->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                'contract' => BillingContractFixture::class,
                'operation' => 'charge',
            ]);

        (new NexusHandlerPass())->process($container);

        $calls = $container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls();
        $methods = array_column($calls, 0);

        self::assertContains('register', $methods, 'the implemented operation registers as usual');
        self::assertContains('registerFulfilment', $methods, 'the deferred one declares itself, so the worker knows which workflow to start');

        $fulfilment = $calls[array_search('registerFulfilment', $methods, true)];
        self::assertSame('charge', self::nameOf($fulfilment[1][1], NexusOperationName::class));
        // The workflow **type**, not the FQCN: that is the name the server knows.
        self::assertSame('ChargeWorkflowFixture', $fulfilment[1][2]);
    }

    public function testAHandlerThatServesNothingIsRefused(): void
    {
        // A class that implements no operation of the contract is caught by the coverage check,
        // operation by operation. There is no `is_a` check: the tag may name the complete
        // contract, of which the handler implements only the served part.
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
     * The hole the other tests left: they check that the call is **added** to the definition,
     * never that it runs. Two `TypeError`s lived in between — the whole payload passed as
     * argument #1, and an ordinary return where `dispatch()` expects a
     * {@see NexusOperationResponse}. So the container is compiled, and the operation really
     * called.
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

        // The payload is keyed by parameter name — that is what `NexusStub` writes — and the
        // handler returns the type its contract declares. The wrapping is the plumbing's business,
        // not that of whoever writes the handler.
        self::assertTrue($response->isImmediate);
        self::assertSame('ok:CMD-1', $response->result);
    }

    /**
     * Nexus's most silent failure mode, and the only one nothing was catching.
     *
     * The payload is keyed by name when written and read back by name on arrival. A workflow
     * parameter that matches no parameter of the contract is not an error — it receives `null`.
     * The workflow starts, runs, and returns a result computed on nothing.
     */
    public function testAWorkflowWhoseParameterNamesDoNotMatchTheContractIsRefused(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingContractFixture::class]);
        $container->register('app.charge', MistypedChargeWorkflowFixture::class)
            ->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                'contract' => BillingContractFixture::class,
                'operation' => 'charge',
            ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/\$ammount/');
        $this->expectExceptionMessageMatches('/silently receive null/');

        (new NexusHandlerPass())->process($container);
    }

    /**
     * A parameter the contract knows nothing about but that carries a default value passes: its
     * absence is then a decision, not an oversight.
     */
    public function testAnOptionalExtraParameterIsAllowed(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.billing', BillingFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['contract' => BillingContractFixture::class]);
        $container->register('app.charge', ChargeWithAnOptionFixture::class)
            ->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                'contract' => BillingContractFixture::class,
                'operation' => 'charge',
            ]);

        (new NexusHandlerPass())->process($container);

        $methods = array_column($container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls(), 0);
        self::assertContains('registerFulfilment', $methods);
    }

    /**
     * The failure mode: a container that compiles and an application that does not start.
     *
     * In dev mode, Symfony rewrites the container as XML on every cache warm-up. A value object
     * passed as is as a method call argument is not serializable, and the message that comes out
     * — "Unable to dump a service container if a parameter is an object or a resource" — speaks
     * neither of Nexus, nor of the compiler pass that laid it down. This test is the only thing
     * that catches it before someone clears their cache.
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

    private static function nameOf(mixed $argument, string $expectedClass): string
    {
        self::assertInstanceOf(Definition::class, $argument, 'a value object travels as a definition, not as an instance');
        self::assertSame($expectedClass, $argument->getClass());

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

#[AsWorkflow('ChargeWorkflowFixture')]
#[FulfilsNexusOperation(BillingContractFixture::class, 'charge')]
final class ChargeWorkflowFixture
{
    #[AsWorkflowMethod]
    public function run(string $order, int $amount): string
    {
        return $order . ':' . $amount;
    }
}

#[AsWorkflow('ChargeWithAnOptionFixture')]
#[FulfilsNexusOperation(BillingContractFixture::class, 'charge')]
final class ChargeWithAnOptionFixture
{
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, bool $trace = false): string
    {
        return $order . ':' . $amount . ':' . ($trace ? '1' : '0');
    }
}

#[AsWorkflow('MistypedChargeWorkflowFixture')]
#[FulfilsNexusOperation(BillingContractFixture::class, 'charge')]
final class MistypedChargeWorkflowFixture
{
    // The contract's `$amount`, written `$ammount` here. Nothing reports it without the guard:
    // the workflow starts and charges zero.
    #[AsWorkflowMethod]
    public function run(string $order, int $ammount): string
    {
        return $order . ':' . $ammount;
    }
}
