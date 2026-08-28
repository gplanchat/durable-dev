<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites a Temporal SDK activity contract onto Durable's attributes, **keeping the activity type
 * names the server already knows**.
 *
 * The SDK's type is `prefix . (AsActivityMethod::$name ?? methodName)` — one concatenation, no
 * separator inserted (`Temporal\Internal\Declaration\Reader\ActivityReader::activityName()`).
 * Durable's is `AsActivity::$name . '.' . AsActivityMethod::$name`, and the dot is not optional
 * ({@see \Gplanchat\Durable\Activity\ActivityContractResolver}). The two agree on exactly two
 * prefixes — the empty one, and one ending in a dot — and this rule refuses the rest rather than
 * rename an activity in flight.
 *
 * It also adds `#[AsActivityMethod]` to methods that carry none: every public method of an
 * `#[ActivityInterface]` is an activity for the SDK, and only an annotated one is for Durable.
 */
final class ActivityContractAttributesRector extends AbstractRector
{
    private const SDK_ACTIVITY_INTERFACE = 'Temporal\Activity\ActivityInterface';
    private const SDK_ACTIVITY_METHOD = 'Temporal\Activity\ActivityMethod';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rewrite a Temporal SDK activity contract onto Durable attributes, preserving the activity type names',
            [new CodeSample(
                <<<'BEFORE'
#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface OrderActivities
{
    public function charge(string $orderId): string;
}
BEFORE,
                <<<'AFTER'
#[\Gplanchat\Durable\Attribute\AsActivity(name: 'Order')]
interface OrderActivities
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod(name: 'charge')]
    public function charge(string $orderId): string;
}
AFTER,
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [Interface_::class, Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof ClassLike);

        $contract = $this->findAttribute($node->attrGroups, self::SDK_ACTIVITY_INTERFACE);
        if (null === $contract) {
            return null;
        }

        $prefix = $this->literalArgument($contract, 'prefix', 0);
        if (false === $prefix) {
            // A computed prefix — a constant, a concatenation. Renaming on a guess is the one
            // mistake this rule exists to avoid.
            return null;
        }

        // Absent is the SDK's own default, and it is the prefix most contracts carry.
        $prefix ??= '';

        $contractName = $this->contractNameForPrefix($prefix);
        if (null === $contractName) {
            return null;
        }

        $methodNames = [];
        foreach ($node->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            $name = $this->activityMethodName($method);
            if (false === $name) {
                return null;
            }

            $methodNames[] = [$method, $name];
        }

        $this->replaceAttribute($node, self::SDK_ACTIVITY_INTERFACE, AsActivity::class, $contractName);

        foreach ($methodNames as [$method, $name]) {
            $this->replaceAttribute($method, self::SDK_ACTIVITY_METHOD, AsActivityMethod::class, $name);
        }

        return $node;
    }

    /**
     * Durable joins with a dot it always inserts; the SDK inserts nothing. Only an empty prefix and
     * a dot-terminated one survive the round trip unchanged.
     */
    private function contractNameForPrefix(string $prefix): ?string
    {
        if ('' === $prefix) {
            return '';
        }

        if (!str_ends_with($prefix, '.')) {
            return null;
        }

        $name = substr($prefix, 0, -1);

        return str_contains($name, '.') ? null : $name;
    }

    /**
     * @return string|false the activity's own name, or false when it cannot be read literally
     */
    private function activityMethodName(ClassMethod $method): string|false
    {
        $attribute = $this->findAttribute($method->attrGroups, self::SDK_ACTIVITY_METHOD);
        if (null === $attribute) {
            // Unannotated: an activity all the same for the SDK, named after its method.
            return $method->name->toString();
        }

        $name = $this->literalArgument($attribute, 'name', 0);

        // Absent: the SDK falls back to the method's own name, so the type does not move.
        return $name ?? $method->name->toString();
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

    private function literalArgument(Attribute $attribute, string $name, int $position): string|false|null
    {
        $index = 0;
        foreach ($attribute->args as $arg) {
            $matches = null !== $arg->name
                ? $arg->name->toString() === $name
                : $index++ === $position;

            if (!$matches) {
                continue;
            }

            return $arg->value instanceof String_ ? $arg->value->value : false;
        }

        return null;
    }

    private function replaceAttribute(ClassLike|ClassMethod $node, string $sdkClass, string $durableClass, string $name): void
    {
        foreach ($node->attrGroups as $groupIndex => $attrGroup) {
            foreach ($attrGroup->attrs as $attrIndex => $attribute) {
                if ($attribute->name->toString() !== $sdkClass) {
                    continue;
                }

                unset($node->attrGroups[$groupIndex]->attrs[$attrIndex]);
                $node->attrGroups[$groupIndex]->attrs = array_values($node->attrGroups[$groupIndex]->attrs);
            }
        }

        $node->attrGroups = array_values(array_filter(
            $node->attrGroups,
            static fn(AttributeGroup $attrGroup): bool => [] !== $attrGroup->attrs,
        ));

        array_unshift($node->attrGroups, new AttributeGroup([
            new Attribute(new FullyQualified($durableClass), [
                new Arg(new String_($name), false, false, [], new Identifier('name')),
            ]),
        ]));
    }
}
