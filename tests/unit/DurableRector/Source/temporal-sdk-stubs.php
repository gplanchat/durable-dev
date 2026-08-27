<?php

declare(strict_types=1);

/*
 * Stubs of the four Temporal SDK attributes the rules match on, under the SDK's own namespace.
 *
 * Rector matches attributes by fully-qualified name, so the stubs have to carry the real names —
 * but `temporal/sdk` itself stays out of `composer.lock`, which is what DUR006 and the comparison
 * page both claim in public. Only the shape the rules read is reproduced.
 */

namespace Temporal\Workflow {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final class WorkflowInterface {}

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class WorkflowMethod
    {
        public function __construct(public readonly ?string $name = null) {}
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class SignalMethod
    {
        public function __construct(public readonly ?string $name = null) {}
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class QueryMethod
    {
        public function __construct(public readonly ?string $name = null) {}
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class UpdateMethod
    {
        public function __construct(public readonly ?string $name = null) {}
    }
}

namespace Temporal\Activity {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final class ActivityInterface
    {
        public function __construct(public readonly string $prefix = '') {}
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class ActivityMethod
    {
        public function __construct(public readonly ?string $name = null) {}
    }
}
