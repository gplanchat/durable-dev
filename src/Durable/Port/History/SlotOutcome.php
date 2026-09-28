<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * A slot's recorded outcome — activity, Nexus operation, side effect: the value the workflow
 * observes, or the failure it receives (#325).
 *
 * Its existence is the answer to "was anything recorded here"; `result` may legitimately be
 * `null`, which is why the lookups return `?SlotOutcome` and never a bare value.
 */
final readonly class SlotOutcome
{
    public function __construct(
        public mixed $result = null,
        public ?\Throwable $failed = null,
    ) {}
}
