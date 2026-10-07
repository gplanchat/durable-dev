<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\ServerFailure;
use Temporal\Exception\Failure\TerminatedFailure;
use Temporal\Exception\Failure\TimeoutFailure;

final class AnonymousFailures
{
    public function throws(): void
    {
        throw new class('x') extends ApplicationFailure {};
        throw new class('x') extends ServerFailure {};
        throw new class('x') extends TerminatedFailure {};
        throw new class('x') extends TimeoutFailure {};
    }

    public function assigns(): void
    {
        $failure = new class('x') extends ApplicationFailure {};
        $failure = new class('x') extends ServerFailure {};
        $failure = new class('x') extends TerminatedFailure {};
        $failure = new class('x') extends TimeoutFailure {};
    }

    public function returns(): object
    {
        return new class('x') extends ApplicationFailure {};
        return new class('x') extends ServerFailure {};
        return new class('x') extends TerminatedFailure {};
        return new class('x') extends TimeoutFailure {};
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\ServerFailure;
use Temporal\Exception\Failure\TerminatedFailure;
use Temporal\Exception\Failure\TimeoutFailure;

final class AnonymousFailures
{
    public function throws(): void
    {
        // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        throw new class('x') extends ApplicationFailure {};
        // durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        throw new class('x') extends ServerFailure {};
        // durable-rector: TerminatedFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        throw new class('x') extends TerminatedFailure {};
        // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        throw new class('x') extends TimeoutFailure {};
    }

    public function assigns(): void
    {
        // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        $failure = new class('x') extends ApplicationFailure {};
        // durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        $failure = new class('x') extends ServerFailure {};
        // durable-rector: TerminatedFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        $failure = new class('x') extends TerminatedFailure {};
        // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        $failure = new class('x') extends TimeoutFailure {};
    }

    public function returns(): object
    {
        // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        return new class('x') extends ApplicationFailure {};
        // durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        return new class('x') extends ServerFailure {};
        // durable-rector: TerminatedFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        return new class('x') extends TerminatedFailure {};
        // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        return new class('x') extends TimeoutFailure {};
    }
}

?>
