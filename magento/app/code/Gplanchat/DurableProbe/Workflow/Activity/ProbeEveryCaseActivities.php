<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * L'implémentation des cas, avec un compteur sur disque.
 *
 * ⚠ **Le compteur ne peut pas vivre en mémoire.** Une reprise d'activité peut être servie par un
 * autre processus que la tentative qui a échoué — c'est le point même de Temporal — donc un champ
 * d'instance ferait échouer `flaky` indéfiniment sur un banc à deux workers, et réussir du premier
 * coup sur un banc à un seul. Un fichier par exécution donne le même scénario dans les deux cas.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProbeEveryCaseActivities implements EveryCaseActivities
{
    /** Deux échecs puis une réussite : assez pour voir la reprise, assez court pour ne pas attendre. */
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
     * Le rang de la tentative en cours, compté sur disque.
     */
    private function countAttempt(string $caseId): int
    {
        $path = $this->directories->getPath(DirectoryList::LOG)
            . '/durable-case-' . preg_replace('/[^A-Za-z0-9_-]/', '', $caseId) . '.log';

        $this->filesystem->filePutContents($path, "x\n", FILE_APPEND);

        return \substr_count((string) $this->filesystem->fileGetContents($path), "\n");
    }
}
