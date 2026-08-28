<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * L'implémentation qui laisse une trace, pour que « la carte n'est pas débitée deux fois » soit
 * une mesure et non une croyance.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class RecordingOrderActivities implements SlowOrderActivities
{
    public const CHARGES_LOG = 'durable-charges.log';

    public function __construct(
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    public function charge(string $orderId): string
    {
        $this->filesystem->filePutContents(
            $this->directories->getPath(DirectoryList::LOG) . '/' . self::CHARGES_LOG,
            sprintf("%s %s pid=%d\n", date('H:i:s'), $orderId, getmypid()),
            FILE_APPEND,
        );

        return 'charge:' . $orderId;
    }

    public function reserveStock(string $orderId, int $pauseSeconds): string
    {
        // La fenêtre pendant laquelle on tue le processus. La carte est déjà débitée, le stock ne
        // l'est pas : c'est exactement l'instant qu'OST003 décrit.
        sleep($pauseSeconds);

        return 'reserve:' . $orderId;
    }

    public function notifyCustomer(string $receipt): string
    {
        return 'notify:' . $receipt;
    }
}
