<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Observer;

use Gplanchat\Durable\Magento\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Une commande passée démarre une exécution durable — sur la grappe, pas dans cette requête.
 *
 * C'est le point tout entier du §5.2. Démarrer le workflow **ici** le ferait mourir avec la
 * requête HTTP qui a passé la commande, ce qui est exactement la panne qu'OST003 décrit : le
 * client a payé, le processus s'arrête, personne ne reprend. `startAsync()` confie l'exécution au
 * cluster, et les workers la mènent — y compris si cette requête-ci meurt à la ligne suivante.
 *
 * Il ne lève jamais : une commande passée reste passée. Un workflow qui ne démarre pas est un
 * incident d'exploitation, pas une raison de refuser la vente au client — et le refuser ne
 * rendrait pas l'argent.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class StartOrderWorkflow implements ObserverInterface
{
    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
        private readonly DirectoryList $directories,
        private readonly File $filesystem,
    ) {}

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $increment = \is_object($order) && method_exists($order, 'getIncrementId')
            ? (string) $order->getIncrementId()
            : '';

        if ($increment === '') {
            return;
        }

        $executionId = 'order-' . $increment;

        try {
            $this->runtimeFactory->workflowClient()->startAsync(
                SlowOrderWorkflow::class,
                ['orderId' => $increment, 'pauseSeconds' => 2],
                $executionId,
            );
            $this->trace(sprintf('%s -> exécution %s démarrée sur la grappe', $increment, $executionId));
        } catch (\Throwable $exception) {
            $this->trace(sprintf('%s -> AUCUNE exécution : %s', $increment, $exception->getMessage()));
        }
    }

    private function trace(string $line): void
    {
        $this->filesystem->filePutContents(
            $this->directories->getPath(DirectoryList::LOG) . '/durable-orders.log',
            date('H:i:s') . ' ' . $line . "\n",
            FILE_APPEND,
        );
    }
}
