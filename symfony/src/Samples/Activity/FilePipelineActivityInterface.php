<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface FilePipelineActivityInterface
{
    #[ActivityMethod('samples_download')]
    public function download(string $sourceUrl): string;

    #[ActivityMethod('samples_process')]
    public function process(string $filename): string;

    #[ActivityMethod('samples_upload')]
    public function upload(string $processed, string $destinationUrl): string;
}
