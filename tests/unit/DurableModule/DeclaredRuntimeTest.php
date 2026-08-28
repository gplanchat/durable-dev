<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Magento;

use Gplanchat\Durable\Magento\Runtime\RuntimeFactory;
use Gplanchat\Durable\Magento\Runtime\UndeclaredWorkflowException;
use Gplanchat\Durable\Magento\Workflow\Activity\DemoOrderActivities;
use Gplanchat\Durable\Magento\Workflow\PlaceOrderWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * La déclaration, puisque le conteneur de Magento n'a pas les tags de Symfony.
 *
 * Ce qui se prouve ici sans Magento : qu'une classe **déclarée** tourne, que ses activités sont
 * résolues depuis `#[AsActivityMethod]` et non depuis des chaînes recopiées à la main, et qu'une
 * classe **non déclarée** échoue en le disant. La fabrique est du PHP ordinaire — c'est ce qui
 * permet à la CI de garder ce mécanisme, là où le reste du module demande un banc.
 */
final class DeclaredRuntimeTest extends TestCase
{
    public function testADeclaredWorkflowRunsWithActivitiesResolvedFromTheirAttribute(): void
    {
        $runtime = (new RuntimeFactory(
            workflowClasses: [PlaceOrderWorkflow::class],
            activityHandlers: [new DemoOrderActivities()],
        ))->create();

        self::assertSame('notify:charge:ORD-4242', $runtime->run(PlaceOrderWorkflow::class, ['orderId' => 'ORD-4242']));
    }

    /**
     * Les noms viennent du contrat, pas de la commande de démonstration : c'est la seule chose qui
     * distingue un mécanisme de déclaration d'une liste recopiée à côté.
     */
    public function testTheDeclaredActivityNamesAreTheOnesTheContractCarries(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new DemoOrderActivities()]))->create();

        self::assertSame(
            ['durable.demo.charge', 'durable.demo.reserve', 'durable.demo.notify'],
            $runtime->declaredActivities(),
        );
    }

    /**
     * Sans ce refus, la déclaration ne déclare rien : n'importe quelle classe tournerait, qu'elle
     * soit dans `di.xml` ou non, et l'oubli ne se verrait qu'en production.
     */
    public function testAnUndeclaredWorkflowFailsNamingTheTypeAndWhereTypesAreDeclared(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new DemoOrderActivities()]))->create();

        $this->expectException(UndeclaredWorkflowException::class);
        $this->expectExceptionMessageMatches('/PlaceOrderWorkflow/');
        $this->expectExceptionMessageMatches('/di\.xml/');

        $runtime->run(PlaceOrderWorkflow::class, ['orderId' => 'ORD-4242']);
    }
}
