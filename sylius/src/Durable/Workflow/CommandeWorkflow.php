<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * La boutique fait facturer une commande par le métier.
 *
 * Les deux formes, dans le même workflow et avec le même stub. `verifier` revient tout de suite,
 * répondue par une méthode que le métier a écrite ; `encaisser` est remplie par un workflow d'en
 * face, qui prend une quinzaine de secondes. **Rien ici ne distingue les deux.** C'est le seul
 * point que cette classe existe pour montrer : l'appelant écrit deux appels, en attend deux
 * résultats, et ignore lequel a coûté douze secondes à quelqu'un d'autre.
 *
 * Pendant l'attente, ce workflow ne tient rien d'ouvert — ni connexion, ni processus, ni
 * transaction. Il n'est pas en mémoire : le worker qui le reprendra n'est peut-être pas celui qui
 * l'a démarré.
 */
#[AsWorkflow(self::TYPE)]
final class CommandeWorkflow
{
    public const TYPE = 'CommandeWorkflow';

    /** L'endpoint du métier, créé par `bin/demo-nexus`. */
    public const ENDPOINT = 'demo-metier-facturation';

    /** @var NexusStub<FacturationContract> */
    private readonly NexusStub $facturation;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->facturation = $environment->nexusStub(FacturationContract::class, endpoint: self::ENDPOINT);
    }

    /**
     * @param int $montant en centimes
     *
     * @return array{verifiee: array{acceptee: bool, motif: string|null}, encaissement: array{recu: string, encaisse: int}|null}
     */
    #[AsWorkflowMethod]
    public function run(string $commande, int $montant, string $devise = 'EUR'): array
    {
        $verdict = $this->environment->await($this->facturation->verifier($commande, $montant, $devise));

        if (true !== ($verdict['acceptee'] ?? false)) {
            // Refusée : rien à encaisser, et rien à compenser non plus.
            return ['verifiee' => $verdict, 'encaissement' => null];
        }

        return [
            'verifiee' => $verdict,
            'encaissement' => $this->environment->await($this->facturation->encaisser($commande, $montant, $devise)),
        ];
    }
}
