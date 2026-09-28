<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The way back through a run listing (#383, the user's decision of 2026-09-28).
 *
 * No catalog can page backwards: Temporal's visibility only pages forward and refuses `ORDER BY`.
 * So the cursor a catalog hands out carries, besides its own cursor, the stack of the cursors that
 * led to the page. "Previous" is the page before, listed again from the cursor that produced it: on
 * Temporal, the same visibility query re-issued with the earlier page token. One helper for the
 * four catalogs, so the semantics cannot drift.
 *
 * ponytail: the cursor grows by one entry per page. Past {@see MAX_DEPTH} pages or
 * {@see MAX_BYTES} bytes, the stack starts again from the first page, which is then where "previous"
 * leads.
 */
final class RunCursorStack
{
    public const MAX_DEPTH = 50;

    /**
     * The longest cursor handed out, so that a URL carrying it stays under the 8 KB request line of
     * nginx and Apache. A Temporal page token is ~130 bytes (measured on 1.20 and 1.32), ~230 once
     * stacked: this is about sixteen pages of way back there, the full fifty on the SQL catalogs.
     */
    public const MAX_BYTES = 4096;

    private const PREFIX = 'r1.';

    /**
     * The catalog's own cursor, `null` for the first page, and the stack behind it. A cursor that
     * does not decode is the first page, as a mistyped URL should be.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    public static function open(?string $cursor): array
    {
        if (null === $cursor || !str_starts_with($cursor, self::PREFIX)) {
            return [null === $cursor || '' === $cursor ? null : $cursor, []];
        }

        $json = base64_decode(strtr(substr($cursor, \strlen(self::PREFIX)), '-_', '+/'), true);
        $state = false === $json ? null : json_decode($json, true);
        if (!\is_array($state) || !\is_array($state['s'] ?? null) || !array_is_list($state['s'])) {
            return [null, []];
        }
        $stack = array_values(array_filter($state['s'], \is_string(...)));
        $current = \is_string($state['c'] ?? null) && '' !== $state['c'] ? $state['c'] : null;

        return [$current, $stack];
    }

    /**
     * The catalog's page, with the cursors that lead on and back from it.
     *
     * @param string|null  $current the catalog's cursor this page was listed from
     * @param list<string> $stack   the cursors behind it, as {@see open()} returned them
     */
    public static function page(WorkflowRunPage $page, ?string $current, array $stack): WorkflowRunPage
    {
        $behind = [...$stack, $current ?? ''];
        if (\count($behind) > self::MAX_DEPTH) {
            $behind = [''];
        }
        $next = null === $page->nextCursor ? null : self::encode($behind, $page->nextCursor);
        if (null !== $next && \strlen($next) > self::MAX_BYTES) {
            $next = self::encode([''], $page->nextCursor);
        }

        return new WorkflowRunPage(
            $page->runs,
            $next,
            $page->tellsWaitingForWorker,
            [] === $stack ? null : self::encode(\array_slice($stack, 0, -1), $stack[array_key_last($stack)]),
        );
    }

    /** @param list<string> $stack */
    private static function encode(array $stack, string $current): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(json_encode(['s' => $stack, 'c' => $current], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
