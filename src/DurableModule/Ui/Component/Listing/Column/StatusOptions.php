<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\Component\Listing\Column;

use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Les états qu'une exécution peut avoir, tels que le cœur les définit.
 *
 * La liste n'est pas recopiée : elle est dérivée de l'énumération. Un état ajouté au composant
 * apparaît dans le filtre sans que personne y pense, et un état retiré en disparaît — ce qui est
 * exactement ce qu'on veut d'un filtre, qui ment dès qu'il propose un choix qui n'existe plus.
 */
final class StatusOptions implements OptionSourceInterface
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn(WorkflowRunStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst(str_replace('_', ' ', $status->value)),
            ],
            WorkflowRunStatus::cases(),
        );
    }
}
