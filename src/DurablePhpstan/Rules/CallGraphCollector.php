<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use Gplanchat\Durable\Attribute\AsWorkflow;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Records, for every call made inside a method, what it calls and whether it reads the clock.
 *
 * Rules see one node at a time; the reachability rule needs the whole project, so each file hands
 * it these rows: `[caller, callee, clock label, line, caller is a workflow]`. Calls inside a closure
 * belong to the method that contains it; calls inside `$env->sideEffect()` are not recorded.
 *
 * ponytail: a call through an interface resolves to the interface's method, so the walk stops
 * there; calls to functions, to `__call()` and through callbacks are not followed; classes under
 * `vendor/` and the `Gplanchat\Durable\` namespace are not walked. Follow the implementations of an
 * interface if that hides real reads.
 *
 * @implements Collector<CallLike, array{string, string, string|null, int, bool}>
 */
final class CallGraphCollector implements Collector
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();
        $function = $scope->getFunctionName();
        if (null === $class || null === $function || true === $node->getAttribute(SideEffectMarker::ATTRIBUTE)) {
            return null;
        }
        $label = ClockSources::of($node, $scope);
        $callee = self::calleeOf($node, $scope);
        if (null === $label && null === $callee) {
            return null;
        }

        return [
            $class->getName() . '::' . $function,
            $callee ?? '',
            $label,
            $node->getStartLine(),
            [] !== $class->getNativeReflection()->getAttributes(AsWorkflow::class),
        ];
    }

    private static function calleeOf(CallLike $call, Scope $scope): ?string
    {
        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            $method = $scope->getMethodReflection($scope->getType($call->var), $call->name->name);
        } elseif ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
            $method = $scope->getMethodReflection($scope->resolveTypeByName($call->class), $call->name->name);
        } elseif ($call instanceof New_ && $call->class instanceof Name) {
            $method = $scope->getMethodReflection($scope->resolveTypeByName($call->class), '__construct');
        } else {
            return null;
        }
        $declaring = $method?->getDeclaringClass();
        // The framework is the API a workflow calls (`await()`, `sleep()`): the clock reads behind it
        // are its own business, not the workflow's. Under `vendor/` it is skipped by path; in the
        // monorepo, by namespace.
        if (null === $declaring || str_contains((string) $declaring->getFileName(), '/vendor/') || str_starts_with($declaring->getName(), 'Gplanchat\\Durable\\')) {
            return null;
        }

        return $declaring->getName() . '::' . $method->getName();
    }
}
