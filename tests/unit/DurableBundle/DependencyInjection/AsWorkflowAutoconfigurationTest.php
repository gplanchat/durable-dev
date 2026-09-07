<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use unit\DurableBundle\Fixtures\PasUnWorkflow;
use unit\DurableBundle\Fixtures\WorkflowAvecEnvironnement;
use unit\DurableBundle\Fixtures\WorkflowSansRien;

/**
 * Trois attributs sur quatre s'autoconfigurent. Le quatrième — celui qui déclare un workflow —
 * demandait une balise écrite à la main, par répertoire, dans le `services.yaml` de l'application.
 *
 * L'issue #255 annonce un piège : un workflow est instancié par réflexion par
 * `WorkflowDefinitionLoader`, jamais par le conteneur, et son constructeur reçoit un
 * `WorkflowEnvironment` qui n'est pas un service. Baliser sans plus ferait donc, dit-elle, échouer
 * la compilation sur une classe que le conteneur ne construira jamais.
 *
 * Ces cas l'éprouvent au lieu de le supposer : ils compilent réellement le conteneur.
 */
final class AsWorkflowAutoconfigurationTest extends TestCase
{
    public function testUnWorkflowAttributeEstEnregistreSansBaliseEcriteALaMain(): void
    {
        $container = $this->compileWith([WorkflowSansRien::class]);

        self::assertContains(
            WorkflowSansRien::class,
            $this->registeredClasses($container),
            "l'attribut doit suffire, comme il suffit déjà pour les trois autres",
        );
    }

    /**
     * Le cœur de la question. Si le piège de #255 mordait, cet appel lèverait à la compilation.
     */
    public function testUnWorkflowQuiRecoitLEnvironnementCompileQuandMeme(): void
    {
        $container = $this->compileWith([WorkflowAvecEnvironnement::class]);

        self::assertContains(WorkflowAvecEnvironnement::class, $this->registeredClasses($container));
    }

    public function testUneClasseSansAttributNeRejointPasLeRegistre(): void
    {
        $container = $this->compileWith([PasUnWorkflow::class]);

        self::assertNotContains(PasUnWorkflow::class, $this->registeredClasses($container));
    }

    /**
     * @param list<class-string> $classes
     */
    private function compileWith(array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([[]], $container);

        // Ce que FrameworkExtension pose et que ce conteneur synthétique n'a pas : le bus par
        // défaut, référencé par le dispatcher de reprise.
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);

        foreach ($classes as $class) {
            $container->register($class, $class)
                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->setPublic(false)
            ;
        }

        (new DurableBundle())->build($container);
        $container->compile();

        return $container;
    }

    /**
     * @return list<string>
     */
    private function registeredClasses(ContainerBuilder $container): array
    {
        $registered = [];
        foreach ($container->getDefinition(WorkflowRegistry::class)->getMethodCalls() as [$method, $arguments]) {
            if ('registerClass' === $method) {
                $registered[] = (string) $arguments[0];
            }
        }

        return $registered;
    }
}
