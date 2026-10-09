<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;

final class PaymentDeclined extends ApplicationFailure
{
}

class OrderTimedOut extends \Temporal\Exception\Failure\TimeoutFailure
{
}

class PlainFailure extends \RuntimeException
{
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;

// durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
final class PaymentDeclined extends ApplicationFailure
{
}

// durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
class OrderTimedOut extends \Temporal\Exception\Failure\TimeoutFailure
{
}

class PlainFailure extends \RuntimeException
{
}

?>
