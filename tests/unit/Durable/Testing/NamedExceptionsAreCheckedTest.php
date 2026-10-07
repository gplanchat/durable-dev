<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowScenarioConformanceTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class NamedExceptionsAreCheckedTest extends TestCase
{
    public function testAKeyThatMatchesNoTestFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('matches no test method');

        $this->scenarios(['testATypo' => 'broken, see #1'])->testAWorkflowStartsAndReturnsItsResult();
    }

    public function testAReasonWithoutAnIssueNumberFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must cite an issue number');

        $this->scenarios(['testAWorkflowStartsAndReturnsItsResult' => 'broken'])->testAWorkflowStartsAndReturnsItsResult();
    }

    /**
     * @param array<string, string> $exceptions
     */
    private function scenarios(array $exceptions): WorkflowScenarioConformanceTestCase
    {
        $scenarios = new class ('testAWorkflowStartsAndReturnsItsResult') extends WorkflowScenarioConformanceTestCase {
            /** @var array<string, string> */
            public array $exceptions = [];

            protected function createEventStore(): EventStoreInterface
            {
                return new InMemoryEventStore();
            }

            protected function namedExceptions(): array
            {
                return $this->exceptions;
            }
        };
        $scenarios->exceptions = $exceptions;

        return $scenarios;
    }
}
