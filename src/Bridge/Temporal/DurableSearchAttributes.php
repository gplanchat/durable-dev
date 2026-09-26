<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Durable\SearchAttributes;

/**
 * The two Keyword search attributes Durable writes on every run it starts on Temporal, and the
 * one place that says how their values are spelled, for the writer and the catalogue alike (#558).
 *
 * Both must be registered on the namespace before the first start: a server refuses a start that
 * names an attribute it has no mapping for.
 *
 * ```
 * temporal operator search-attribute create --namespace <ns> \
 *     --name DurableWorkflowName --type Keyword --name DurableExecutionId --type Keyword
 * ```
 */
final class DurableSearchAttributes
{
    public const WORKFLOW_NAME = 'DurableWorkflowName';
    public const EXECUTION_ID = 'DurableExecutionId';

    /**
     * The caller's attributes, with Durable's two set over them.
     */
    public static function of(string $executionId, string $workflowName, SearchAttributes $callers): SearchAttributes
    {
        return $callers
            ->keyword(self::WORKFLOW_NAME, self::normalizedName($workflowName))
            ->keyword(self::EXECUTION_ID, $executionId);
    }

    /**
     * The workflow name without a backslash: the visibility query parser matches no value that
     * holds one, however it is escaped (1c60c18a, #558). A backslash becomes a dot, so a class
     * name reads `App.Workflow.OrderWorkflow`.
     *
     * No two names share a normalized form. An alias is free text and may hold a dot or a `%`
     * already, so those are escaped first (`%` → `%25`, `.` → `%2E`): the mapping is an escape
     * scheme, one character at a time, and can be read back.
     */
    public static function normalizedName(string $workflowName): string
    {
        return strtr($workflowName, ['%' => '%25', '.' => '%2E', '\\' => '.']);
    }

    /**
     * A value as a visibility query literal, quoted. It comes from outside, so a quote in it must
     * not end the literal.
     */
    public static function literal(string $value): string
    {
        return "'" . addcslashes($value, "\\'") . "'";
    }
}
