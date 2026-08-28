<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Ce qu'une exécution a fait, lu dans son journal.
 *
 * Rien de spécifique à Magento ici non plus : `readHistory()` est le même port que le tableau de
 * bord Sylius interroge, et il rend les mêmes `WorkflowRunEvent`. Une observation, une surface par
 * hôte.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessDetail extends Template
{
    private ?WorkflowRunDescription $run = null;

    private bool $looked = false;

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
     * L'exécution demandée, ou `null` si la grappe ne la connaît pas — un identifiant collé à la
     * main dans la barre d'adresse, ou une exécution que la rétention a effacée.
     */
    public function getRun(): ?WorkflowRunDescription
    {
        if (!$this->looked) {
            $this->looked = true;
            $wanted = $this->getRunId();

            foreach ($this->runtimeFactory->catalog()->listRuns(limit: 200)->runs as $candidate) {
                if ($candidate->runId === $wanted) {
                    $this->run = $candidate;

                    break;
                }
            }
        }

        return $this->run;
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function getEvents(): array
    {
        $run = $this->getRun();

        return $run === null ? [] : $this->runtimeFactory->catalog()->readHistory($run);
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
