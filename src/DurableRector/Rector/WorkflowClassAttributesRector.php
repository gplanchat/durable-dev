<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Copies a Temporal SDK workflow contract's attributes from the interface onto the implementing
 * class, which is where Durable reads them.
 *
 * The SDK declares `#[WorkflowInterface]` and its method attributes on the **interface**; Durable's
 * loader skips any method not declared on the class itself
 * ({@see \Gplanchat\Durable\Workflow\WorkflowDefinitionLoader::resolveWorkflowMethod()}), so a
 * rename in place would leave a class the registry cannot see.
 *
 * The workflow type travels with it. The SDK's is `AsWorkflowMethod::$name ?? interfaceShortName`
 * ({@see \Temporal\Internal\Declaration\Reader\WorkflowReader}); Durable's `#[AsWorkflow]` is
 * **optional** and falls back to the class's short name — so a class migrated without an explicit
 * name compiles, passes its tests, and stops resolving every run already in flight. That fallback
 * is why this rule always writes the name out.
 *
 * It adds; it never removes. The SDK attributes stay on the interface until `temporal/sdk` leaves
 * the project, and a rule cannot read an attribute another rule has deleted in the same pass.
 */
final class WorkflowClassAttributesRector extends AbstractRector
{
    private const SDK_WORKFLOW_INTERFACE = 'Temporal\Workflow\WorkflowInterface';

    /** SDK method attribute => the Durable attribute it becomes. */
    private const METHOD_ATTRIBUTES = [
        'Temporal\Workflow\WorkflowMethod' => AsWorkflowMethod::class,
        'Temporal\Workflow\SignalMethod' => AsSignalMethod::class,
        'Temporal\Workflow\QueryMethod' => AsQueryMethod::class,
        'Temporal\Workflow\UpdateMethod' => AsUpdateMethod::class,
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Copy Temporal SDK workflow attributes from the interface onto the implementing class, preserving the workflow type name',
            [new CodeSample(
                <<<'BEFORE'
final class OrderWorkflow implements OrderWorkflowContract
{
    public function run(string $orderId)
    {
    }
}
BEFORE,
                <<<'AFTER'
#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'OrderWorkflowContract')]
final class OrderWorkflow implements OrderWorkflowContract
{
    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(string $orderId)
    {
    }
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

        if ($node->isAnonymous() || null === $node->namespacedName) {
            return null;
        }

        $className = $node->namespacedName->toString();
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $contract = $this->findWorkflowContract($className);
        if (null === $contract) {
            return null;
        }

        $changed = false;

        if (null === $this->findAttribute($node->attrGroups, AsWorkflow::class)) {
            $this->prependAttribute($node, AsWorkflow::class, $this->workflowType($contract));
            $changed = true;
        }

        foreach ($node->getMethods() as $method) {
            $durable = $this->durableAttributeFor($contract, $method);
            if (null === $durable) {
                continue;
            }

            [$durableClass, $name] = $durable;

            if (null !== $this->findAttribute($method->attrGroups, $durableClass)) {
                continue;
            }

            $this->prependAttribute($method, $durableClass, AsWorkflowMethod::class === $durableClass ? null : $name);
            $changed = true;
        }

        return $changed ? $node : null;
    }

    private function findWorkflowContract(string $className): ?\ReflectionClass
    {
        $reflection = $this->reflectionProvider->getClass($className)->getNativeReflection();

        foreach ($reflection->getInterfaces() as $interface) {
            foreach ($interface->getAttributes() as $attribute) {
                if (self::SDK_WORKFLOW_INTERFACE === $attribute->getName()) {
                    return $interface;
                }
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $contract
     */
    private function workflowType(\ReflectionClass $contract): string
    {
        foreach ($contract->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ('Temporal\Workflow\WorkflowMethod' !== $attribute->getName()) {
                    continue;
                }

                $explicit = $this->argument($attribute->getArguments(), 'name');
                if (\is_string($explicit) && '' !== $explicit) {
                    return $explicit;
                }
            }
        }

        return $contract->getShortName();
    }

    /**
     * @param \ReflectionClass<object> $contract
     *
     * @return array{class-string, string}|null
     */
    private function durableAttributeFor(\ReflectionClass $contract, ClassMethod $method): ?array
    {
        $name = $method->name->toString();
        if (!$contract->hasMethod($name)) {
            return null;
        }

        foreach ($contract->getMethod($name)->getAttributes() as $attribute) {
            $durableClass = self::METHOD_ATTRIBUTES[$attribute->getName()] ?? null;
            if (null === $durableClass) {
                continue;
            }

            $explicit = $this->argument($attribute->getArguments(), 'name');

            return [$durableClass, \is_string($explicit) && '' !== $explicit ? $explicit : $name];
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function argument(array $arguments, string $name): mixed
    {
        return $arguments[$name] ?? $arguments[0] ?? null;
    }

    /**
     * @param AttributeGroup[] $attrGroups
     */
    private function findAttribute(array $attrGroups, string $class): ?Attribute
    {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if ($attribute->name->toString() === $class) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    private function prependAttribute(Class_|ClassMethod $node, string $class, ?string $name): void
    {
        $args = null === $name
            ? []
            : [new Arg(new String_($name), false, false, [], new Identifier('name'))];

        array_unshift($node->attrGroups, new AttributeGroup([
            new Attribute(new FullyQualified($class), $args),
        ]));
    }
}
