<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports a clock or randomness read that a workflow class reaches, however many calls down.
 *
 * A replayed value that comes from the clock differs on every replay. The whole class is walked,
 * signal and query handlers and private helpers included. The error lands on the workflow's own
 * call, where it can be fixed, and names the calls in between.
 *
 * @implements Rule<CollectedDataNode>
 */
final class NondeterministicReachabilityRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $edges = [];     // caller => list<[callee, file, line]>
        $sinks = [];     // caller => list<[label, file, line]>
        $workflows = []; // methods of the classes carrying #[AsWorkflow]
        foreach ($node->get(CallGraphCollector::class) as $file => $rows) {
            foreach ($rows as [$caller, $callee, $label, $line, $isWorkflow]) {
                if ($isWorkflow) {
                    $workflows[$caller] = true;
                }
                if ('' !== $callee) {
                    $edges[$caller][] = [$callee, $file, $line];
                }
                if (null !== $label) {
                    $sinks[$caller][] = [$label, $file, $line];
                }
            }
        }

        $errors = [];
        foreach (array_keys($workflows) as $start) {
            foreach ($sinks[$start] ?? [] as [$label, $file, $line]) {
                $errors[$file . ':' . $line . ':' . $label] = $this->error($label, [], $file, $line);
            }
            foreach ($edges[$start] ?? [] as [$callee, $file, $line]) {
                $path = $this->find($callee, $edges, $sinks, [$start => true]);
                if (null !== $path) {
                    $label = array_pop($path);
                    $errors[$file . ':' . $line . ':' . $label] = $this->error($label, $path, $file, $line);
                }
            }
        }

        return array_values($errors);
    }

    /**
     * @param array<string, list<array{string, string, int}>> $edges
     * @param array<string, list<array{string, string, int}>> $sinks
     * @param array<string, true>                            $seen
     *
     * @return list<string>|null the methods on the way, then the label of the read
     */
    private function find(string $method, array $edges, array $sinks, array $seen): ?array
    {
        if (isset($seen[$method])) {
            return null;
        }
        $seen[$method] = true;
        if (isset($sinks[$method])) {
            return [$method, $sinks[$method][0][0]];
        }
        foreach ($edges[$method] ?? [] as [$callee]) {
            $rest = $this->find($callee, $edges, $sinks, $seen);
            if (null !== $rest) {
                return [$method, ...$rest];
            }
        }

        return null;
    }

    /**
     * @param list<string> $via the methods between the workflow's call and the read
     */
    private function error(string $label, array $via, string $file, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            ([] === $via ? '' : 'Via ' . implode(' → ', $via) . ': ')
            . \sprintf('%s reads the current time or waits on the worker, which differs on every replay.', $label),
        )
            ->identifier('durable.nondeterministic')
            ->tip('Wrap it in $env->sideEffect(static fn () => …) so the value is journalled, compute it in an activity, or use $env->sleep() / $env->timer() to wait.')
            ->file($file)
            ->line($line)
            ->build();
    }
}
