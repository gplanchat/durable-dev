<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Marks, in place, every call to the Temporal SDK that this migration cannot make.
 *
 * It changes no behaviour: it writes a `durable-rector:` comment above the statement and leaves the
 * code alone. The point is to answer, before anybody rewrites a line, **whether the migration is
 * available at all** — a workflow built on `Workflow::async()` and `Workflow::runLocked()` is not a
 * long migration, it is a redesign.
 *
 * The list of what it accepts is an **allow-list**, and deliberately so. `Workflow::` carries forty
 * or so methods and `WorkflowEnvironment` answers eight of them; a deny-list would pass in silence
 * every method nobody thought to enumerate, including the ones a future SDK release adds.
 */
final class UnmigratableTemporalCallRector extends AbstractRector
{
    public const MARKER = 'durable-rector:';

    /** The getVersion() marker written before the rewrite existed. */
    private const OLD_VERSION_MARKER = 'Workflow::getVersion() — no equivalent yet';

    private const SDK_WORKFLOW_FACADE = 'Temporal\Workflow';
    private const SDK_PROMISE_FACADE = 'Temporal\Promise';

    /** What TemporalFacadeToEnvironmentRector rewrites on `Promise::`, given its arguments. */
    private const REWRITABLE_PROMISE = ['all', 'any', 'some'];

    /**
     * The SDK failures that `temporal-sdk.php` deliberately does not rename: mapping one onto a
     * neighbour would silently change which catch block wins.
     */
    private const FAILURES_WITHOUT_COUNTERPART = [
        'Temporal\Exception\Failure\ApplicationFailure',
        'Temporal\Exception\Failure\ServerFailure',
        'Temporal\Exception\Failure\TerminatedFailure',
        'Temporal\Exception\Failure\TimeoutFailure',
    ];

    /**
     * The facade calls a Durable environment can answer. Everything else is reported.
     *
     * They are not rewritten here — that is the execution-model half of the migration, and it needs
     * a receiver this class does not have. Listing them keeps the report about decisions rather
     * than about work a tool still owes.
     */
    private const REWRITABLE = [
        'newActivityStub', 'newChildWorkflowStub', 'await', 'awaitWithTimeout',
        'timer', 'sideEffect', 'continueAsNew', 'getVersion',
    ];

    private const REASONS = [
        'now' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
        'uuid' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
        'uuid4' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
        'uuid7' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
        'executeActivity' => 'DUR039: a typed stub is the only way to schedule an activity; the contract interface is yours to write',
        'executeChildWorkflow' => 'DUR039: a typed stub is the only way; the contract interface is yours to write',
        'newUntypedActivityStub' => 'DUR039: a typed stub is the only way; the contract interface is yours to write',
        'newUntypedChildWorkflowStub' => 'DUR039: a typed stub is the only way; the contract interface is yours to write',
        'newUntypedExternalWorkflowStub' => 'no external-workflow stub, typed or not',
        'newExternalWorkflowStub' => 'no external-workflow stub',
        'newContinueAsNewStub' => 'continueAsNew() takes the workflow type and its payload directly, with no stub in between',
        'async' => 'no coroutine primitive: a stub assembles and returns an Awaitable, and await() is the only wait (DUR038)',
        'asyncDetached' => 'no detached coroutine; work that must outlive the scope is a child workflow',
        'runLocked' => 'no mutex; a workflow is single-threaded here, so what the lock protected may not need protecting',
        'allHandlersFinished' => 'no equivalent — handler completion is not observable from workflow code',
        'isReplaying' => 'no equivalent — replay is not observable from workflow code, by design',
        'upsertSearchAttributes' => 'search attributes are start options here, not writable from inside a run',
        'upsertTypedSearchAttributes' => 'search attributes are start options here, not writable from inside a run',
        'upsertMemo' => 'no memo',
        'registerQuery' => 'declare it with #[QueryMethod] instead',
        'registerSignal' => 'declare it with #[SignalMethod], or register it with onSignal()',
        'registerUpdate' => 'declare it with #[UpdateMethod], or register it with onUpdate()',
        'registerDynamicQuery' => 'no dynamic handler registration',
        'registerDynamicSignal' => 'no dynamic handler registration',
        'registerDynamicUpdate' => 'no dynamic handler registration',
    ];

    private const RUN_STATE_REASON = 'the environment exposes executionId() and nothing else of the run';

    private const RUN_STATE = [
        'getInfo', 'getCurrentContext', 'getInput', 'getInstance', 'getStackTrace',
        'getLastCompletionResult', 'getUpdateContext', 'getLogger',
        'getCurrentDetails', 'setCurrentDetails',
    ];

    private const UNMIGRATABLE_CLASSES = [
        'Temporal\Workflow\Saga' => 'Durable\Workflow\Saga is a different shape — each compensation calls $env->await() itself, and compensate() does not yield',
        'Temporal\Workflow\Mutex' => 'no mutex; a workflow is single-threaded here',
        // The options objects are the same idea on both sides and not the same shape: Durable builds
        // them with ActivityOptions::of() over ActivityTimeouts and RetryLimit, not with a fluent
        // withStartToCloseTimeout(). Left alone, they would read as migrated and could not run.
        'Temporal\Activity\ActivityOptions' => 'Durable\Activity\ActivityOptions is a different shape — ActivityOptions::of() with ActivityTimeouts and RetryLimit',
        'Temporal\Activity\LocalActivityOptions' => 'no local activities; this is an ordinary activity here, with its own options',
        'Temporal\Common\RetryOptions' => 'retry is a RetryLimit on the activity options here',
        'Temporal\Workflow\ChildWorkflowOptions' => 'Durable\ChildWorkflowOptions is a different shape',
        'Temporal\Workflow\ContinueAsNewOptions' => 'Durable\ContinueAsNewOptions is a different shape',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Comment every Temporal SDK call that has no counterpart in Durable, changing nothing else',
            [new CodeSample(
                <<<'BEFORE'
yield Workflow::runLocked($mutex, fn () => null);
BEFORE,
                <<<'AFTER'
// durable-rector: Workflow::runLocked() — no mutex; a workflow is single-threaded here, so what the lock protected may not need protecting
yield Workflow::runLocked($mutex, fn () => null);
AFTER,
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [Stmt::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof Stmt);

        if ($node instanceof Class_ && null !== $node->name && null !== $node->extends) {
            // A class reports only the failure it extends; its members report for themselves. An
            // anonymous class is not marked; the README lists it.
            $findings = array_filter([self::failureFinding($node->extends)]);
        } elseif ($node instanceof ClassLike || $node instanceof Catch_ || $node instanceof Finally_) {
            // Containers: their statements report for themselves, and marking both would say it twice.
            // A catch clause reports on its `try`, the statement a comment can sit above.
            return null;
        } elseif ($node instanceof Use_ || $node instanceof GroupUse) {
            // An import is not a use: the statements that reference the class carry the marker.
            return null;
        } elseif ($node instanceof ClassMethod || $node instanceof Function_) {
            // A method or a function reports only its signature types; its body reports for itself.
            $findings = $this->signatureFindings($node);
        } else {
            $findings = $this->findings($node);
        }

        // Already reported: a second pass adds no second comment for the same finding.
        $findings = array_filter($findings, static fn(string $finding): bool => !self::isMarked($node, $finding));
        if ([] === $findings) {
            return null;
        }

        $reportsVersion = [] !== array_filter($findings, static fn(string $finding): bool => str_starts_with($finding, 'Workflow::getVersion() '));
        $comments = $reportsVersion ? self::withoutOldVersionMarker($node->getComments()) : $node->getComments();
        foreach ($findings as $finding) {
            $comments[] = new Comment('// ' . self::MARKER . ' ' . $finding);
        }

        $node->setAttribute('comments', $comments);

        return $node;
    }

    /**
     * Whether a `durable-rector:` comment on the node already reports this finding.
     *
     * Two markers report the same finding when they match up to the first ` — `, the part that names
     * the construct. The explanation after it can differ: a marker written by an earlier run keeps
     * its text (#914). A different finding gets its own marker next to the first one (#917).
     */
    /**
     * Before #894, every getVersion() call carried "no equivalent yet". A call still unmapped gets
     * its new marker in place of that one; a call rewritten to version() loses it
     * ({@see TemporalFacadeToEnvironmentRector}).
     *
     * @param Comment[] $comments
     *
     * @return Comment[]
     */
    public static function withoutOldVersionMarker(array $comments): array
    {
        return array_values(array_filter(
            $comments,
            static fn(Comment $comment): bool => !str_contains($comment->getText(), self::MARKER . ' ' . self::OLD_VERSION_MARKER),
        ));
    }

    public static function isMarked(Node $node, string $finding): bool
    {
        $separator = strpos($finding, ' — ');
        $needle = self::MARKER . ' ' . (false === $separator ? $finding : substr($finding, 0, $separator + \strlen(' — ')));

        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] one line per unmigratable call, in source order, without duplicates
     */
    private function findings(Stmt $statement): array
    {
        $findings = [];

        if ($statement instanceof TryCatch) {
            foreach ($statement->catches as $catch) {
                foreach ($catch->types as $type) {
                    $findings[] = self::failureFinding($type);
                }
            }
        }

        // The getVersion() calls a yield waits on: the only ones version() answers, since it returns
        // the int where the SDK returns a promise of it.
        $yielded = [];

        $this->traverseNodesWithCallable($statement, static function (Node $node) use ($statement, &$findings, &$yielded): ?int {
            if ($node instanceof Stmt && $node !== $statement) {
                // A nested statement reports on its own line; stopping here is what keeps the
                // marker on the innermost statement rather than on every block above it.
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if ($node instanceof Yield_ && null !== $node->value) {
                $yielded[spl_object_id($node->value)] = true;

                return null;
            }

            if ($node instanceof ClassConstFetch && self::isDefaultVersion($node)) {
                $findings[] = 'Workflow::DEFAULT_VERSION — ChangePoint::DEFAULT_VERSION is the same -1; once temporal/sdk is removed this reference no longer resolves';

                return null;
            }

            if ($node instanceof Node\Name) {
                $findings[] = self::failureFinding($node);

                return null;
            }

            if ($node instanceof New_ && $node->class instanceof Node\Name) {
                $reason = self::UNMIGRATABLE_CLASSES[$node->class->toString()] ?? null;
                if (null !== $reason) {
                    $findings[] = \sprintf('new %s — %s', $node->class->getLast(), $reason);
                }

                return null;
            }

            if (!$node instanceof StaticCall || !$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
                return null;
            }

            $class = $node->class->toString();

            // An options object is reached through a static builder as often as through `new`.
            $reason = self::UNMIGRATABLE_CLASSES[$class] ?? null;
            if (null !== $reason) {
                $findings[] = \sprintf('%s::%s() — %s', $node->class->getLast(), $node->name->toString(), $reason);

                return null;
            }

            if (self::SDK_PROMISE_FACADE === $class) {
                $finding = self::promiseFinding($node);
                if (null !== $finding) {
                    $findings[] = $finding;
                }

                return null;
            }

            if (self::SDK_WORKFLOW_FACADE !== $class) {
                return null;
            }

            $name = $node->name->toString();

            if ('getVersion' === $name) {
                $finding = self::versionFinding($node, isset($yielded[spl_object_id($node)]));
                if (null !== $finding) {
                    $findings[] = $finding;
                }

                return null;
            }

            if (\in_array($name, self::REWRITABLE, true) && !self::isAwaitBeyondOneCondition($node, $name)) {
                return null;
            }

            if (self::isAwaitBeyondOneCondition($node, $name)) {
                $findings[] = \sprintf(
                    'Workflow::%s() with more than one condition — await() takes one condition and a deadline here, so a second condition would become a timeout; combine them by hand',
                    $name,
                );

                return null;
            }

            $findings[] = \sprintf('Workflow::%s() — %s', $name, self::reasonFor($name));

            return null;
        });

        return array_values(array_unique(array_filter($findings)));
    }

    /**
     * @return string[] one line per failure named in a parameter or return type, without duplicates
     */
    private function signatureFindings(ClassMethod|Function_ $function): array
    {
        $types = array_map(static fn(Node\Param $param): ?Node => $param->type, $function->params);
        $types[] = $function->returnType;

        $findings = [];
        foreach (array_filter($types) as $type) {
            // Nullable, union and intersection types hold their names as children.
            $this->traverseNodesWithCallable($type, static function (Node $node) use (&$findings): null {
                if ($node instanceof Node\Name) {
                    $findings[] = self::failureFinding($node);
                }

                return null;
            });
        }

        return array_values(array_unique(array_filter($findings)));
    }

    private static function failureFinding(Node\Name $name): ?string
    {
        if (!\in_array($name->toString(), self::FAILURES_WITHOUT_COUNTERPART, true)) {
            return null;
        }

        return \sprintf(
            '%s has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand',
            $name->getLast(),
        );
    }

    /**
     * The Promise calls TemporalFacadeToEnvironmentRector leaves as they are: another method, a
     * call with no iterable, or `some()` without its count.
     */
    private static function promiseFinding(StaticCall $call): ?string
    {
        \assert($call->name instanceof Node\Identifier);
        $name = $call->name->toString();

        if (!\in_array($name, self::REWRITABLE_PROMISE, true) || [] === $call->args) {
            return \sprintf('Promise::%s() — no Durable equivalent — decide by hand', $name);
        }

        if ('some' === $name && !isset($call->args[1])) {
            return 'some() without a count — pass the count to $env->some() by hand';
        }

        return null;
    }

    /**
     * `await(...$conditions)` settles on the first of many; `await($condition, $deadline)` does not.
     * The arities that map are one condition, or an interval plus one condition.
     */
    private static function isAwaitBeyondOneCondition(StaticCall $call, string $name): bool
    {
        return match ($name) {
            'await' => 1 !== \count($call->args),
            'awaitWithTimeout' => 2 !== \count($call->args),
            default => false,
        };
    }

    /**
     * The getVersion() calls TemporalFacadeToEnvironmentRector leaves as they are: another arity, or
     * a promise not waited on where it is made.
     */
    private static function versionFinding(StaticCall $call, bool $yielded): ?string
    {
        if (3 !== \count($call->args)) {
            return \sprintf(
                'Workflow::getVersion() with %d arguments — version() takes a change id, a minimum and a maximum; write the call by hand',
                \count($call->args),
            );
        }

        if (!$yielded) {
            return 'Workflow::getVersion() not yielded — it returns a promise there, and version() returns the int; rewrite the code that consumes it by hand';
        }

        return null;
    }

    private static function isDefaultVersion(ClassConstFetch $fetch): bool
    {
        return $fetch->class instanceof Node\Name
            && self::SDK_WORKFLOW_FACADE === $fetch->class->toString()
            && $fetch->name instanceof Node\Identifier
            && 'DEFAULT_VERSION' === $fetch->name->toString();
    }

    private static function reasonFor(string $name): string
    {
        if (\in_array($name, self::RUN_STATE, true)) {
            return self::RUN_STATE_REASON;
        }

        return self::REASONS[$name] ?? 'no Durable equivalent — decide by hand';
    }
}
