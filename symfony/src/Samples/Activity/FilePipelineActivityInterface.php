<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface FilePipelineActivityInterface
{
    #[AsActivityMethod('samples_download')]
    public function download(string $sourceUrl): string;

    #[AsActivityMethod('samples_process')]
    public function process(string $filename): string;

    #[AsActivityMethod('samples_upload')]
    public function upload(string $processed, string $destinationUrl): string;
}
