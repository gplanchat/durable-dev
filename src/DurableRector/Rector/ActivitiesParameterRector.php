<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Property;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Moves an activity stub built with `$environment->activityStub()` to an `ActivityStub` parameter
 * of the workflow method, marked `#[Activities]`: the form the documentation shows first (#778).
 * Two shapes qualify: a local variable assigned once at the top of the workflow method, and a
 * private property assigned once in the constructor and read only by that method.
 *
 * The constructor form stays supported, and is kept wherever the attribute cannot carry the stub:
 * options that are not literals, a stub read outside the workflow method (a signal or update method
 * receives the payload only), and a workflow method an interface or parent class declares, since
 * PHP forbids adding a required parameter there. The durable-rector README lists the cases.
 */
final class ActivitiesParameterRector extends AbstractRector
{
    /**
     * `ActivityOptions::of()` parameters, in order => the `#[Activities]` argument that says the
     * same, and the literal it takes. `activityId` has no counterpart, so it keeps the call.
     */
    private const OF_TO_ATTRIBUTE = [
        'retryLimit' => ['attempts', 'int'],
        'timeouts' => ['startToClose', 'number'],
        'initialInterval' => ['initialInterval', 'number'],
        'nonRetryableExceptions' => ['nonRetryable', 'classes'],
        'taskQueue' => ['taskQueue', 'string'],
        'backoffCoefficient' => ['backoffCoefficient', 'number'],
        'maximumInterval' => ['maximumInterval', 'number'],
        'summary' => ['summary', 'string'],
        'activityId' => [null, ''],
        'cancellationType' => ['cancellationType', 'cancellation'],
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly DocBlockUpdater $docBlockUpdater,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Move an activity stub the #[Activities] attribute can carry to a parameter of the workflow method',
            [new CodeSample(
                <<<'BEFORE'
#[AsWorkflowMethod]
public function run(string $orderId, WorkflowEnvironment $env): mixed
{
    $orders = $env->activityStub(OrderActivities::class, ActivityOptions::of(3, 30.0));

    return $env->await($orders->charge($orderId));
}
BEFORE,
                <<<'AFTER'
/**
 * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $orders
 */
#[AsWorkflowMethod]
public function run(string $orderId, WorkflowEnvironment $env, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class, attempts: 3, startToClose: 30.0)]
\Gplanchat\Durable\Activity\ActivityStub $orders): mixed
{
    return $env->await($orders->charge($orderId));
}
AFTER,
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof Class_);

        $method = $this->workflowMethod($node);
        if (null === $method || null === $method->stmts || $this->isDeclaredAbove($node, $method->name->toString())) {
            return null;
        }

        $changed = false;
        foreach ($method->stmts as $key => $stmt) {
            $built = $this->builtStub($stmt);
            if (null === $built || !$built[0] instanceof Variable || !\is_string($name = $built[0]->name)
                || $this->hasParam($method, $name) || 1 !== $this->writesTo($method, $name)) {
                continue;
            }
            unset($method->stmts[$key]);
            $this->addStubParam($method, $name, $built[1]);
            $changed = true;
        }
        $method->stmts = array_values($method->stmts);

        $constructor = $node->getMethod('__construct');
        foreach ($constructor->stmts ?? [] as $key => $stmt) {
            $built = $this->builtStub($stmt);
            if (null === $built || !$built[0] instanceof PropertyFetch || !$this->isName($built[0]->var, 'this')
                || null === ($name = $this->getName($built[0]->name))) {
                continue;
            }
            $property = $this->privateProperty($node, $name);
            if (null === $property || [] !== $node->getTraitUses() || $this->hasParam($method, $name)
                || $this->writesTo($method, $name) > 0 || !$this->readOnlyIn($node, $name, $method)) {
                continue;
            }
            \assert(null !== $constructor && null !== $constructor->stmts);
            unset($constructor->stmts[$key]);
            $node->stmts = array_values(array_filter($node->stmts, static fn(Stmt $s): bool => $s !== $property));
            $this->traverseNodesWithCallable($method->stmts, fn(Node $n): ?Variable => $this->isThisFetch($n, $name) ? new Variable($name) : null);
            $this->addStubParam($method, $name, $built[1]);
            $changed = true;
        }
        if (null !== $constructor?->stmts) {
            $constructor->stmts = array_values($constructor->stmts);
        }

        return $changed ? $node : null;
    }

    private function workflowMethod(Class_ $class): ?ClassMethod
    {
        $found = [];
        foreach ($class->getMethods() as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($this->isName($attribute->name, AsWorkflowMethod::class)) {
                        $found[] = $method;
                    }
                }
            }
        }

        // The loader wants exactly one; anything else is not a workflow this rule can reason about.
        return 1 === \count($found) && !$found[0]->isStatic() ? $found[0] : null;
    }

    private function isDeclaredAbove(Class_ $class, string $method): bool
    {
        $name = $this->getName($class);
        if (null === $name || !$this->reflectionProvider->hasClass($name)) {
            return true;
        }
        $native = $this->reflectionProvider->getClass($name)->getNativeReflection();
        $parent = $native->getParentClass();
        foreach ([...$native->getInterfaces(), ...(false === $parent ? [] : [$parent])] as $ancestor) {
            if ($ancestor->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{Expr, Attribute}|null what the stub is assigned to, and the attribute that carries it
     */
    private function builtStub(Stmt $stmt): ?array
    {
        if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
            return null;
        }
        $call = $stmt->expr->expr;
        if (!$call instanceof MethodCall || !$this->isName($call->name, 'activityStub') || $call->isFirstClassCallable()
            || !$this->isObjectType($call->var, new ObjectType(WorkflowEnvironment::class))) {
            return null;
        }

        $args = $call->getArgs();
        $contract = $args[0] ?? null;
        if (null === $contract || null !== $contract->name || $contract->unpack || \count($args) > 2
            || !$contract->value instanceof ClassConstFetch || !$this->isName($contract->value->name, 'class')
            || !$contract->value->class instanceof Name || $contract->value->class->isSpecialClassName()) {
            return null;
        }
        $options = isset($args[1]) ? $this->attributeOptions($args[1]) : [];
        if (null === $options) {
            return null;
        }

        return [$stmt->expr->var, new Attribute(new FullyQualified(Activities::class), [new Arg($contract->value), ...$options])];
    }

    /**
     * @return list<Arg>|null the `#[Activities]` arguments that say the same, or null when there are none
     */
    private function attributeOptions(Arg $arg): ?array
    {
        $options = $arg->value;
        if (null !== $arg->name || $arg->unpack || !$options instanceof StaticCall || !$options->class instanceof Name
            || !$this->isName($options->class, ActivityOptions::class) || $options->isFirstClassCallable()) {
            return null;
        }

        if ($this->isName($options->name, 'default') && [] === $options->args) {
            return [];
        }
        if (!$this->isName($options->name, 'of')) {
            return null;
        }

        $parameters = array_keys(self::OF_TO_ATTRIBUTE);
        $attributeArgs = [];
        foreach ($options->getArgs() as $position => $ofArg) {
            $parameter = null === $ofArg->name ? ($parameters[$position] ?? null) : $ofArg->name->toString();
            if ($ofArg->unpack || null === $parameter || !\array_key_exists($parameter, self::OF_TO_ATTRIBUTE)) {
                return null;
            }
            if ($ofArg->value instanceof ConstFetch && $this->isName($ofArg->value, 'null')) {
                continue;
            }
            [$target, $accepts] = self::OF_TO_ATTRIBUTE[$parameter];
            if (null === $target || !$this->isLiteral($ofArg->value, $accepts)) {
                return null;
            }
            $attributeArgs[] = new Arg($ofArg->value, name: new Identifier($target));
        }

        return $attributeArgs;
    }

    /** Whether the value is a literal of that kind: nothing computed at run time fits in an attribute. */
    private function isLiteral(Expr $value, string $kind): bool
    {
        return match ($kind) {
            'int' => $value instanceof Int_,
            'number' => $value instanceof Int_ || $value instanceof Float_,
            'string' => $value instanceof String_,
            'classes' => $value instanceof Array_ && [] === array_filter($value->items, fn(ArrayItem $item): bool => null !== $item->key || $item->unpack
                || !($item->value instanceof String_ || ($item->value instanceof ClassConstFetch && $item->value->class instanceof Name && $this->isName($item->value->name, 'class')))),
            'cancellation' => $value instanceof ClassConstFetch && $value->class instanceof Name
                && $this->isName($value->class, ActivityCancellationType::class) && !$this->isName($value->name, 'class'),
            default => false,
        };
    }

    private function privateProperty(Class_ $class, string $name): ?Property
    {
        $property = $class->getProperty($name);

        return null !== $property && 1 === \count($property->props) && $property->isPrivate() && !$property->isStatic() ? $property : null;
    }

    /** Whether `$this->$name` appears once in the constructor (its assignment) and elsewhere in `$method` only. */
    private function readOnlyIn(Class_ $class, string $name, ClassMethod $method): bool
    {
        $reads = 0;
        foreach ($class->getMethods() as $candidate) {
            $count = 0;
            $this->traverseNodesWithCallable($candidate->stmts ?? [], function (Node $n) use (&$count, $name): null {
                $count += $this->isThisFetch($n, $name) ? 1 : 0;

                return null;
            });
            $expected = match (true) {
                $candidate === $method => $count,
                $this->isName($candidate, '__construct') => 1,
                default => 0,
            };
            if ($count !== $expected) {
                return false;
            }
            $reads += $candidate === $method ? $count : 0;
        }

        return $reads > 0;
    }

    private function isThisFetch(Node $node, string $name): bool
    {
        return $node instanceof PropertyFetch && $this->isName($node->var, 'this') && $this->isName($node->name, $name);
    }

    private function hasParam(ClassMethod $method, string $name): bool
    {
        foreach ($method->params as $param) {
            if ($this->isName($param, $name)) {
                return true;
            }
        }

        return false;
    }

    /** How many times `$name` is written to in the method, nested scopes included: those count too. */
    private function writesTo(ClassMethod $method, string $name): int
    {
        $writes = 0;
        $this->traverseNodesWithCallable($method->stmts ?? [], function (Node $n) use (&$writes, $name): null {
            $targets = match (true) {
                $n instanceof Assign, $n instanceof AssignRef, $n instanceof AssignOp => [$n->var],
                $n instanceof Foreach_ => [$n->keyVar, $n->valueVar],
                default => [],
            };
            foreach ($targets as $target) {
                $writes += $target instanceof Variable && $this->isName($target, $name) ? 1 : 0;
            }

            return null;
        });

        return $writes;
    }

    /**
     * Before the first optional or variadic parameter, so no required parameter follows an optional
     * one. Position is free otherwise: the loader binds the input by name and the stub by attribute.
     */
    private function addStubParam(ClassMethod $method, string $name, Attribute $attribute): void
    {
        $position = \count($method->params);
        foreach ($method->params as $index => $param) {
            if (null !== $param->default || $param->variadic) {
                $position = $index;
                break;
            }
        }
        $param = new Param(new Variable($name), null, new FullyQualified(ActivityStub::class), attrGroups: [new AttributeGroup([$attribute])]);
        array_splice($method->params, $position, 0, [$param]);

        // PHPStan reads the contract from the docblock, and compares it with the attribute: both
        // name the class the way the source already did.
        $contract = $attribute->args[0]->value;
        \assert($contract instanceof ClassConstFetch && $contract->class instanceof Name);
        $written = $contract->class->getAttribute('originalName');
        $type = new GenericTypeNode(
            new IdentifierTypeNode('\\' . ActivityStub::class),
            [new IdentifierTypeNode($written instanceof Name ? $written->toCodeString() : $contract->class->toCodeString())],
        );
        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($method);
        $phpDocInfo->addTagValueNode(new ParamTagValueNode($type, false, '$' . $name, '', false));
        $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($method);
    }
}
