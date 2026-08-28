<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Runtime;

use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Le moteur, tel qu'un processus Magento le tient.
 *
 * Il ne fait rien que le composant ne fasse déjà : il tient les cinq objets
 * ensemble et donne à l'hôte les trois gestes dont il a besoin — déclarer une
 * activité, déclarer un workflow, lancer une exécution.
 *
 * Ce qui est **absent** est le sujet. Il n'y a pas d'autoconfiguration par
 * attribut : le conteneur de Magento n'a pas d'équivalent des tags de Symfony,
 * donc une classe se déclare. C'est le coût du palier 1, et le nommer ici évite
 * de le redécouvrir dans chaque classe qui s'en étonne.
 */
final class MagentoRuntime
{
    public function __construct(
        private readonly InMemoryEventStore $eventStore,
        private readonly RegistryActivityExecutor $activities,
        private readonly WorkflowRegistry $workflows,
        private readonly InMemoryWorkflowRunner $runner,
    ) {}

    /** @var list<string> */
    private array $declaredActivities = [];

    /**
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function registerActivity(string $activityName, callable $handler): void
    {
        $this->activities->register($activityName, $handler);
        $this->declaredActivities[] = $activityName;
    }

    /**
     * @param class-string $workflowClass
     */
    public function registerWorkflow(string $workflowClass): void
    {
        $this->workflows->registerClass($workflowClass);
    }

    /**
     * @param class-string          $workflowClass
     * @param array<string, mixed>  $input
     */
    public function run(string $workflowClass, array $input = [], ?string $executionId = null): mixed
    {
        if (!$this->workflows->has($workflowClass)) {
            throw UndeclaredWorkflowException::forClass($workflowClass);
        }

        return $this->runner->run(
            $executionId ?? 'magento-' . bin2hex(random_bytes(6)),
            $this->workflows->getHandler($workflowClass, $input),
        );
    }

    /**
     * Les noms d'activité que la déclaration a produits, dans l'ordre où les contrats les portent.
     *
     * C'est ce qui permet de dire, sans lire le code, que les noms viennent de `#[ActivityMethod]`
     * et non de chaînes recopiées à côté.
     *
     * @return list<string>
     */
    public function declaredActivities(): array
    {
        return $this->declaredActivities;
    }

    public function eventStore(): InMemoryEventStore
    {
        return $this->eventStore;
    }
}
