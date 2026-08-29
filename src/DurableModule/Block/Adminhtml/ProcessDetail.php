<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Ce qu'une exécution a fait, lu dans son journal.
 *
 * Rien de spécifique à Magento ici : `readHistory()` est le même port que le tableau de bord Sylius
 * interroge, et {@see RunTimeline} est la même projection. Une observation, une surface par hôte.
 *
 * Ce bloc en dérivait autrefois la sienne — regroupement, découpe en segments, mise à l'échelle,
 * infobulles, mise en forme de la charge utile, nom d'action par ligne. Tout cela vit désormais
 * dans le cœur, et pour une raison qui se mesurait sur l'écran : Sylius empilait des blocs sans
 * position, ne distinguait pas la file du travail, et rendait un dépliant vide sur une charge
 * mal encodée. Deux moitiés du même modèle, sur le même journal.
 *
 * Ce qui reste ici est ce qui appartient à l'hôte : la **mise à l'échelle** — elle demande de
 * connaître la largeur d'une colonne, et la projection ne rend que des secondes.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessDetail extends Template
{
    private ?WorkflowRunDescription $run = null;

    private bool $looked = false;

    private ?RunTimeline $timeline = null;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getRunId(): string
    {
        return (string) $this->getRequest()->getParam('run_id');
    }

    /**
     * L'exécution demandée, ou `null` si le backend ne la connaît pas — un identifiant collé à la
     * main dans la barre d'adresse, ou une exécution que la rétention a effacée.
     *
     * ⚠ La fenêtre est **celle de la grille**, littéralement la même constante : deux fenêtres de
     * tailles différentes rendaient possible d'être listé ici et introuvable là.
     */
    public function getRun(): ?WorkflowRunDescription
    {
        if (!$this->looked) {
            $this->looked = true;
            $wanted = $this->getRunId();

            foreach ($this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs as $candidate) {
                if ($candidate->runId === $wanted) {
                    $this->run = $candidate;

                    break;
                }
            }
        }

        return $this->run;
    }

    /**
     * La frise et le journal, projetés une fois : le gabarit lit les deux, et sans mémoire ce
     * serait un second aller-retour vers le backend pour la même réponse.
     */
    public function getTimeline(): RunTimeline
    {
        if ($this->timeline === null) {
            $run = $this->getRun();
            $this->timeline = RunTimeline::of(
                $run === null ? [] : $this->runtimeFactory->catalog()->readHistory($run),
            );
        }

        return $this->timeline;
    }

    /**
     * Des secondes vers un pourcentage de la piste.
     *
     * C'est la seule chose que l'hôte décide de la frise, et c'est normal qu'il la décide : mettre
     * à l'échelle demande de connaître la largeur d'une colonne, ce qu'une projection partagée avec
     * une surface sans balisage ne peut pas savoir.
     *
     * Sans durée — une seule action, ou tout dans la même microseconde — tout se pose à gauche.
     * Étaler par rang ferait passer un ordre pour une durée.
     */
    public function scale(float $seconds): string
    {
        $span = $this->getTimeline()->span;

        return number_format($span > 0.0 ? $seconds / $span * 100.0 : 0.0, 3, '.', '');
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('durable/process/history');
    }
}
