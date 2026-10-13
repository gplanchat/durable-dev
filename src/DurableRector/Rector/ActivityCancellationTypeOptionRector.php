<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\Activities;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Drops the `cancellationType` argument of `new ActivityOptions()`, `ActivityOptions::of()` and
 * `#[Activities]` (#1052): no backend reads it.
 *
 * A named argument is removed wherever it stands. A positional one is removed from `new` (ninth
 * position) and from `of()` (tenth) when no argument before it is unpacked. A positional value in
 * `#[Activities]` is not handled: the attribute is documented with named arguments. The
 * `ActivityCancellationType` import stays; remove it with your import cleanup.
 */
final class ActivityCancellationTypeOptionRector extends AbstractRector
{
    private const OPTION = 'cancellationType';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Drop the cancellationType argument of the activity options',
            [new CodeSample(
                'ActivityOptions::of(3, 30, cancellationType: ActivityCancellationType::Abandon);',
                'ActivityOptions::of(3, 30);',
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [New_::class, StaticCall::class, Attribute::class];
    }

    public function refactor(Node $node): ?Node
    {
        $position = null;
        if ($node instanceof New_ && $this->isName($node->class, ActivityOptions::class)) {
            $position = 8;
        } elseif ($node instanceof StaticCall && $this->isName($node->class, ActivityOptions::class) && $this->isName($node->name, 'of')) {
            $position = 9;
        } elseif (!$node instanceof Attribute || !$this->isName($node->name, Activities::class)) {
            return null;
        }
        if (($node instanceof New_ || $node instanceof StaticCall) && $node->isFirstClassCallable()) {
            return null;
        }

        $kept = [];
        $unpacked = false;
        foreach ($node->args as $index => $arg) {
            \assert($arg instanceof Arg);
            $unpacked = $unpacked || $arg->unpack;
            if (null !== $arg->name ? self::OPTION === $arg->name->toString() : !$unpacked && $index === $position) {
                continue;
            }
            $kept[] = $arg;
        }
        if (\count($kept) === \count($node->args)) {
            return null;
        }
        $node->args = $kept;

        return $node;
    }
}
