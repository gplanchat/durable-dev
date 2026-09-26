<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Durable\SearchAttributes;

/**
 * The two Keyword search attributes Durable writes on every run it starts on Temporal, and the
 * one place that says how their values are spelled, for the writer and the catalogue alike (#558).
 *
 * Written only when {@see TemporalConnection::$searchAttributes} is on, and both must be
 * registered on the namespace before it is: a server refuses a start that names an attribute it
 * has no mapping for.
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
     * Temporal documents this limit per Keyword value, in characters. Counted here in bytes, which
     * is never more.
     */
    public const MAX_LENGTH = 255;

    /**
     * Between the kept prefix and the hash of a long value. A normalized value only holds `%` as
     * `%25` or `%2E`, so no value short enough to be kept as is can contain this.
     */
    private const LONG_FORM_SEPARATOR = '%~';

    /**
     * The caller's attributes, with Durable's two set over them when the connection enables them.
     */
    public static function of(TemporalConnection $connection, string $executionId, string $workflowName, SearchAttributes $callers): SearchAttributes
    {
        if (!$connection->searchAttributes) {
            return $callers;
        }

        return $callers
            ->keyword(self::WORKFLOW_NAME, self::value($workflowName))
            ->keyword(self::EXECUTION_ID, self::value($executionId));
    }

    /**
     * A workflow name or an execution id as it is written, and as a query must spell it.
     *
     * No backslash: the visibility query parser matches no value that holds one, however it is
     * escaped (1c60c18a, #558). A backslash becomes a dot, so a class name reads
     * `App.Workflow.OrderWorkflow`. An alias is free text and may hold a dot or a `%` already, so
     * those are escaped first (`%` → `%25`, `.` → `%2E`): one character at a time, so no two
     * values share a form, and a prefix of a value normalizes to a prefix of its form.
     *
     * Past {@see MAX_LENGTH}, the form keeps its first characters and ends with a hash of the
     * whole: still one form per value, so an exact match finds it. A prefix match only reaches as
     * far as the kept characters.
     */
    public static function value(string $value): string
    {
        $normalized = strtr($value, ['%' => '%25', '.' => '%2E', '\\' => '.']);
        if (\strlen($normalized) <= self::MAX_LENGTH) {
            return $normalized;
        }

        $hash = hash('sha256', $normalized);
        $kept = self::MAX_LENGTH - \strlen(self::LONG_FORM_SEPARATOR) - \strlen($hash);

        // The cut is in bytes, and the value travels as JSON: a multibyte character it would split
        // goes whole. Always the same bytes for the same value, so the form stays stable.
        $prefix = preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', substr($normalized, 0, $kept)) ?? '';

        return $prefix . self::LONG_FORM_SEPARATOR . $hash;
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
