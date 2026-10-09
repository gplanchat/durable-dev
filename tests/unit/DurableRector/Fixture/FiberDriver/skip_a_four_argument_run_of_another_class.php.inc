<?php

namespace unit\DurableRector\Fixture\FiberDriver;

final class AnotherDriver
{
    public function run(string $a, string $b, string $c, string $d): string
    {
        return $a . $b . $c . $d;
    }
}

final class CallsAnotherDriver
{
    public function __invoke(AnotherDriver $driver): string
    {
        return $driver->run('exec-1', 'b', 'c', 'd');
    }
}
