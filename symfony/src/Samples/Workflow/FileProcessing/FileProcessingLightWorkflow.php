<?php

declare(strict_types=1);

namespace App\Samples\Workflow\FileProcessing;

use App\Samples\Activity\FilePipelineActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Chaîne download → process → upload (sans routage de file d’attente dynamique comme samples-php).
 */
#[Workflow('Samples_FileProcessing_Light')]
final class FileProcessingLightWorkflow
{
    private readonly ActivityStub $download;

    private readonly ActivityStub $process;

    private readonly ActivityStub $upload;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $opts = ActivityOptions::default()->withScheduleToCloseTimeoutSeconds(300.0);
        $this->download = $environment->activityStub(
            FilePipelineActivityInterface::class,
            $opts,
        );
        $this->process = $environment->activityStub(
            FilePipelineActivityInterface::class,
            $opts,
        );
        $this->upload = $environment->activityStub(
            FilePipelineActivityInterface::class,
            $opts,
        );
    }

    #[WorkflowMethod]
    public function run(
        string $sourceUrl = 'https://example.com/in/data.bin',
        string $destinationUrl = 'https://example.com/out/data.bin',
    ): string {
        $downloaded = $this->environment->await($this->download->download($sourceUrl));
        $processed = $this->environment->await($this->process->process($downloaded));

        return $this->environment->await($this->upload->upload($processed, $destinationUrl));
    }
}
