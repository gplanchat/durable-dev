<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le contrat du workflow de démonstration exhaustive : une activité par forme que l'écran
 * d'observation doit savoir montrer.
 *
 * Le banc n'avait que des activités qui réussissent. Un écran qui n'a jamais vu d'échec n'a jamais
 * prouvé qu'il savait en montrer un — et « la couleur d'échec marche » ne se vérifie pas sur une
 * exécution qui n'échoue nulle part.
 */
interface EveryCaseActivities
{
    /** Le cas nominal : elle réussit du premier coup. */
    #[AsActivityMethod(name: 'durable.case.succeed')]
    public function succeed(string $caseId): string;

    /**
     * Elle échoue les deux premières fois, puis réussit.
     *
     * C'est le seul moyen d'obtenir un `ACTIVITY_TASK_FAILED` **suivi d'une reprise** : une action
     * qui porte à la fois du rouge et une fin verte, et qui prouve que la couleur marque
     * l'événement et non l'action entière.
     */
    #[AsActivityMethod(name: 'durable.case.flaky')]
    public function flaky(string $caseId): string;

    /** Elle échoue toujours, et son échec est définitif. */
    #[AsActivityMethod(name: 'durable.case.doomed')]
    public function doomed(string $caseId): string;
}
