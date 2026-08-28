<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Fixture analysée par {@see \unit\DurablePhpstan\StubMethodsExtensionTest}, jamais exécutée.
 *
 * Elle contient délibérément des appels corrects **et** fautifs : c'est ce que l'extension doit
 * distinguer, et c'est ce qu'un test qui se contenterait de vérifier « aucune erreur » ne
 * prouverait pas.
 */
interface OrderActivities
{
    #[ActivityMethod('charge')]
    public function charge(string $orderId, int $amount): string;

    /** Sans attribut : du code de contrat, pas une opération planifiable. */
    public function helper(): string;
}

#[AsNexusService('facturation')]
interface FacturationServed
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface Facturation extends FacturationServed
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre, int $montant): string;

    /** Sans attribut : du code de contrat, pas une opération appelable. */
    public function bareme(): string;
}

#[Workflow(name: 'child')]
final class ChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[WorkflowMethod]
    public function run(string $text): string
    {
        return $text;
    }
}

#[Workflow(name: 'call-sites')]
final class StubCallSites
{
    private readonly ActivityStub $orders;

    private readonly ChildWorkflowStub $child;

    private readonly NexusStub $facturation;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(OrderActivities::class);
        $this->child = $environment->childWorkflowStub(ChildWorkflow::class);
        $this->facturation = $environment->nexusStub(Facturation::class, 'paiements');
    }

    #[WorkflowMethod]
    public function run(string $orderId): mixed
    {
        // Correct : déclaré par le contrat et marqué.
        $this->environment->await($this->orders->charge($orderId, 100));

        // Correct : la méthode d'entrée de l'enfant.
        $this->environment->await($this->child->run('bonjour'));

        // FAUTIF — faute de frappe. C'est le cas qui motive l'extension : sans elle, aucune
        // erreur d'analyse, et un BadMethodCallException à l'exécution.
        $this->environment->await($this->orders->chrage($orderId, 100));

        // FAUTIF — déclarée par le contrat, mais sans #[ActivityMethod] : ce n'est pas une
        // activité, et le stub la refuse.
        $this->environment->await($this->orders->helper());

        // Correct : déclarée par le contrat Nexus et marquée.
        $this->environment->await($this->facturation->encaisser($orderId, 1200));

        // Correct : **héritée** du contrat servi. C'est la séparation en deux interfaces qui rend
        // ce cas possible, et l'extension doit la suivre comme le résolveur la suit.
        $this->environment->await($this->facturation->verifier($orderId));

        // FAUTIF — déclarée par le contrat Nexus, mais sans #[AsNexusOperation].
        $this->environment->await($this->facturation->bareme());

        // FAUTIF — faute de frappe sur une opération Nexus.
        $this->environment->await($this->facturation->encasser($orderId, 1200));

        // FAUTIF — mauvais nombre d'arguments. Ne devient visible que parce que l'extension a
        // rendu la méthode connue : c'est le gain de second ordre.
        return $this->environment->await($this->orders->charge($orderId));
    }
}
