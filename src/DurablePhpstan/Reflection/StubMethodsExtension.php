<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Reflection;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Teaches PHPStan what a Durable stub is capable of.
 *
 * `ActivityStub`, `ChildWorkflowStub` and `NexusStub` resolve their calls through `__call()`.
 * Without the extension, PHPStan sees only objects with no method and reports **every** stub
 * call — the correct ones as well as the faulty ones:
 *
 * ```php
 * $this->orders->charge($orderId, 100);   // without the extension: "undefined method" — false
 * $this->orders->chrage($orderId, 100);   // without the extension: "undefined method" — true
 * ```
 *
 * The default is therefore not silence, it is noise. Four errors of which two are false go into a
 * baseline or get ignored in one block, and the two true ones leave along with them — which comes
 * to the same as checking nothing, only more expensive.
 *
 * The extension **tells them apart**. It unlocks along the way a check the noise was hiding: once
 * the method is known, PHPStan compares the arguments against what the contract declares.
 *
 * The stub carries its contract as a generic parameter, `ActivityStub<OrderActivities>`, and
 * PHPStan already infers it from the signature of `WorkflowEnvironment::activityStub()`. All that
 * is left is to tell it which methods that contract declares: those marked {@see AsActivityMethod}
 * for an activity, {@see AsWorkflowMethod} for a child, {@see AsNexusOperation} for a Nexus
 * operation.
 *
 * The Nexus case adds inheritance. A Nexus contract splits into two interfaces — the one the
 * handler implements, and the one that extends it for the caller — and the stub calls both.
 * `hasNativeMethod()` already follows the hierarchy; it is `getAttributes()` on the native
 * reflection that would not follow it if the method were read on the wrong class, hence the read
 * through `getNativeReflection()->getMethod()`, which resolves it.
 *
 * A method absent from the contract, or present but not marked, stays unknown to PHPStan. That is
 * the intended behaviour: the stub already refuses it at runtime, and the analysis now says so
 * beforehand.
 */
final class StubMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * The stub, and the attribute that makes a contract method callable through it.
     *
     * @var array<class-string, class-string>
     */
    private const STUBS = [
        ActivityStub::class => AsActivityMethod::class,
        ChildWorkflowStub::class => AsWorkflowMethod::class,
        NexusStub::class => AsNexusOperation::class,
    ];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return null !== $this->resolve($classReflection, $methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): ExtendedMethodReflection
    {
        $method = $this->resolve($classReflection, $methodName);
        if (null === $method) {
            throw new \LogicException(\sprintf(
                'getMethod(%s) was called although hasMethod() refused it.',
                $methodName,
            ));
        }

        return $method;
    }

    private function resolve(ClassReflection $classReflection, string $methodName): ?ExtendedMethodReflection
    {
        $attribute = self::STUBS[$classReflection->getName()] ?? null;
        if (null === $attribute) {
            return null;
        }

        $contract = $this->contractOf($classReflection);
        if (null === $contract || !$contract->hasNativeMethod($methodName)) {
            return null;
        }

        // Declared by the contract: what remains is whether it is callable through the stub. An
        // unmarked method is contract code, not a schedulable operation.
        $native = $contract->getNativeReflection()->getMethod($methodName);
        if ([] === $native->getAttributes($attribute)) {
            return null;
        }

        // The contract says what the activity returns; the stub itself returns an Awaitable.
        return new SchedulingMethodReflection($contract->getNativeMethod($methodName));
    }

    /**
     * The contract carried by the stub, read from its generic parameter.
     *
     * With no parameter — an `ActivityStub` written without stating its contract — there is
     * nothing to resolve. The call then stays unknown rather than being accepted blindly: better a
     * false positive than a check silently switched off.
     */
    private function contractOf(ClassReflection $classReflection): ?ClassReflection
    {
        $types = $classReflection->getActiveTemplateTypeMap()->getTypes();
        if ([] === $types) {
            return null;
        }

        $classes = array_values($types)[0]->getObjectClassReflections();

        return $classes[0] ?? null;
    }
}
