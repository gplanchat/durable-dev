<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Observer;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * A placed order starts a durable execution — on the cluster, not in this request.
 *
 * That is the whole point of §5.2. Starting the workflow **here** would make it die with the HTTP
 * request that placed the order, which is exactly the failure OST003 describes: the customer has
 * paid, the process stops, nobody picks it up. `startAsync()` hands the execution to the cluster,
 * and the workers carry it — including if this very request dies on the next line.
 *
 * It never throws: a placed order stays placed. A workflow that does not start is an operational
 * incident, not a reason to refuse the sale to the customer — and refusing it would not give the
 * money back.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
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
            $this->trace(sprintf('%s -> execution %s started on the cluster', $increment, $executionId));
        } catch (\Throwable $exception) {
            $this->trace(sprintf('%s -> NO execution: %s', $increment, $exception->getMessage()));
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
