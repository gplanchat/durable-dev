<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Psr\Clock\ClockInterface;

/**
 * What reads the clock, or waits on the worker, in one call: the question the rules ask of every
 * call in a workflow class.
 *
 * Classes are recognised by type, never by name: `DateTimeInterface` covers `DateTime`,
 * `DateTimeImmutable`, Symfony's `DatePoint`, Carbon and the application's own subclasses, and
 * `Psr\Clock\ClockInterface` covers every clock object.
 */
final class ClockSources
{
    private const FUNCTIONS = ['time', 'microtime', 'hrtime', 'rand', 'mt_rand', 'random_int', 'uniqid', 'sleep', 'usleep', 'now', 'today'];
    private const NOW = ['now', 'today', 'tomorrow', 'yesterday'];

    /** A label for what the call reads, shown in the message; null when the call is deterministic. */
    public static function of(CallLike $call, Scope $scope): ?string
    {
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            $name = $call->name->toLowerString();

            // `date()` with an explicit timestamp is deterministic; without one it reads the clock.
            return \in_array($name, self::FUNCTIONS, true) || ('date' === $name && 1 === \count($call->args)) ? $name . '()' : null;
        }
        if ($call instanceof New_ && $call->class instanceof Name && self::isDateClass($scope->resolveTypeByName($call->class))) {
            return self::readsTheClock($scope, $call->args) ? 'new ' . $call->class->toString() . '()' : null;
        }
        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier
            && self::isDateClass($scope->resolveTypeByName($call->class))) {
            $method = $call->name->toLowerString();

            return \in_array($method, self::NOW, true) || ('parse' === $method && self::readsTheClock($scope, $call->args))
                ? $call->class->toString() . '::' . $call->name->toString() . '()' : null;
        }
        if ($call instanceof MethodCall && $call->name instanceof Identifier
            && \in_array($call->name->toLowerString(), ['now', 'sleep', 'usleep'], true)
            && (new ObjectType(ClockInterface::class))->isSuperTypeOf($scope->getType($call->var))->yes()) {
            return '->' . $call->name->toString() . '() on a clock';
        }

        return null;
    }

    private static function isDateClass(Type $type): bool
    {
        return (new ObjectType(\DateTimeInterface::class))->isSuperTypeOf($type)->yes();
    }

    /**
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     */
    private static function readsTheClock(Scope $scope, array $args): bool
    {
        $first = $args[0] ?? null;
        if (!$first instanceof Arg) {
            return true; // `new DateTime()`, `Carbon::parse()`: both mean "now"
        }
        // A variable or a computed string has no known value, so it is not reported.
        // ponytail: add a strict mode if this hides real cases.
        foreach ($scope->getType($first->value)->getConstantStrings() as $string) {
            if (!self::isAbsolute($string->getValue())) {
                return true;
            }
        }

        return false;
    }

    private static function isAbsolute(string $text): bool
    {
        if (str_starts_with($text, '@')) {
            return true; // a Unix timestamp
        }
        $parsed = date_parse($text);

        return 0 === $parsed['error_count']
            && \is_int($parsed['year']) && \is_int($parsed['month']) && \is_int($parsed['day'])
            && !isset($parsed['relative']);
    }
}
