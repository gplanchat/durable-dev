<?php

declare(strict_types=1);

use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Rector\Rector\ActivityContractAttributesRector;
use Gplanchat\Durable\Rector\Rector\TemporalFacadeToEnvironmentRector;
use Gplanchat\Durable\Rector\Rector\UnmigratableTemporalCallRector;
use Gplanchat\Durable\Rector\Rector\WorkflowClassAttributesRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * The attribute and exception half of a migration off the official Temporal PHP SDK.
 *
 * The execution model comes with it: the static facade becomes an injected `WorkflowEnvironment`,
 * and `yield` goes, along with any `\Generator` return type it left behind. What is **not**
 * synthesised is a real return type — the SDK could not declare one, and inventing it here would be
 * a guess with a `TypeError` behind it.
 *
 * And whatever cannot be migrated is said out loud rather than left looking done:
 * `UnmigratableTemporalCallRector` comments every such call, so the answer to "can we migrate at
 * all" arrives before anybody rewrites a line.
 */
return RectorConfig::configure()
    ->withRules([
        ActivityContractAttributesRector::class,
        WorkflowClassAttributesRector::class,
        TemporalFacadeToEnvironmentRector::class,
        UnmigratableTemporalCallRector::class,
    ])
    ->withConfiguredRule(RenameClassRector::class, [
        // Only the failures with a Durable counterpart. `ApplicationFailure`, `ServerFailure`,
        // `TerminatedFailure` and `TimeoutFailure` are deliberately absent: mapping them onto a
        // neighbour would silently change which catch block wins.
        'Temporal\Exception\Failure\ActivityFailure' => DurableActivityFailedException::class,
        'Temporal\Exception\Failure\ChildWorkflowFailure' => DurableChildWorkflowFailedException::class,
        // WorkflowCancelledFailure, not WorkflowCancelledException: the second is what the engine
        // throws at its host to stop redelivering a resume, and no workflow catches it.
        'Temporal\Exception\Failure\CanceledFailure' => WorkflowCancelledFailure::class,
    ]);
