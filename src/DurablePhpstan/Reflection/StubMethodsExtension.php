<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Reflection;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Apprend à PHPStan de quoi un stub Durable est capable.
 *
 * `ActivityStub`, `ChildWorkflowStub` et `NexusStub` résolvent leurs appels par `__call()`. Sans
 * extension,
 * PHPStan ne voit que des objets sans méthode et signale **tous** les appels de stub — les
 * corrects comme les fautifs :
 *
 * ```php
 * $this->orders->charge($orderId, 100);   // sans extension : « undefined method » — faux
 * $this->orders->chrage($orderId, 100);   // sans extension : « undefined method » — vrai
 * ```
 *
 * Le défaut n'est donc pas le silence, c'est le bruit. Quatre erreurs dont deux fausses se mettent
 * en ligne de base ou s'ignorent d'un bloc, et les deux vraies partent avec — ce qui revient au
 * même que ne rien vérifier, en plus coûteux.
 *
 * L'extension **distingue**. Elle débloque au passage une vérification que le bruit masquait : une
 * fois la méthode connue, PHPStan compare les arguments à ce que le contrat déclare.
 *
 * Le stub porte son contrat en paramètre générique, `ActivityStub<OrderActivities>`, et PHPStan
 * l'infère déjà depuis la signature de `WorkflowEnvironment::activityStub()`. Il suffit donc de lui
 * dire quelles méthodes ce contrat déclare : celles marquées {@see ActivityMethod} pour une
 * activité, {@see WorkflowMethod} pour un enfant, {@see AsNexusOperation} pour une opération Nexus.
 *
 * Le cas Nexus ajoute l'héritage. Un contrat Nexus se sépare en deux interfaces — celle que le
 * gestionnaire implémente, et celle qui l'étend pour l'appelant — et le stub appelle les deux.
 * `hasNativeMethod()` suit déjà la hiérarchie ; c'est `getAttributes()` sur la réflexion native
 * qui ne la suivrait pas si on lisait la méthode sur la mauvaise classe, d'où la lecture par
 * `getNativeReflection()->getMethod()`, qui la résout.
 *
 * Une méthode absente du contrat, ou présente mais non marquée, reste inconnue de PHPStan. C'est
 * le comportement voulu : le stub la refuse déjà à l'exécution, et l'analyse le dit désormais
 * avant.
 */
final class StubMethodsExtension implements MethodsClassReflectionExtension
{
    /**
     * Le stub, et l'attribut qui rend une méthode du contrat appelable à travers lui.
     *
     * @var array<class-string, class-string>
     */
    private const STUBS = [
        ActivityStub::class => ActivityMethod::class,
        ChildWorkflowStub::class => WorkflowMethod::class,
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
                'getMethod(%s) appelée alors que hasMethod() l\'a refusée.',
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

        // Déclarée par le contrat : reste à savoir si elle est appelable à travers le stub. Une
        // méthode non marquée est du code de contrat, pas une opération planifiable.
        $native = $contract->getNativeReflection()->getMethod($methodName);
        if ([] === $native->getAttributes($attribute)) {
            return null;
        }

        // Le contrat dit ce que l'activité rend ; le stub, lui, rend un Awaitable.
        return new SchedulingMethodReflection($contract->getNativeMethod($methodName));
    }

    /**
     * Le contrat porté par le stub, lu de son paramètre générique.
     *
     * Sans paramètre — un `ActivityStub` écrit sans préciser son contrat — il n'y a rien à
     * résoudre. L'appel reste alors inconnu plutôt que d'être accepté à l'aveugle : mieux vaut un
     * faux positif qu'une vérification silencieusement désactivée.
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
