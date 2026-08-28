<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Queue;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Gestionnaire du sujet de sonde `gplanchat.durable.probe`.
 *
 * Il ne fait qu'une chose utile : **traîner**. Le message dit combien de temps,
 * et la trace dit quand il a commencé et s'il a fini. Un consommateur tué entre
 * les deux lignes laisse une trace ouverte, et c'est ce que le §1.3 mesure —
 * la file rend-elle le message à quelqu'un d'autre, le met-elle en lettre
 * morte, ou se tait-elle ?
 *
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui
 * l'étend. C'est la contrainte d'hôte que le design a trouvée en essayant.
 */
class ProbeHandler
{
    public function __construct(
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    /**
     * @param string $payload `<étiquette>:<secondes à tenir>`
     */
    public function process(string $payload): void
    {
        [$label, $seconds] = array_pad(explode(':', $payload, 2), 2, '0');

        $this->trace(sprintf('%s DÉBUT   pid=%d tient=%ds', $label, getmypid(), (int) $seconds));
        sleep((int) $seconds);
        $this->trace(sprintf('%s FIN     pid=%d', $label, getmypid()));
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
