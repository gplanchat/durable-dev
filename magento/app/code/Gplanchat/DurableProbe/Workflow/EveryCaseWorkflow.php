<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\DurableProbe\Workflow\Activity\EveryCaseActivities;

/**
 * Une exécution qui contient **tous les cas que l'écran d'observation doit savoir montrer**.
 *
 * Le banc n'avait qu'un chemin heureux : trois activités qui réussissent, aucun minuteur, aucun
 * enfant, aucun échec. Un écran qui n'a jamais vu d'échec n'a jamais prouvé qu'il savait en
 * montrer un, et une frise validée sur une seule forme d'action ne prouve rien de la suivante.
 * Celui-ci est le véhicule de recette : ce qu'il produit est ce que la page doit rendre lisible.
 *
 * Ce qu'il contient, et pourquoi :
 * - une activité qui **réussit** — la ligne de référence ;
 * - une activité **instable**, deux échecs puis une réussite : une action qui porte du rouge *et*
 *   se termine bien, ce qui prouve que la couleur marque l'événement et non l'action entière ;
 *   ⚠ **elle ne se reprend que sur le backend en mémoire** — sur Temporal les trois tentatives
 *   sont consommées en deux secondes sans que le code de l'activité soit rappelé. C'est cette
 *   sonde qui l'a trouvé, et le fait est rapporté dans l'issue #218 : ici il n'est pas contourné ;
 * - un **minuteur** de cinq secondes, qui doit annoncer sa durée sans qu'on ait à soustraire deux
 *   horodatages ;
 * - un **workflow enfant** qui réussit et un autre qui échoue, sur des lignes séparées ;
 * - une activité **condamnée**, dont l'échec est définitif et rattrapé ici : l'exécution se
 *   termine, et l'échec reste visible dans son journal.
 *
 * ⚠ **Deux cas manquent, et c'est délibéré.** Un *signal* demande un émetteur, et le runtime de
 * l'hôte n'expose pas d'envoi — la sonde tourne sans surveillance, donc rien ne le lui enverrait.
 * Une opération *Nexus* demande **deux applications** ; elle appartient à
 * `change/demo-nexus-deux-applications`, et l'écrire ici en ferait une deuxième source de vérité.
 */
final class EveryCaseWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @return array<string, string>
     */
    #[AsWorkflowMethod]
    public function run(string $caseId = 'CASE-1'): array
    {
        $steady = $this->environment->activityStub(EveryCaseActivities::class);
        // Trois tentatives, une seconde entre chacune : assez pour que l'attente entre deux
        // tentatives soit un intervalle visible, assez court pour ne pas faire attendre l'exploitant.
        $patient = $this->environment->activityStub(
            EveryCaseActivities::class,
            ActivityOptions::of(retryLimit: RetryLimit::ofAttempts(3), initialInterval: 1.0, backoffCoefficient: 1.0),
        );
        // Une seule tentative : l'échec est définitif, et il l'est tout de suite.
        $hopeless = $this->environment->activityStub(
            EveryCaseActivities::class,
            ActivityOptions::of(retryLimit: RetryLimit::once()),
        );

        $trace = [];
        $trace['succeed'] = $this->environment->await($steady->succeed($caseId));

        $this->environment->sleep(Duration::seconds(5), 'cooling down before retry');
        $trace['timer'] = 'slept 5 s';

        // Rattrapée elle aussi, et pas par principe : sur le backend en mémoire elle se reprend et
        // rend « recovered after 3 attempts », sur Temporal elle échoue. La laisser propager ferait
        // mourir l'exécution ici et priverait la page des quatre cas suivants — or c'est
        // précisément la page qu'on vient juger. La différence entre les deux backends est un fait
        // que cette sonde a trouvé, pas une raison de ne montrer qu'un backend.
        $trace['flaky'] = $this->caught(fn(): mixed => $this->environment->await($patient->flaky($caseId)));

        $trace['child'] = $this->environment->await(
            $this->environment->childWorkflowStub(EveryCaseChildWorkflow::class)->run($caseId . '-ok'),
        );

        // Rattrapés, tous les deux : ce que la page doit montrer est un échec **dans** une
        // exécution qui se termine. Une exécution qui meurt à la première erreur n'aurait qu'une
        // seule ligne rouge, la dernière, et n'apprendrait rien de la mise en page du reste.
        $trace['failing child'] = $this->caught(fn(): mixed => $this->environment->await(
            $this->environment->childWorkflowStub(EveryCaseChildWorkflow::class)->run($caseId . '-ko', true),
        ));

        $trace['doomed'] = $this->caught(fn(): mixed => $this->environment->await($hopeless->doomed($caseId)));

        return $trace;
    }

    private function caught(\Closure $attempt): string
    {
        try {
            return (string) $attempt();
        } catch (\Throwable $failure) {
            return 'caught: ' . $failure->getMessage();
        }
    }
}
