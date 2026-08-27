<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Reflection;

use Gplanchat\Durable\Awaitable\Awaitable;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedFunctionVariant;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * La méthode du contrat, vue à travers le stub.
 *
 * Un contrat déclare ce que l'activité **rend** — `charge(string $id): string`. Le stub, lui, ne
 * l'exécute pas : il la planifie et rend un `Awaitable` que l'appelant attend. Rendre la réflexion
 * du contrat telle quelle ferait donc croire à PHPStan que `$this->orders->charge($id)` vaut une
 * chaîne, et il refuserait le `await()` qui suit — en signalant une faute qui n'en est pas une.
 *
 * Ce décorateur ne change qu'une chose : le type de retour devient `Awaitable<T>` où `T` est ce
 * que le contrat déclarait. Les paramètres, eux, sont ceux du contrat, ce qui est exactement le
 * but — c'est là que la vérification a lieu.
 */
final class SchedulingMethodReflection implements ExtendedMethodReflection
{
    public function __construct(
        private readonly ExtendedMethodReflection $contractMethod,
    ) {}

    public function getVariants(): array
    {
        return array_map($this->wrap(...), $this->contractMethod->getVariants());
    }

    public function getOnlyVariant(): ExtendedParametersAcceptor
    {
        return $this->wrap($this->contractMethod->getOnlyVariant());
    }

    public function getNamedArgumentsVariants(): ?array
    {
        $variants = $this->contractMethod->getNamedArgumentsVariants();

        return null === $variants ? null : array_map($this->wrap(...), $variants);
    }

    private function wrap(ExtendedParametersAcceptor $variant): ExtendedParametersAcceptor
    {
        return new ExtendedFunctionVariant(
            $variant->getTemplateTypeMap(),
            $variant->getResolvedTemplateTypeMap(),
            $variant->getParameters(),
            $variant->isVariadic(),
            $this->awaitableOf($variant->getReturnType()),
            $this->awaitableOf($variant->getPhpDocReturnType()),
            $this->awaitableOf($variant->getNativeReturnType()),
            $variant->getCallSiteVarianceMap(),
        );
    }

    private function awaitableOf(Type $inner): Type
    {
        return new GenericObjectType(Awaitable::class, [$inner]);
    }

    // --- Tout le reste est le contrat, inchangé. ---------------------------------------------

    public function getDeclaringClass(): ClassReflection
    {
        return $this->contractMethod->getDeclaringClass();
    }

    public function getName(): string
    {
        return $this->contractMethod->getName();
    }

    public function getPrototype(): \PHPStan\Reflection\ClassMemberReflection
    {
        return $this->contractMethod->getPrototype();
    }

    public function isStatic(): bool
    {
        return $this->contractMethod->isStatic();
    }

    public function isPrivate(): bool
    {
        return $this->contractMethod->isPrivate();
    }

    public function isPublic(): bool
    {
        return $this->contractMethod->isPublic();
    }

    public function getDocComment(): ?string
    {
        return $this->contractMethod->getDocComment();
    }

    public function isDeprecated(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->isDeprecated();
    }

    public function getDeprecatedDescription(): ?string
    {
        return $this->contractMethod->getDeprecatedDescription();
    }

    public function isFinal(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->isFinal();
    }

    public function isInternal(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->isInternal();
    }

    public function getThrowType(): ?Type
    {
        return $this->contractMethod->getThrowType();
    }

    public function hasSideEffects(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->hasSideEffects();
    }

    public function acceptsNamedArguments(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->acceptsNamedArguments();
    }

    public function getAsserts(): \PHPStan\Reflection\Assertions
    {
        return $this->contractMethod->getAsserts();
    }

    public function getSelfOutType(): ?Type
    {
        return $this->contractMethod->getSelfOutType();
    }

    public function returnsByReference(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->returnsByReference();
    }

    public function isFinalByKeyword(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->isFinalByKeyword();
    }

    public function isAbstract(): \PHPStan\TrinaryLogic|bool
    {
        return $this->contractMethod->isAbstract();
    }

    public function isBuiltin(): \PHPStan\TrinaryLogic|bool
    {
        return $this->contractMethod->isBuiltin();
    }

    public function isPure(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->isPure();
    }

    public function getPureUnlessCallableIsImpureParameters(): array
    {
        return $this->contractMethod->getPureUnlessCallableIsImpureParameters();
    }

    public function getAttributes(): array
    {
        return $this->contractMethod->getAttributes();
    }

    public function mustUseReturnValue(): \PHPStan\TrinaryLogic
    {
        return $this->contractMethod->mustUseReturnValue();
    }

    public function getResolvedPhpDoc(): ?\PHPStan\PhpDoc\ResolvedPhpDocBlock
    {
        return $this->contractMethod->getResolvedPhpDoc();
    }
}
