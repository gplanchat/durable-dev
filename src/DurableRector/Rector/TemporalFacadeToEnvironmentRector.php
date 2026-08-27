<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\WorkflowEnvironment;
use PhpParser\Comment;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * The execution-model half: the static facade becomes an injected environment, and `yield` goes.
 *
 * Three things happen together because none of them is separable from the others.
 *
 * **The receiver does not exist in the source.** `Workflow::` is static; `$this->environment` is
 * not, so the rule adds a promoted `WorkflowEnvironment` constructor parameter. It prepends it —
 * position is free, because Durable resolves the constructor by **type**
 * ({@see \Gplanchat\Durable\Workflow\WorkflowDefinitionLoader::instantiate()}), and prepending is
 * the one position that never puts a required parameter after an optional one.
 *
 * **`yield` is what says whether a call waits**, and it is the only thing that says it.
 * `yield Workflow::timer($d)` waits, so it becomes `sleep($d)`; a bare `Workflow::timer($d)` handed
 * to a race assembles, so it becomes `timer($d)`. Splitting the two rewrites across two rules would
 * lose that discriminator the moment Rector interleaved them.
 *
 * **A de-yielded method may no longer declare `\Generator`.** The type is removed, never replaced:
 * the SDK could not declare what the method actually returns, and inventing it here would be a
 * guess with a `TypeError` behind it. An interface that declared `\Generator` loses it too —
 * stripping only the class would make the class widen its own contract, which is fatal.
 *
 * What it will not do is rewrite in a **static** method: there is no `$this` to route through. It
 * marks those and leaves them, because turning working code into a parse error is worse than
 * leaving it alone.
 *
 * **And it will not touch a class that is not workflow code at all.** `yield` is ordinary PHP: an
 * interceptor in `temporalio/samples-php` yields reflection attributes out of a plain iterator, and
 * an earlier draft of this rule turned that into `await()`. A class qualifies only if it implements
 * an `#[WorkflowInterface]` contract or calls the facade somewhere. Inside one that qualifies,
 * every non-static method is rewritten — an SDK workflow is generator-coloured throughout, helpers
 * included, which is the colouring problem this migration exists to remove. The one shape to check
 * by hand afterwards is a plain iterator generator living inside a workflow class.
 */
final class TemporalFacadeToEnvironmentRector extends AbstractRector
{
    private const SDK_WORKFLOW_FACADE = 'Temporal\Workflow';
    private const SDK_PROMISE_FACADE = 'Temporal\Promise';

    private const ENVIRONMENT_PROPERTY = 'environment';

    /** Facade call => the environment method it becomes, when it is a plain rename. */
    private const DIRECT = [
        'newActivityStub' => 'activityStub',
        'newChildWorkflowStub' => 'childWorkflowStub',
        'sideEffect' => 'sideEffect',
        'continueAsNew' => 'continueAsNew',
        'await' => 'await',
    ];

    /** Generator-ish return types a de-yielded method may not keep. */
    private const GENERATOR_TYPES = ['Generator', 'Traversable', 'iterable'];

    private const SDK_METHOD_ATTRIBUTES = [
        'Temporal\Workflow\WorkflowMethod',
        'Temporal\Workflow\SignalMethod',
        'Temporal\Workflow\QueryMethod',
        'Temporal\Workflow\UpdateMethod',
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace the static Temporal Workflow facade with an injected WorkflowEnvironment, and remove the generator colouring',
            [new CodeSample(
                <<<'BEFORE'
final class OrderWorkflow implements OrderWorkflowContract
{
    public function run(string $orderId): \Generator
    {
        $activities = Workflow::newActivityStub(OrderActivities::class);
        yield Workflow::timer(3600);

        return yield $activities->charge($orderId);
    }
}
BEFORE,
                <<<'AFTER'
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }

    public function run(string $orderId)
    {
        $activities = $this->environment->activityStub(OrderActivities::class);
        $this->environment->sleep(3600);

        return $this->environment->await($activities->charge($orderId));
    }
}
AFTER,
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [Class_::class, Interface_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Interface_) {
            return $this->refactorContract($node);
        }

        \assert($node instanceof Class_);

        if ($node->isAnonymous() || !$this->isWorkflowCode($node)) {
            return null;
        }

        $usesEnvironment = false;

        foreach ($node->getMethods() as $method) {
            if ($method->isStatic()) {
                $usesEnvironment = $this->markStaticMethod($method) || $usesEnvironment;

                continue;
            }

            $usesEnvironment = $this->rewriteBody($method) || $usesEnvironment;
        }

        if (!$usesEnvironment) {
            return null;
        }

        $this->injectEnvironment($node);

        return $node;
    }

    /**
     * `yield` belongs to PHP before it belongs to Temporal. A class earns the rewrite by implementing
     * an SDK workflow contract, or by calling the facade — never by containing a generator.
     */
    private function isWorkflowCode(Class_ $class): bool
    {
        if ($this->callsTheFacade($class)) {
            return true;
        }

        if (null === $class->namespacedName) {
            return false;
        }

        $className = $class->namespacedName->toString();
        if (!$this->reflectionProvider->hasClass($className)) {
            return false;
        }

        foreach ($this->reflectionProvider->getClass($className)->getNativeReflection()->getInterfaces() as $interface) {
            foreach ($interface->getAttributes() as $attribute) {
                if ('Temporal\Workflow\WorkflowInterface' === $attribute->getName()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function callsTheFacade(Class_ $class): bool
    {
        $found = false;

        $this->traverseNodesWithCallable($class->stmts, function (Node $node) use (&$found): ?Node {
            if ($node instanceof StaticCall
                && ($this->isFacade($node, self::SDK_WORKFLOW_FACADE) || $this->isFacade($node, self::SDK_PROMISE_FACADE))
            ) {
                $found = true;
            }

            return null;
        });

        return $found;
    }

    /**
     * An SDK contract declares `\Generator` on methods whose implementations are about to stop being
     * generators. The class may not widen what the interface narrowed, so both sides drop it.
     */
    private function refactorContract(Interface_ $node): ?Node
    {
        $changed = false;

        foreach ($node->getMethods() as $method) {
            if (!$this->hasSdkMethodAttribute($method)) {
                continue;
            }

            if ($this->stripGeneratorReturnType($method)) {
                $changed = true;
            }
        }

        return $changed ? $node : null;
    }

    /**
     * @return bool whether the method now needs `$this->environment`
     */
    private function rewriteBody(ClassMethod $method): bool
    {
        if (null === $method->stmts) {
            return false;
        }

        $used = false;
        $deYielded = false;

        $this->traverseNodesWithCallable($method->stmts, function (Node $node) use (&$used, &$deYielded): ?Node {
            if ($node instanceof YieldFrom) {
                // `yield from $this->helper()` — the helper stopped being a generator with its caller.
                $deYielded = true;

                return $node->expr;
            }

            if ($node instanceof Yield_) {
                $replacement = $this->rewriteYield($node);
                if (null !== $replacement) {
                    $used = true;
                    $deYielded = true;
                }

                return $replacement;
            }

            if ($node instanceof StaticCall) {
                $replacement = $this->rewriteStaticCall($node);
                if (null !== $replacement) {
                    $used = true;
                }

                return $replacement;
            }

            return null;
        });

        // The two are not the same question. `yield from` alone touches no environment, and a method
        // that stops yielding may no longer declare `\Generator` whether or not it gained a receiver.
        if ($deYielded) {
            $this->stripGeneratorReturnType($method);
        }

        return $used;
    }

    /**
     * `yield` is the SDK's wait. Four facade calls already wait once rewritten; everything else
     * yielded was a promise, and a promise waited for is `await()`.
     */
    private function rewriteYield(Yield_ $yield): ?Expr
    {
        if (null === $yield->value) {
            return null;
        }

        $value = $yield->value;

        if ($value instanceof StaticCall && $this->isFacade($value, self::SDK_WORKFLOW_FACADE)) {
            $name = $this->staticCallName($value);

            if ('timer' === $name) {
                // Yielded, so it waits: that is `sleep()`, which is `await()` on a timer written short.
                return $this->environmentCall('sleep', $value->args);
            }

            if (\in_array($name, ['sideEffect', 'continueAsNew', 'await'], true)) {
                return $this->environmentCall(self::DIRECT[$name], $value->args);
            }

            if ('awaitWithTimeout' === $name) {
                return $this->rewriteAwaitWithTimeout($value);
            }
        }

        return $this->environmentCall('await', [new Arg($value)]);
    }

    private function rewriteStaticCall(StaticCall $call): ?Expr
    {
        $name = $this->staticCallName($call);
        if (null === $name) {
            return null;
        }

        if ($this->isFacade($call, self::SDK_WORKFLOW_FACADE)) {
            if ('timer' === $name) {
                // Not yielded: it assembles, and something else will wait on it.
                return $this->environmentCall('timer', $call->args);
            }

            if ('awaitWithTimeout' === $name) {
                return $this->rewriteAwaitWithTimeout($call);
            }

            $target = self::DIRECT[$name] ?? null;

            return null === $target ? null : $this->environmentCall($target, $call->args);
        }

        if ($this->isFacade($call, self::SDK_PROMISE_FACADE)) {
            return $this->rewritePromise($name, $call->args);
        }

        return null;
    }

    /**
     * `Promise::all($p)` takes one iterable; `all()` is variadic. An array literal is unpacked into
     * arguments; anything else is spread, because a rule cannot see what a variable holds.
     *
     * @param Arg[] $args
     */
    private function rewritePromise(string $name, array $args): ?Expr
    {
        if (!\in_array($name, ['all', 'any', 'some'], true) || [] === $args) {
            return null;
        }

        $awaitables = $this->spread($args[0]->value);

        if ('some' !== $name) {
            return $this->environmentCall($name, $awaitables);
        }

        if (!isset($args[1])) {
            return null;
        }

        // some(iterable, count) becomes some(count, ...awaitables).
        return $this->environmentCall('some', [new Arg($args[1]->value), ...$awaitables]);
    }

    /**
     * @return Arg[]
     */
    private function spread(Expr $iterable): array
    {
        if ($iterable instanceof Array_) {
            $args = [];
            foreach ($iterable->items as $item) {
                if ($item->unpack || null !== $item->key) {
                    return [new Arg($iterable, false, true)];
                }

                $args[] = new Arg($item->value);
            }

            return $args;
        }

        return [new Arg($iterable, false, true)];
    }

    private function rewriteAwaitWithTimeout(StaticCall $call): ?Expr
    {
        if (!isset($call->args[0], $call->args[1])) {
            return null;
        }

        // awaitWithTimeout(timeout, condition) becomes await(condition, deadline).
        return $this->environmentCall('await', [
            new Arg($call->args[1]->value),
            new Arg($call->args[0]->value),
        ]);
    }

    /**
     * @param Arg[] $args
     */
    private function environmentCall(string $method, array $args): MethodCall
    {
        return new MethodCall(
            new PropertyFetch(new Variable('this'), new Identifier(self::ENVIRONMENT_PROPERTY)),
            new Identifier($method),
            $args,
        );
    }

    private function injectEnvironment(Class_ $class): void
    {
        $constructor = $class->getMethod('__construct');

        if (null !== $constructor && $this->hasEnvironmentParam($constructor)) {
            return;
        }

        $param = new Param(
            new Variable(self::ENVIRONMENT_PROPERTY),
            null,
            new FullyQualified(WorkflowEnvironment::class),
            false,
            false,
            [],
            Modifiers::PRIVATE | Modifiers::READONLY,
        );

        if (null === $constructor) {
            $constructor = new ClassMethod(new Identifier('__construct'), [
                'flags' => Modifiers::PUBLIC,
                'params' => [$param],
                'stmts' => [],
            ]);

            array_unshift($class->stmts, $constructor);

            return;
        }

        array_unshift($constructor->params, $param);
    }

    private function hasEnvironmentParam(ClassMethod $constructor): bool
    {
        foreach ($constructor->params as $param) {
            if ($param->type instanceof Node\Name && WorkflowEnvironment::class === $param->type->toString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A static method has no `$this`. Saying so is the only honest move: the alternative is output
     * that does not parse.
     */
    private function markStaticMethod(ClassMethod $method): bool
    {
        $found = false;
        $this->traverseNodesWithCallable($method->stmts ?? [], function (Node $node) use (&$found): ?Node {
            if ($node instanceof StaticCall
                && ($this->isFacade($node, self::SDK_WORKFLOW_FACADE) || $this->isFacade($node, self::SDK_PROMISE_FACADE))
            ) {
                $found = true;
            }

            return null;
        });

        if (!$found) {
            return false;
        }

        foreach ($method->getComments() as $comment) {
            if (str_contains($comment->getText(), UnmigratableTemporalCallRector::MARKER)) {
                return false;
            }
        }

        $comments = $method->getComments();
        $comments[] = new Comment(
            '// ' . UnmigratableTemporalCallRector::MARKER
            . ' a static method has no $this — move this to an instance method, or pass the environment in',
        );
        $method->setAttribute('comments', $comments);

        // Reported, not rewritten: the class gains nothing to inject.
        return false;
    }

    private function stripGeneratorReturnType(ClassMethod $method): bool
    {
        $returnType = $method->returnType;

        if (!$returnType instanceof Identifier && !$returnType instanceof Node\Name) {
            return false;
        }

        if (!\in_array($returnType->toString(), self::GENERATOR_TYPES, true)) {
            return false;
        }

        $method->returnType = null;

        return true;
    }

    private function hasSdkMethodAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if (\in_array($attribute->name->toString(), self::SDK_METHOD_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isFacade(StaticCall $call, string $facade): bool
    {
        return $call->class instanceof Node\Name && $facade === $call->class->toString();
    }

    private function staticCallName(StaticCall $call): ?string
    {
        return $call->name instanceof Identifier ? $call->name->toString() : null;
    }
}
