<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Keeps `#[Activities(T::class)]` and `@param ActivityStub<T>` saying the same thing.
 *
 * The attribute is what the loader reads at run time; the docblock is what PHPStan reads, because
 * no extension point types a parameter from an attribute. Two statements of one fact can drift
 * apart, and a drifted docblock would have PHPStan check the calls against the wrong contract.
 *
 * A missing docblock is reported too. Without the generic, {@see \Gplanchat\Durable\PHPStan\Reflection\StubMethodsExtension}
 * cannot resolve the contract and every call on the stub is already "undefined method"; this rule
 * reports the cause once, on the parameter, with the line to write. PHPStan has no non-failing
 * level, so it is an error with its own identifier, which a project can ignore.
 *
 * @implements Rule<InClassMethodNode>
 */
final class ActivitiesParameterRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $phpDocTypes = [];
        foreach ($node->getMethodReflection()->getVariants()[0]->getParameters() as $parameter) {
            $phpDocTypes[$parameter->getName()] = $parameter->getPhpDocType();
        }

        $errors = [];
        foreach ($node->getOriginalNode()->params as $param) {
            $contract = self::contractOf($param);
            if (null === $contract || !$param->var instanceof Node\Expr\Variable || !\is_string($param->var->name)) {
                continue;
            }
            $name = $param->var->name;
            $phpDoc = $phpDocTypes[$name] ?? null;
            // A bare `ActivityStub` answers its bound, `object`, which names no class.
            $declared = null === $phpDoc ? [] : TypeCombinator::removeNull($phpDoc)->getTemplateType(ActivityStub::class, 'TActivity')->getObjectClassNames();

            if ([] === $declared) {
                $short = substr($contract, (int) strrpos($contract, '\\') + 1);
                $errors[] = RuleErrorBuilder::message(\sprintf('Parameter $%s is #[Activities(%s::class)] but has no @param ActivityStub<%s>: PHPStan cannot check the calls on it.', $name, $contract, $short))
                    ->identifier('durable.activities.missingGeneric')
                    ->tip(\sprintf('Add /** @param ActivityStub<%s> $%s */ to the method.', $short, $name))
                    ->line($param->getStartLine())
                    ->build();

                continue;
            }

            if ([strtolower($contract)] !== array_map(strtolower(...), $declared)) {
                $errors[] = RuleErrorBuilder::message(\sprintf('Parameter $%s is #[Activities(%s::class)] but its @param says %s: the loader and PHPStan see two contracts.', $name, $contract, 'ActivityStub<' . implode('|', $declared) . '>'))
                    ->identifier('durable.activities.contractMismatch')
                    ->line($param->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    private static function contractOf(Node\Param $param): ?string
    {
        foreach ($param->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (Activities::class !== $attribute->name->toString()) {
                    continue;
                }
                // The contract is the first positional argument, or the one named `contract`:
                // the options are named, and may come before it.
                $value = null;
                foreach ($attribute->args as $position => $argument) {
                    if ('contract' === $argument->name?->toString() || (null === $argument->name && 0 === $position)) {
                        $value = $argument->value;

                        break;
                    }
                }
                if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
                    return $value->class->toString();
                }
                if ($value instanceof String_) {
                    return ltrim($value->value, '\\');
                }
            }
        }

        return null;
    }
}
