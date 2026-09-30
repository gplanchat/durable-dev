<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
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
    public function __construct(
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
        if (null === $method || null === $method->stmts) {
            return null;
        }

        $changed = false;
        foreach ($method->stmts as $key => $stmt) {
            $built = $this->builtStub($stmt);
            if (null === $built || !$built[0] instanceof Variable || !\is_string($name = $built[0]->name)) {
                continue;
            }
            unset($method->stmts[$key]);
            $this->addStubParam($method, $name, $built[1]);
            $changed = true;
        }
        $method->stmts = array_values($method->stmts);

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
        if (isset($args[1])) {
            return null;
        }

        return [$stmt->expr->var, new Attribute(new FullyQualified(Activities::class), [new Arg($contract->value)])];
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
