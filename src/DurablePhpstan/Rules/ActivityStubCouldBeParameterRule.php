<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports an activity stub built with `$env->activityStub(Contract::class, …)` that could be an
 * `#[Activities(Contract::class, …)]` parameter of the workflow method instead.
 *
 * A report, not a rewrite: the developer moves it by hand, so a rare false positive is tolerable.
 * The rule still stays silent wherever the move would change what runs:
 *
 * - options that are not literals, or literals the attribute cannot carry with the same meaning:
 *   `default()` or an `of()` that sets nothing (the attribute would then build the stub with no
 *   options, and the Durable worker would retry with no backoff), an empty `taskQueue` (which the
 *   attribute refuses), an `activityId`, or a `backoffCoefficient` or `maximumInterval` the
 *   attribute refuses at registration where `of()` let the stub be built;
 * - a stub read anywhere but one workflow method, the constructor that builds it included, or
 *   inside a closure, an arrow function or an anonymous class;
 * - a local name assigned twice, or that is already a parameter of the method;
 * - a class that extends another, implements an interface or uses a trait: one of them may
 *   declare or call the workflow method, which then cannot gain a required parameter.
 *
 * Code that already fails, or a `nonRetryable` entry that never matches, is still reported, with
 * a warning that the worker then refuses to register the workflow: `of(0)`, a `nonRetryable`
 * entry that is not a `\Throwable` class, a contract with no `#[AsActivityMethod]`, and a
 * contract that names no class or interface. The rule does not see another method calling the workflow
 * method, `__call`, reflection or `get_object_vars()` reaching the property, or the local name
 * used before the stub is built: those are its false positives.
 *
 * @implements Rule<InClassNode>
 */
final class ActivityStubCouldBeParameterRule implements Rule
{
    public const IDENTIFIER = 'durable.activityStubCouldBeParameter';

    /** `ActivityOptions::of()`'s parameters, in order, and the attribute field each maps to. */
    private const OF_TO_ATTRIBUTE = [
        'retryLimit' => 'attempts',
        'timeouts' => 'startToClose',
        'initialInterval' => 'initialInterval',
        'nonRetryableExceptions' => 'nonRetryable',
        'taskQueue' => 'taskQueue',
        'backoffCoefficient' => 'backoffCoefficient',
        'maximumInterval' => 'maximumInterval',
        'summary' => 'summary',
        'activityId' => null,
        'cancellationType' => 'cancellationType',
    ];

    private const TIP = 'Keep activityStub() when the options are computed at run time, or when a signal, update or helper method, or a closure, uses the stub. The rule does not see another method calling the workflow method, __call, reflection or get_object_vars() reaching the property, or the local name used before the stub is built. Otherwise ignore this with the identifier ' . self::IDENTIFIER . '.';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();
        // An interface, a parent or a trait may declare the workflow method, which then cannot
        // gain a required parameter, or call it without the stub.
        if (!$class instanceof Class_ || null !== $class->extends || [] !== $class->implements || [] !== $class->getTraitUses()) {
            return [];
        }

        $workflowMethods = [];
        foreach ($class->getMethods() as $method) {
            if (self::isWorkflowMethod($method)) {
                $workflowMethods[$method->name->toLowerString()] = $method;
            }
        }
        if ([] === $workflowMethods) {
            return [];
        }

        $errors = [];
        // A stub built in the workflow method itself, into a local variable.
        foreach ($workflowMethods as $method) {
            foreach ($method->stmts ?? [] as $stmt) {
                if ($stmt instanceof Expression && $stmt->expr instanceof Assign
                    && $stmt->expr->var instanceof Variable && \is_string($stmt->expr->var->name)
                    && self::isOnlyAssignment($method, $stmt->expr->var->name)) {
                    $errors[] = $this->report($stmt->expr->expr, $stmt->expr->var->name, $method, $node->getClassReflection()->getName());
                }
            }
        }
        // A stub built in the constructor, into a property only one workflow method reads.
        foreach ($class->getMethod('__construct')->stmts ?? [] as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign
                && $stmt->expr->var instanceof PropertyFetch && self::isThis($stmt->expr->var->var)
                && $stmt->expr->var->name instanceof Identifier) {
                $name = $stmt->expr->var->name->toString();
                $reader = self::onlyReader($class, $name);
                if (null !== $reader && isset($workflowMethods[$reader])) {
                    $errors[] = $this->report($stmt->expr->expr, $name, $workflowMethods[$reader], $node->getClassReflection()->getName());
                }
            }
        }

        return array_values(array_filter($errors));
    }

    private function report(Expr $call, string $name, ClassMethod $method, string $className): ?IdentifierRuleError
    {
        if (!$call instanceof MethodCall || !$call->name instanceof Identifier || 'activityStub' !== $call->name->toString()) {
            return null;
        }
        $args = $call->getArgs();
        $contract = $args[0]->value ?? null;
        if (\count($args) !== \count($call->args) || \count($args) > 2 || null !== ($args[0]->name ?? null)
            || !$contract instanceof ClassConstFetch || !$contract->class instanceof Name
            || !$contract->name instanceof Identifier || 'class' !== $contract->name->toLowerString()) {
            return null;
        }
        $fields = isset($args[1]) ? self::fields($args[1]->value) : [];
        // Options given, but none the attribute would carry: it would build the stub with no
        // options at all, which is not the same as a default `ActivityOptions`.
        if (null === $fields || (isset($args[1]) && [] === $fields)) {
            return null;
        }

        $short = $contract->class->getLast();
        $printer = new Standard();
        $attribute = $short . '::class';
        foreach ($fields as $field => $value) {
            $attribute .= ', ' . $field . ': ' . $printer->prettyPrintExpr($value);
        }
        $refusals = $this->refusals($contract->class->toString(), $fields, $className);

        return RuleErrorBuilder::message(\sprintf(
            'Activity stub $%2$s could be a parameter of %1$s(): #[Activities(%3$s)] ActivityStub $%2$s, documented with @param ActivityStub<%4$s> $%2$s.%5$s',
            $method->name->toString(),
            $name,
            $attribute,
            $short,
            [] === $refusals ? '' : ' Warning: after the move, the worker refuses to register the workflow: ' . implode('; ', $refusals) . '.',
        ))
            ->identifier(self::IDENTIFIER)
            ->tip(self::TIP)
            ->line($call->getStartLine())
            ->build();
    }

    /**
     * The `#[Activities]` fields that say what the options say, or null when they cannot.
     *
     * @return array<string, Expr>|null
     */
    private static function fields(Expr $options): ?array
    {
        if (!$options instanceof StaticCall || !$options->class instanceof Name || ActivityOptions::class !== $options->class->toString()
            || !$options->name instanceof Identifier || 'of' !== $options->name->toLowerString()) {
            return null;
        }
        $parameters = array_keys(self::OF_TO_ATTRIBUTE);
        $fields = [];
        $numbers = [];
        foreach ($options->args as $position => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return null;
            }
            $parameter = null === $arg->name ? ($parameters[$position] ?? null) : $arg->name->toString();
            if (null === $parameter || !\array_key_exists($parameter, self::OF_TO_ATTRIBUTE)) {
                return null;
            }
            $value = $arg->value;
            if ($value instanceof ConstFetch && 'null' === $value->name->toLowerString()) {
                continue;
            }
            $field = self::OF_TO_ATTRIBUTE[$parameter];
            $accepted = match ($field) {
                'attempts' => $value instanceof Int_,
                'startToClose', 'initialInterval', 'maximumInterval', 'backoffCoefficient' => $value instanceof Int_ || $value instanceof Float_,
                'nonRetryable' => self::isClassList($value),
                // `of()` reads '' as no queue; the attribute refuses it.
                'taskQueue' => $value instanceof String_ && '' !== $value->value,
                'summary' => $value instanceof String_,
                'cancellationType' => $value instanceof ClassConstFetch && $value->class instanceof Name && ActivityCancellationType::class === $value->class->toString(),
                default => false,
            };
            if (!$accepted || null === $field) {
                return null;
            }
            // An empty list is the default on both sides: say nothing.
            if ($value instanceof Expr\Array_ && [] === $value->items) {
                continue;
            }
            $fields[$field] = $value;
            if ($value instanceof Int_ || $value instanceof Float_) {
                $numbers[$field] = (float) $value->value;
            }
        }
        // The attribute refuses at registration what `of()` lets through to scheduling.
        if (($numbers['backoffCoefficient'] ?? 1.0) < 1.0
            || ($numbers['maximumInterval'] ?? \INF) < ($numbers['initialInterval'] ?? 1.0)) {
            return null;
        }

        return $fields;
    }

    /**
     * What the loader refuses when it registers the workflow, where the source fails only on a run
     * or on a call.
     *
     * @param array<string, Expr> $fields
     *
     * @return list<string>
     */
    private function refusals(string $contract, array $fields, string $className): array
    {
        $refusals = [];
        if (($fields['attempts'] ?? null) instanceof Int_ && $fields['attempts']->value < 1) {
            $refusals[] = \sprintf('attempts: %d is not a number of attempts', $fields['attempts']->value);
        }
        $nonRetryable = $fields['nonRetryable'] ?? null;
        foreach ($nonRetryable instanceof Expr\Array_ ? $nonRetryable->items : [] as $item) {
            $class = $item->value instanceof String_ ? ltrim($item->value->value, '\\') : null;
            if ($item->value instanceof ClassConstFetch && $item->value->class instanceof Name) {
                $class = $item->value->class->isSpecialClassName() ? $className : $item->value->class->toString();
            }
            if (null !== $class && !$this->isThrowable($class)) {
                $refusals[] = \sprintf('nonRetryable: %s is not a \\Throwable class', $class);
            }
        }
        if (!$this->reflectionProvider->hasClass($contract)) {
            $refusals[] = \sprintf('%s names no class or interface', $contract);
        } elseif (!self::declaresActivityMethod($this->reflectionProvider->getClass($contract)->getNativeReflection())) {
            $refusals[] = \sprintf('%s declares no #[AsActivityMethod]', $contract);
        }

        return $refusals;
    }

    private function isThrowable(string $class): bool
    {
        if (!$this->reflectionProvider->hasClass($class)) {
            return false;
        }
        $reflection = $this->reflectionProvider->getClass($class);

        return \Throwable::class === $reflection->getName() || $reflection->implementsInterface(\Throwable::class);
    }

    /**
     * @param \ReflectionClass<object> $contract
     */
    private static function declaresActivityMethod(\ReflectionClass $contract): bool
    {
        foreach ($contract->getMethods() as $method) {
            if ([] !== $method->getAttributes(AsActivityMethod::class)) {
                return true;
            }
        }

        return false;
    }

    /** A second write, or a parameter of the same name, means the stub is not the only value. */
    private static function isOnlyAssignment(ClassMethod $method, string $name): bool
    {
        foreach ($method->params as $param) {
            if ($param->var instanceof Variable && $name === $param->var->name) {
                return false;
            }
        }
        $writes = (new NodeFinder())->find($method->stmts ?? [], static fn(Node $n): bool => $n instanceof Assign
            && $n->var instanceof Variable && $name === $n->var->name);

        return 1 === \count($writes);
    }

    private static function isClassList(Expr $value): bool
    {
        if (!$value instanceof Expr\Array_) {
            return false;
        }
        foreach ($value->items as $item) {
            if (null !== $item->key || $item->unpack || $item->byRef
                || !(self::isClassName($item->value) || $item->value instanceof String_)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lower-cased name of the one method that reads `$this->{$name}`, or null when none does,
     * when several do, when a closure does, or when the property may be reached another way.
     */
    private static function onlyReader(Class_ $class, string $name): ?string
    {
        $finder = new NodeFinder();
        $reads = static fn(Node $n): bool => ($n instanceof PropertyFetch || $n instanceof NullsafePropertyFetch)
            && (!$n->name instanceof Identifier || $name === $n->name->toString());
        // The constructor writes the property; any other fetch there reads it.
        $constructor = $class->getMethod('__construct')->stmts ?? [];
        $writes = $finder->find($constructor, static fn(Node $n): bool => $n instanceof Assign && $reads($n->var));
        if (\count($finder->find($constructor, $reads)) > \count($writes)) {
            return null;
        }
        $readers = [];
        foreach ($class->getMethods() as $method) {
            if ('__construct' === $method->name->toLowerString() || null === $finder->findFirst($method->stmts ?? [], $reads)) {
                continue;
            }
            foreach ($finder->find($method->stmts ?? [], static fn(Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction || $n instanceof Class_) as $nested) {
                if (null !== $finder->findFirst([$nested], $reads)) {
                    return null;
                }
            }
            $readers[] = $method->name->toLowerString();
        }

        return 1 === \count($readers) ? $readers[0] : null;
    }

    private static function isClassName(Expr $value): bool
    {
        return $value instanceof ClassConstFetch && $value->class instanceof Name
            && $value->name instanceof Identifier && 'class' === $value->name->toLowerString();
    }

    private static function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && 'this' === $expr->name;
    }

    private static function isWorkflowMethod(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (AsWorkflowMethod::class === $attribute->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }
}
