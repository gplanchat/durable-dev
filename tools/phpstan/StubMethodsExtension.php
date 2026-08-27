<?php

declare(strict_types=1);

namespace Gplanchat\Tooling\PHPStan;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\Generic\GenericObjectType;

/**
 * Apprend à PHPStan de quoi un stub est capable.
 *
 * `ActivityStub` et `ChildWorkflowStub` résolvent leurs appels par `__call()`, donc toute analyse
 * statique les voit comme des objets sans méthode. Depuis que le stub est la **seule** façon de
 * planifier une activité ou un enfant (DUR037, DUR038), ça revenait à ne plus vérifier
 * l'ordonnancement du tout : `$this->orders->chrage($id)` passait sans un mot.
 *
 * Le stub porte son contrat en paramètre générique — `ActivityStub<OrderActivities>` — et PHPStan
 * l'infère déjà. Il suffit donc de lui dire quelles méthodes ce contrat déclare : celles marquées
 * {@see ActivityMethod} pour une activité, {@see WorkflowMethod} pour un enfant.
 *
 * Une méthode absente du contrat, ou présente mais non marquée, reste inconnue — ce qui est le
 * comportement voulu : `ActivityStub::__call()` lève déjà `BadMethodCallException` dans ce cas,
 * et l'analyse le dit maintenant avant l'exécution.
 */
final class StubMethodsExtension implements MethodsClassReflectionExtension
{
    /** Le stub, et l'attribut qui marque une méthode appelable à travers lui. */
    private const STUBS = [
        ActivityStub::class => ActivityMethod::class,
        ChildWorkflowStub::class => WorkflowMethod::class,
    ];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return null !== $this->resolve($classReflection, $methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): ExtendedMethodReflection
    {
        $method = $this->resolve($classReflection, $methodName);
        if (null === $method) {
            throw new \LogicException(\sprintf('getMethod() appelée pour %s, que hasMethod() a refusée.', $methodName));
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

        // Le contrat déclare la méthode : reste à savoir si elle est appelable à travers le stub.
        $native = $contract->getNativeReflection()->getMethod($methodName);
        if ([] === $native->getAttributes($attribute)) {
            return null;
        }

        return $contract->getNativeMethod($methodName);
    }

    /**
     * Le contrat porté par le stub, lu de son paramètre générique.
     *
     * Sans paramètre — un `ActivityStub` écrit sans préciser son contrat — il n'y a rien à
     * résoudre, et l'appel reste inconnu plutôt que d'être accepté à l'aveugle.
     */
    private function contractOf(ClassReflection $classReflection): ?ClassReflection
    {
        $types = $classReflection->getActiveTemplateTypeMap()->getTypes();
        if ([] === $types) {
            return null;
        }

        $contract = array_values($types)[0];
        $classes = $contract->getObjectClassReflections();

        return $classes[0] ?? null;
    }
}
