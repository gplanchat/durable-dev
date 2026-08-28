<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\NexusHandlerPass;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NexusHandlerPass::class)]
final class NexusHandlerPassTest extends TestCase
{
    public function testADeclaredHandlerIsRegisteredOnTheRegistry(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.greeting', GreetingHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['service' => 'probe', 'operation' => 'greet']);

        (new NexusHandlerPass())->process($container);

        $calls = $container->getDefinition('durable.temporal.nexus_registry')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('register', $calls[0][0]);
        self::assertEquals(NexusService::named('probe'), $calls[0][1][0]);
        self::assertEquals(NexusOperationName::named('greet'), $calls[0][1][1]);
    }

    public function testAHandlerOnABackendThatCannotRouteIsRefusedAtStartup(): void
    {
        // §5.3, et c'est la raison d'être de cette passe. Côté appelant, un appel sur un backend
        // sans route échoue à l'appel. Servir n'a pas d'appel à faire échouer : un gestionnaire
        // déclaré sans route est un service qui ne reçoit jamais rien, en silence. Alors on
        // refuse au montage du conteneur, en nommant ce qui manque.
        $container = new ContainerBuilder();
        $container->register('app.greeting', GreetingHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['service' => 'probe', 'operation' => 'greet']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot route Nexus operations/');
        $this->expectExceptionMessageMatches('/durable\.temporal\.dsn/');
        $this->expectExceptionMessageMatches('/app\.greeting/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAContainerWithNoHandlerAtAllIsLeftAlone(): void
    {
        // Sans gestionnaire déclaré, la passe n'a rien à dire — y compris sur un backend sans
        // route. Refuser ici casserait toute application qui n'utilise pas Nexus.
        $container = new ContainerBuilder();

        (new NexusHandlerPass())->process($container);

        self::assertFalse($container->hasDefinition('durable.temporal.nexus_registry'));
    }

    public function testATagMissingItsNamesIsRefused(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.greeting', GreetingHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['service' => 'probe']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must declare both "service" and "operation"/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAHandlerMissingItsMethodIsRefused(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('app.greeting', GreetingHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['service' => 'probe', 'operation' => 'greet', 'method' => 'inexistante']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must implement inexistante\(\)/');

        (new NexusHandlerPass())->process($container);
    }

    public function testAnInvalidServiceNameIsRefusedAtStartupToo(): void
    {
        // Une faute dans un nom donne un gestionnaire que rien n'atteint jamais. Le serveur ne
        // dira rien : il n'y a pas d'erreur à lever plus tard.
        $container = $this->containerWithRegistry();
        $container->register('app.greeting', GreetingHandlerFixture::class)
            ->addTag(NexusHandlerPass::TAG, ['service' => '   ', 'operation' => 'greet']);

        $this->expectException(\LogicException::class);

        (new NexusHandlerPass())->process($container);
    }

    private function containerWithRegistry(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('durable.temporal.nexus_registry', NexusOperationRegistry::class);

        return $container;
    }
}

final class GreetingHandlerFixture
{
    public function __invoke(mixed $payload): NexusOperationResponse
    {
        return NexusOperationResponse::completed(['greeting' => 'hello']);
    }
}
