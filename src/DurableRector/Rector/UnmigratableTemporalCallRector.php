<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
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

    private const SDK_WORKFLOW_FACADE = 'Temporal\Workflow';

    /**
     * The facade calls a Durable environment can answer. Everything else is reported.
     *
     * They are not rewritten here — that is the execution-model half of the migration, and it needs
     * a receiver this class does not have. Listing them keeps the report about decisions rather
     * than about work a tool still owes.
     */
    private const REWRITABLE = [
        'newActivityStub', 'newChildWorkflowStub', 'await', 'awaitWithTimeout',
        'timer', 'sideEffect', 'continueAsNew',
    ];

    private const REASONS = [
        'getVersion' => 'no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands',
        'now' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
        'uuid' => 'sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename',
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
        'Temporal\Workflow\Saga' => 'no saga helper — the shape is a deadline and a compensation path, written out by hand',
        'Temporal\Workflow\Mutex' => 'no mutex; a workflow is single-threaded here',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Comment every Temporal SDK call that has no counterpart in Durable, changing nothing else',
            [new CodeSample(
                <<<'BEFORE'
$version = yield Workflow::getVersion('change', 1, 2);
BEFORE,
                <<<'AFTER'
// durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change
$version = yield Workflow::getVersion('change', 1, 2);
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

        if ($node instanceof ClassLike || $node instanceof ClassMethod || $node instanceof Function_) {
            // Containers: their statements report for themselves, and marking both would say it twice.
            return null;
        }

        $findings = $this->findings($node);
        if ([] === $findings) {
            return null;
        }

        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), self::MARKER)) {
                // Already reported. A second pass must not stack a second comment.
                return null;
            }
        }

        $comments = $node->getComments();
        foreach ($findings as $finding) {
            $comments[] = new Comment('// ' . self::MARKER . ' ' . $finding);
        }

        $node->setAttribute('comments', $comments);

        return $node;
    }

    /**
     * @return string[] one line per unmigratable call, in source order, without duplicates
     */
    private function findings(Stmt $statement): array
    {
        $findings = [];

        $this->traverseNodesWithCallable($statement, static function (Node $node) use ($statement, &$findings): ?int {
            if ($node instanceof Stmt && $node !== $statement) {
                // A nested statement reports on its own line; stopping here is what keeps the
                // marker on the innermost statement rather than on every block above it.
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            if ($node instanceof New_ && $node->class instanceof Node\Name) {
                $reason = self::UNMIGRATABLE_CLASSES[$node->class->toString()] ?? null;
                if (null !== $reason) {
                    $findings[] = \sprintf('new %s — %s', $node->class->getLast(), $reason);
                }

                return null;
            }

            if (!$node instanceof StaticCall
                || !$node->class instanceof Node\Name
                || self::SDK_WORKFLOW_FACADE !== $node->class->toString()
                || !$node->name instanceof Node\Identifier
            ) {
                return null;
            }

            $name = $node->name->toString();
            if (\in_array($name, self::REWRITABLE, true)) {
                return null;
            }

            $findings[] = \sprintf('Workflow::%s() — %s', $name, self::reasonFor($name));

            return null;
        });

        return array_values(array_unique($findings));
    }

    private static function reasonFor(string $name): string
    {
        if (\in_array($name, self::RUN_STATE, true)) {
            return self::RUN_STATE_REASON;
        }

        return self::REASONS[$name] ?? 'no Durable equivalent — decide by hand';
    }
}
