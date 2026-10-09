<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\MagentoBench\Fixture\CallsNexus;
use Gplanchat\Durable\MagentoBench\Fixture\GreeterActivities;
use Gplanchat\Durable\MagentoBench\Fixture\GreetThenWait;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\ObjectManagerInterface;

/** The factory the way `di.xml` builds it, with the bench's two workflows and its one activity handler declared. */
final class BenchRuntime
{
    public static function objectManager(): ObjectManagerInterface
    {
        return Bootstrap::create(BP, $_SERVER)->getObjectManager();
    }

    public static function factory(float $budgetSeconds = 30.0): RuntimeFactory
    {
        $objects = self::objectManager();

        return new RuntimeFactory(
            workflowClasses: [GreetThenWait::class, CallsNexus::class],
            activityHandlers: [new GreeterActivities()],
            deploymentConfig: $objects->get(DeploymentConfig::class),
            budgetSeconds: $budgetSeconds,
            journalConnection: $objects->get(JournalConnectionResolver::class),
        );
    }
}
