<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Observer;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow;
use Gplanchat\Durable\ExecutionId;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * A placed order starts a durable execution through `dispatchNewWorkflowRun()`.
 *
 * With a cluster configured, the execution starts there and the workers carry it, including if this
 * very request dies on the next line (OST003: the customer has paid, the process stops, nobody
 * picks it up). Without one, the dispatch runs the workflow in this request, up to `budgetSeconds`,
 * and the request waits for it: a probe convenience, not a production setup.
 *
 * It catches every `\Throwable`: a placed order stays placed. A workflow that fails does not throw
 * from the dispatch, and a start error (an undeclared workflow) is caught here and traced. A
 * workflow that does not start is an operational incident, not a reason to refuse the sale, and
 * refusing it would not give the money back.
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
            $this->runtimeFactory->resumeDispatcher()->dispatchNewWorkflowRun(
                ExecutionId::fromString($executionId),
                SlowOrderWorkflow::class,
                ['orderId' => $increment, 'pauseSeconds' => 2],
            );
            $this->trace(sprintf('%s -> execution %s started', $increment, $executionId));
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
