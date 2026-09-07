<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * The implementation of the cases, with a counter on disk.
 *
 * ⚠ **The counter cannot live in memory.** An activity retry can be served by a process other than
 * the attempt that failed — that is Temporal's very point — so an instance field would make
 * `flaky` fail forever on a two-worker bench, and succeed first time on a single-worker one. One
 * file per execution gives the same scenario in both cases.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class ProbeEveryCaseActivities implements EveryCaseActivities
{
    /** Two failures then a success: enough to see the retry, short enough not to wait. */
    private const ATTEMPTS_BEFORE_SUCCESS = 3;

    public function __construct(
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    public function succeed(string $caseId): string
    {
        return 'ok:' . $caseId;
    }

    public function flaky(string $caseId): string
    {
        $attempt = $this->countAttempt($caseId);
        if ($attempt < self::ATTEMPTS_BEFORE_SUCCESS) {
            throw new \RuntimeException(\sprintf(
                'the payment gateway did not answer (attempt %d of %d)',
                $attempt,
                self::ATTEMPTS_BEFORE_SUCCESS,
            ));
        }

        return \sprintf('recovered:%s after %d attempts', $caseId, $attempt);
    }

    public function doomed(string $caseId): string
    {
        throw new \DomainException('this order can never be shipped: ' . $caseId);
    }

    /**
     * The rank of the current attempt, counted on disk.
     */
    private function countAttempt(string $caseId): int
    {
        $path = $this->directories->getPath(DirectoryList::LOG)
            . '/durable-case-' . preg_replace('/[^A-Za-z0-9_-]/', '', $caseId) . '.log';

        $this->filesystem->filePutContents($path, "x\n", FILE_APPEND);

        return \substr_count((string) $this->filesystem->fileGetContents($path), "\n");
    }
}
