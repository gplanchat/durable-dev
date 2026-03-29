<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use PHPStan\Reflection\Annotations\AnnotationMethodReflection;
use PHPStan\Reflection\Annotations\AnnotationsMethodParameterReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\TypehintHelper;

/**
 * Exposes activity contract methods on {@see ActivityStub} as if they were real methods,
 * with return type {@see Awaitable}<R> where R is the contract method return type.
 */
final class ActivityStubMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, ExtendedMethodReflection|null> */
    private array $cache = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return null !== $this->resolveMethod($classReflection, $methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): ExtendedMethodReflection
    {
        $method = $this->resolveMethod($classReflection, $methodName);
        if (null === $method) {
            throw new ShouldNotHappenException();
        }

        return $method;
    }

    private function resolveMethod(ClassReflection $classReflection, string $methodName): ?ExtendedMethodReflection
    {
        $cacheKey = $classReflection->getCacheKey().'::'.$methodName;
        if (\array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        if (ActivityStub::class !== $classReflection->getName()) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        $contractClass = $this->resolveContractClassName($classReflection);
        if (null === $contractClass || !$this->reflectionProvider->hasClass($contractClass)) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        $contractReflection = $this->reflectionProvider->getClass($contractClass);
        $nativeClass = $contractReflection->getNativeReflection();
        if (!$nativeClass->hasMethod($methodName)) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        $native = $nativeClass->getMethod($methodName);
        if ($native->isStatic() || !$native->isPublic()) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        if ($native->getDeclaringClass()->getName() !== $contractClass) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        if ([] === $native->getAttributes(ActivityMethod::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        if (!$contractReflection->hasMethod($methodName)) {
            $this->cache[$cacheKey] = null;

            return null;
        }

        $businessReturnType = TypehintHelper::decideTypeFromReflection(
            $native->getReturnType(),
            null,
            $contractReflection,
        );

        $awaitableReturn = new GenericObjectType(Awaitable::class, [
            $businessReturnType,
        ]);

        $params = [];
        foreach ($native->getParameters() as $parameter) {
            $paramType = TypehintHelper::decideTypeFromReflection(
                $parameter->getType(),
                null,
                $contractReflection,
                $parameter->isVariadic(),
            );
            $byRef = $parameter->isPassedByReference()
                ? PassedByReference::createReadsArgument()
                : PassedByReference::createNo();
            $params[] = new AnnotationsMethodParameterReflection(
                $parameter->getName(),
                $paramType,
                $byRef,
                $parameter->isOptional(),
                $parameter->isVariadic(),
                null,
            );
        }

        $throwType = $classReflection->hasNativeMethod('__call')
            ? $classReflection->getNativeMethod('__call')->getThrowType()
            : null;

        $method = new AnnotationMethodReflection(
            $methodName,
            $classReflection,
            $awaitableReturn,
            $params,
            false,
            $native->isVariadic(),
            $throwType,
            TemplateTypeMap::createEmpty(),
        );

        $this->cache[$cacheKey] = $method;

        return $method;
    }

    private function resolveContractClassName(ClassReflection $classReflection): ?string
    {
        $map = $classReflection->getPossiblyIncompleteActiveTemplateTypeMap();
        if ($map->isEmpty()) {
            return null;
        }

        $type = null;
        if ($map->hasType('TActivity')) {
            $type = $map->getType('TActivity');
        } elseif ($map->hasType('T')) {
            $type = $map->getType('T');
        }
        if (null === $type) {
            return null;
        }

        $names = $type->getObjectClassNames();
        if ([] !== $names) {
            return $names[0];
        }

        foreach ($map->getTypes() as $candidate) {
            $candidateNames = $candidate->getObjectClassNames();
            if ([] !== $candidateNames) {
                return $candidateNames[0];
            }
        }

        return null;
    }
}
