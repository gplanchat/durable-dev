<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Profiler;

use Gplanchat\Durable\Bundle\Profiler\DurableProfilerEventPresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The panel named a message that no longer exists: `WorkflowRunMessage` became
 * `ResumeWorkflowMessage`, `WorkflowRunHandler` became `ResumeWorkflowHandler` (#337, B-6).
 */
final class TheProfilerNamesTheMessageItSeesTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function dispatches(): iterable
    {
        yield 'a resume' => [['kind' => 'dispatch', 'isResume' => true, 'workflowType' => '']];
        yield 'a new run' => [['kind' => 'dispatch', 'isResume' => false, 'workflowType' => 'App\\OrderWorkflow']];
        yield 'no type' => [['kind' => 'dispatch', 'isResume' => false, 'workflowType' => '']];
    }

    /**
     * @param array<string, mixed> $dispatch
     */
    #[DataProvider('dispatches')]
    public function testADispatchNamesTheMessageThatWasDispatched(array $dispatch): void
    {
        $shown = DurableProfilerEventPresentation::fromProcessTrace($dispatch);

        self::assertStringContainsString('ResumeWorkflowMessage', $shown['title'] . ' ' . $shown['subtitle']);
        self::assertStringNotContainsString('WorkflowRunMessage', $shown['title'] . ' ' . $shown['subtitle']);
    }

    public function testTheBundleNamesNoRenamedClass(): void
    {
        $stale = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../../src/DurableBundle', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (1 === preg_match('/\bWorkflowRun(Message|Handler)\b/', $line)) {
                    $stale[] = $file->getFilename() . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $stale);
    }
}
