<?php

namespace unit\DurableRector\Fixture\CancellationType;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\Activities;

final class NamedArguments
{
    public function options(): array
    {
        return [
            new ActivityOptions(
                backoffCoefficient: 1.5,
                cancellationType: ActivityCancellationType::Abandon,
                summary: 'Charge',
            ),
            ActivityOptions::of(3, 30, cancellationType: ActivityCancellationType::TryCancel),
            ActivityOptions::of(cancellationType: ActivityCancellationType::TryCancel),
            \Gplanchat\Durable\Activity\ActivityOptions::of(attempts: 3, cancellationType: ActivityCancellationType::WaitCancellationCompleted, summary: 'x'),
        ];
    }
}

#[Activities(\stdClass::class, attempts: 3, cancellationType: ActivityCancellationType::Abandon, summary: 'Charge')]
final class Annotated {}

?>
-----
<?php

namespace unit\DurableRector\Fixture\CancellationType;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\Activities;

final class NamedArguments
{
    public function options(): array
    {
        return [
            new ActivityOptions(
                backoffCoefficient: 1.5,
                summary: 'Charge',
            ),
            ActivityOptions::of(3, 30),
            ActivityOptions::of(),
            \Gplanchat\Durable\Activity\ActivityOptions::of(attempts: 3, summary: 'x'),
        ];
    }
}

#[Activities(\stdClass::class, attempts: 3, summary: 'Charge')]
final class Annotated {}

?>
