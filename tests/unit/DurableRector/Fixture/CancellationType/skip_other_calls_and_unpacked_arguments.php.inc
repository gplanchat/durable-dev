<?php

namespace unit\DurableRector\Fixture\CancellationType;

use Gplanchat\Durable\Activity\ActivityOptions;

final class Untouched
{
    public function options(array $arguments, object $other): array
    {
        return [
            // Another class with a parameter of the same name.
            new \ArrayObject(cancellationType: 1),
            $other->of(cancellationType: 1),
            // Unpacked: the position of the argument is unknown.
            ActivityOptions::of(...$arguments),
            // Fewer arguments than the position of the removed one.
            ActivityOptions::of(3, 30),
            new ActivityOptions(),
        ];
    }
}

?>
