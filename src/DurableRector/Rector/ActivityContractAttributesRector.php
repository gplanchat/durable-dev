<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use PhpParser\Comment;
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
 * Durable's is `AsActivity::$name . '.' . AsActivityMethod::$name`, or the method name alone when
 * the contract name is empty ({@see \Gplanchat\Durable\Activity\ActivityContractResolver}). The
 * two agree on the empty prefix and on one ending in a dot with something before it, and this rule
 * marks the rest rather than rename an activity in flight.
 *
 * It also adds `#[AsActivityMethod]` to methods that carry none: every public method of an
 * `#[ActivityInterface]` is an activity for the SDK, and only an annotated one is for Durable.
 */
final class ActivityContractAttributesRector extends AbstractRector
{
    private const SDK_ACTIVITY_INTERFACE = 'Temporal\Activity\ActivityInterface';
    private const SDK_ACTIVITY_METHOD = 'Temporal\Activity\ActivityMethod';

    private const PREFIX_MARKER = 'activity prefix "%s" cannot become a Durable activity name — name the activities by hand';
    private const METHOD_MARKER = 'activity method name is not a literal — name this activity by hand';

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
            // mistake this rule exists to avoid, so it is marked, as written in the source.
            return $this->mark($node, \sprintf(self::PREFIX_MARKER, $this->sourceOf($this->argument($contract, 'prefix', 0))));
        }

        // Absent is the SDK's own default, and it is the prefix most contracts carry.
        $prefix ??= '';

        $contractName = $this->contractNameForPrefix($prefix);
        if (null === $contractName) {
            return $this->mark($node, \sprintf(self::PREFIX_MARKER, $prefix));
        }

        $methodNames = [];
        $unnamed = [];
        foreach ($node->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            $name = $this->activityMethodName($method);
            if (false === $name) {
                $unnamed[] = $method;

                continue;
            }

            $methodNames[] = [$method, $name];
        }

        if ([] !== $unnamed) {
            // The contract moves as a whole or not at all; the methods that hold it back are marked.
            $changed = false;
            foreach ($unnamed as $method) {
                $changed = null !== $this->mark($method, self::METHOD_MARKER) || $changed;
            }

            return $changed ? $node : null;
        }

        $this->replaceAttribute($node, self::SDK_ACTIVITY_INTERFACE, AsActivity::class, $contractName);

        foreach ($methodNames as [$method, $name]) {
            $this->replaceAttribute($method, self::SDK_ACTIVITY_METHOD, AsActivityMethod::class, $name);
        }

        return $node;
    }

    /**
     * The SDK concatenates prefix and method name; Durable inserts a dot between a non-empty contract
     * name and the method name, and uses the method name alone when the contract name is empty. Only
     * an empty prefix and a dot-terminated one with something before the dot keep their names.
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

        // A lone '.' would become an empty contract name, and the dot with it would be lost.
        return '' === $name ? null : $name;
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
        $arg = $this->argument($attribute, $name, $position);
        if (null === $arg) {
            return null;
        }

        return $arg->value instanceof String_ ? $arg->value->value : false;
    }

    private function argument(Attribute $attribute, string $name, int $position): ?Arg
    {
        $index = 0;
        foreach ($attribute->args as $arg) {
            $matches = null !== $arg->name
                ? $arg->name->toString() === $name
                : $index++ === $position;

            if ($matches) {
                return $arg;
            }
        }

        return null;
    }

    /** The expression as the source spells it, not as a printer would re-spell its resolved names. */
    private function sourceOf(?Arg $arg): string
    {
        \assert(null !== $arg);

        return substr(
            $this->getFile()->getFileContent(),
            $arg->value->getStartFilePos(),
            $arg->value->getEndFilePos() - $arg->value->getStartFilePos() + 1,
        );
    }

    /**
     * What this rule cannot rename, it says so above the declaration, as
     * {@see UnmigratableTemporalCallRector} does above a call. A second pass adds nothing it already wrote.
     */
    private function mark(ClassLike|ClassMethod $node, string $finding): ?Node
    {
        if (UnmigratableTemporalCallRector::isMarked($node, $finding)) {
            return null;
        }

        $node->setAttribute('comments', [
            ...$node->getComments(),
            new Comment('// ' . UnmigratableTemporalCallRector::MARKER . ' ' . $finding),
        ]);

        return $node;
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
