<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\Component\Listing\Column;

use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The states an execution can be in, as the core defines them.
 *
 * The list is not copied out: it is derived from the enum. A state added to the component appears
 * in the filter without anyone thinking about it, and a state removed disappears from it — which
 * is exactly what one wants of a filter, which lies the moment it offers a choice that is gone.
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
