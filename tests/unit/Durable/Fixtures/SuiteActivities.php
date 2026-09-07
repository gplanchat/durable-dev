<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The activities the suite schedules, declared once.
 *
 * The suite named its activities by strings — `$env->activity('double', ['value' => 2])`, with no contract —
 * the form the library no longer teaches. These names are test props, not business contracts, but
 * they are workflow code all the same: the rule holds for them.
 *
 * **The parameter name is the payload key.** `ActivityStub` builds the payload from the parameters
 * declared here, so a renamed parameter changes what the double receives. It is the only trap in
 * this file, and it is a silent one: the payload turns wrong, the test does not go red.
 *
 * The name sent stays the one on the attribute, which lets `task-a` and `task-b` be written
 * `taskA()` and `taskB()` without changing a byte of what goes on the wire.
 */
interface SuiteActivities
{
    #[AsActivityMethod('never')]
    public function never(): mixed;

    #[AsActivityMethod('double')]
    public function double(int $value): int;

    #[AsActivityMethod('append')]
    public function append(string $text): string;

    #[AsActivityMethod('greet')]
    public function greet(string $name): string;

    #[AsActivityMethod('echo')]
    public function echoValue(mixed $v = null): mixed;

    /** @param list<string> $lines */
    #[AsActivityMethod('quote')]
    public function quote(array $lines): mixed;

    // Scheduling props: these names describe no business at all, they serve to make a branch win
    // or lose a race. So they take no argument.
    #[AsActivityMethod('fast')]
    public function fast(): mixed;

    #[AsActivityMethod('slow')]
    public function slow(): mixed;

    #[AsActivityMethod('work')]
    public function work(): mixed;

    /** Returns an empty value: `empty` is a language construct, not a method name. */
    #[AsActivityMethod('empty')]
    public function emptyResult(): mixed;

    /** Fails then succeeds: used by the retry tests. */
    #[AsActivityMethod('flaky')]
    public function flaky(): mixed;

    #[AsActivityMethod('charge')]
    public function charge(mixed $o = null): mixed;

    #[AsActivityMethod('refund')]
    public function refund(mixed $order = null): mixed;

    #[AsActivityMethod('doWork')]
    public function doWork(): mixed;

    // The hyphen is not a PHP method name; the attribute carries the name that is sent, so
    // nothing moves on the wire.
    #[AsActivityMethod('task-a')]
    public function taskA(): mixed;

    #[AsActivityMethod('task-b')]
    public function taskB(): mixed;

    #[AsActivityMethod('ping')]
    public function ping(): mixed;

    /** Always fails: used by the retry tests that have no way out. */
    #[AsActivityMethod('always')]
    public function always(): mixed;

    #[AsActivityMethod('square')]
    public function square(int $value): int;

    #[AsActivityMethod('add')]
    public function add(int $a, int $b): int;

    #[AsActivityMethod('task')]
    public function task(string $name): mixed;

    /** Returns what it is given: used to tell two branches of the same race apart. */
    #[AsActivityMethod('id')]
    public function id(mixed $v): mixed;

    #[AsActivityMethod('validate')]
    public function validate(string $data): mixed;

    #[AsActivityMethod('explode')]
    public function explodeNow(): never;

    /** One step of a sequence: called several times, it returns a different result. */
    #[AsActivityMethod('step')]
    public function step(): mixed;

    #[AsActivityMethod('compute')]
    public function compute(int $a, int $b): int;

    // Bare branches of a composition: `a`, `b`, `c` name nothing but their place in an assembly.
    // The contract does not make them any more expressive — it only makes them reachable without
    // naming a string.
    #[AsActivityMethod('a')]
    public function a(): mixed;

    #[AsActivityMethod('b')]
    public function b(): mixed;

    #[AsActivityMethod('c')]
    public function c(): mixed;

    #[AsActivityMethod('price')]
    public function price(int $n): mixed;

    #[AsActivityMethod('ok')]
    public function ok(mixed $n = null): mixed;

    /** Fails: used by the compositions where one branch has to fall. */
    #[AsActivityMethod('boom')]
    public function boom(): never;
}
