<?php

declare(strict_types=1);

use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Rector\Rector\ActivityContractAttributesRector;
use Gplanchat\Durable\Rector\Rector\UnmigratableTemporalCallRector;
use Gplanchat\Durable\Rector\Rector\WorkflowClassAttributesRector;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * The attribute and exception half of a migration off the official Temporal PHP SDK.
 *
 * What it does not do is the execution model: `yield` is still `yield`, and `Workflow::` is still a
 * static call after this set has run. Those need a receiver the source class does not have, and a
 * return type the SDK could not declare — see the README.
 *
 * What it does instead is say so: `UnmigratableTemporalCallRector` comments every call this
 * migration cannot make, so the answer to "can we migrate at all" arrives before anybody rewrites
 * a line.
 */
return RectorConfig::configure()
    ->withRules([
        ActivityContractAttributesRector::class,
        WorkflowClassAttributesRector::class,
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
