<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Marks every node inside a closure passed to `->sideEffect()`.
 *
 * `$env->sideEffect()` journals what its closure returns: the closure runs once and a replay reads
 * the recorded value. A clock read in there is the correct way to read the clock, so the rules
 * leave those nodes alone.
 *
 * ponytail: matches the method name only, not the type of the receiver; a `sideEffect()` on an
 * unrelated class would exempt its closure too.
 */
final class SideEffectMarker extends NodeVisitorAbstract
{
    public const ATTRIBUTE = 'durable.inSideEffect';

    /** @var array<int, true> object ids of the closures passed to sideEffect() */
    private array $open = [];
    private int $depth = 0;

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && 'sideEffect' === $node->name->toString()) {
            foreach ($node->args as $arg) {
                if ($arg instanceof Node\Arg && ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction)) {
                    $this->open[spl_object_id($arg->value)] = true;
                }
            }
        }
        if (isset($this->open[spl_object_id($node)])) {
            ++$this->depth;
        }
        if ($this->depth > 0) {
            $node->setAttribute(self::ATTRIBUTE, true);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (isset($this->open[spl_object_id($node)])) {
            --$this->depth;
            unset($this->open[spl_object_id($node)]);
        }

        return null;
    }
}
