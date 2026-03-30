<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Psalm;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Magic {@see ActivityStub} calls: {@see MethodReturnTypeProviderInterface} runs before argument checks;
 * we stash the receiver expression so {@see MethodParamsProviderInterface} can resolve the contract class.
 *
 * @internal
 */
final class ActivityStubPsalmHandlers implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * @var list<array{0: StatementsAnalyzer, 1: Expr, 2: string}>
     */
    private static array $pendingMagicCalls = [];

    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array
    {
        return [ActivityStub::class];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $contractClass = self::contractClassFromReceiver($source, $stmt->var);
        if (null === $contractClass) {
            return null;
        }

        $methodName = $event->getMethodNameLowercase();
        if (!self::isValidActivityMethod($contractClass, $methodName)) {
            return null;
        }

        $returnUnion = self::reflectionReturnUnion($contractClass, $methodName);
        $awaitable = new Union([
            new TGenericObject(Awaitable::class, [$returnUnion]),
        ]);

        self::$pendingMagicCalls[] = [$source, $stmt->var, $methodName];

        return $awaitable;
    }

    /**
     * @return ?array<int, FunctionLikeParameter>
     */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $methodLc = $event->getMethodNameLowercase();
        $receiver = self::popPendingReceiver($source, $methodLc);
        if (null === $receiver) {
            return null;
        }

        $contractClass = self::contractClassFromReceiver($source, $receiver);
        if (null === $contractClass || !self::isValidActivityMethod($contractClass, $methodLc)) {
            return null;
        }

        try {
            $rm = new \ReflectionMethod($contractClass, $methodLc);
        } catch (\ReflectionException) {
            // ADR018: méthode absente ou inaccessible — sentinelle null (analyse statique), pas une erreur runtime à journaliser.
            return null;
        }

        $params = [];
        foreach ($rm->getParameters() as $rp) {
            $paramUnion = self::reflectionParameterUnion($rp);
            $params[] = new FunctionLikeParameter(
                $rp->getName(),
                $rp->isPassedByReference(),
                $paramUnion,
                $paramUnion,
                null,
                null,
                $rp->isOptional(),
                $rp->allowsNull(),
                $rp->isVariadic(),
            );
        }

        return $params;
    }

    private static function popPendingReceiver(StatementsAnalyzer $source, string $methodLc): ?Expr
    {
        $n = \count(self::$pendingMagicCalls);
        for ($i = $n - 1; $i >= 0; --$i) {
            [$a, $expr, $m] = self::$pendingMagicCalls[$i];
            if ($a === $source && $m === $methodLc) {
                array_splice(self::$pendingMagicCalls, $i, 1);

                return $expr;
            }
        }

        return null;
    }

    private static function contractClassFromReceiver(StatementsAnalyzer $analyzer, Expr $receiver): ?string
    {
        $union = $analyzer->node_data->getType($receiver);
        if (null === $union) {
            return null;
        }

        foreach ($union->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject) {
                continue;
            }
            if (0 !== strcasecmp($atomic->value, ActivityStub::class)) {
                continue;
            }
            if ([] === $atomic->type_params) {
                return null;
            }
            $first = $atomic->type_params[0];
            $resolved = self::namedObjectFromUnion($first);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private static function namedObjectFromUnion(Union $union): ?string
    {
        foreach ($union->getAtomicTypes() as $a) {
            if ($a instanceof TTemplateParam) {
                $inner = self::namedObjectFromUnion($a->as);
                if (null !== $inner) {
                    return $inner;
                }

                continue;
            }

            if ($a instanceof TNamedObject && !$a instanceof TGenericObject) {
                return $a->value;
            }
        }

        return null;
    }

    private static function isValidActivityMethod(string $contractClass, string $methodNameLc): bool
    {
        try {
            $rm = new \ReflectionMethod($contractClass, $methodNameLc);
        } catch (\ReflectionException) {
            // ADR018: contrat ou méthode non reflétable — faux pour isValidActivityMethod.
            return false;
        }

        if ($rm->isStatic() || !$rm->isPublic()) {
            return false;
        }

        if ($rm->getDeclaringClass()->getName() !== $contractClass) {
            return false;
        }

        return [] !== $rm->getAttributes(ActivityMethod::class, \ReflectionAttribute::IS_INSTANCEOF);
    }

    private static function reflectionReturnUnion(string $contractClass, string $methodNameLc): Union
    {
        try {
            $rm = new \ReflectionMethod($contractClass, $methodNameLc);
        } catch (\ReflectionException) {
            // ADR018: pas de type de retour inférable — mixed pour l’analyse Psalm.
            return Type::getMixed();
        }

        return self::reflectionTypeToUnion($rm->getReturnType());
    }

    private static function reflectionParameterUnion(\ReflectionParameter $rp): Union
    {
        return self::reflectionTypeToUnion($rp->getType());
    }

    private static function reflectionTypeToUnion(?\ReflectionType $t): Union
    {
        if (null === $t) {
            return Type::getMixed();
        }

        if ($t instanceof \ReflectionUnionType) {
            $parts = [];
            foreach ($t->getTypes() as $inner) {
                if ($inner instanceof \ReflectionNamedType) {
                    $parts[] = self::reflectionNamedTypeString($inner);
                }
            }

            return [] !== $parts ? Type::parseString(implode('|', $parts)) : Type::getMixed();
        }

        if ($t instanceof \ReflectionIntersectionType) {
            return Type::getMixed();
        }

        if ($t instanceof \ReflectionNamedType) {
            return Type::parseString(self::reflectionNamedTypeString($t));
        }

        return Type::getMixed();
    }

    private static function reflectionNamedTypeString(\ReflectionNamedType $t): string
    {
        $name = $t->getName();
        if ('void' === $name) {
            return 'void';
        }

        return $t->isBuiltin() ? $name : '\\'.$name;
    }
}
