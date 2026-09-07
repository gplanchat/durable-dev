<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Queue;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Handler of the `gplanchat.durable.probe` probe topic.
 *
 * It does only one useful thing: **drag on**. The message says for how long,
 * and the trace says when it started and whether it finished. A consumer killed
 * between the two lines leaves an open trace, and that is what §1.3 measures —
 * does the queue hand the message back to somebody else, does it put it in a
 * dead letter, or does it say nothing?
 *
 * Not `final`: the container instantiates it, so it generates an `Interceptor`
 * extending it. That is the host constraint the design found by trying.
 */
class ProbeHandler
{
    public function __construct(
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    /**
     * @param string $payload `<label>:<seconds to hold>`
     */
    public function process(string $payload): void
    {
        [$label, $seconds] = array_pad(explode(':', $payload, 2), 2, '0');

        $this->trace(sprintf('%s START   pid=%d holds=%ds', $label, getmypid(), (int) $seconds));
        sleep((int) $seconds);
        $this->trace(sprintf('%s END     pid=%d', $label, getmypid()));
    }

    private function trace(string $line): void
    {
        $this->filesystem->filePutContents(
            $this->directories->getPath(DirectoryList::LOG) . '/durable-probe.log',
            date('H:i:s') . ' ' . $line . "\n",
            FILE_APPEND,
        );
    }
}
