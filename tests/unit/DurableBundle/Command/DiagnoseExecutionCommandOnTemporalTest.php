<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * On Temporal native the metadata store is in-memory, per process: an empty row there says nothing
 * about the id, and the command must not send the operator after an "unknown id" (#337, B-12).
 */
final class DiagnoseExecutionCommandOnTemporalTest extends TestCase
{
    public function testOnTemporalAnEmptyRowSaysWhereTheMetadataLives(): void
    {
        $display = $this->diagnose(metadataIsProcessLocal: true);

        self::assertStringNotContainsString('unknown id', $display);
        self::assertStringContainsString('lives in the Temporal cluster', $display);
    }

    public function testOnAJournalBackendAnEmptyRowStillMeansTheIdIsUnknownHere(): void
    {
        self::assertStringContainsString('unknown id', $this->diagnose(metadataIsProcessLocal: false));
    }

    private function diagnose(bool $metadataIsProcessLocal): string
    {
        $tester = new CommandTester(new DiagnoseExecutionCommand(
            new InMemoryWorkflowMetadataStore(),
            new InMemoryEventStore(),
            new InMemoryChildWorkflowParentLinkStore(),
            null,
            new KeyPatternPayloadRedactor(),
            $metadataIsProcessLocal,
        ));
        $tester->execute(['executionId' => 'exec-1']);

        // The console wraps long lines: compare the words, not the layout.
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
