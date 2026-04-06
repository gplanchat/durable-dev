<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: FilePipelineActivityInterface::class)]
final class FilePipelineActivityHandler implements FilePipelineActivityInterface
{
    public function download(string $sourceUrl): string
    {
        return 'file-'.\basename(\parse_url($sourceUrl, PHP_URL_PATH) ?: 'blob.bin');
    }

    public function process(string $filename): string
    {
        return 'processed-'.$filename;
    }

    public function upload(string $processed, string $destinationUrl): string
    {
        return 'OK:'.$processed.'→'.\basename(\parse_url($destinationUrl, PHP_URL_PATH) ?: 'out.bin');
    }
}
