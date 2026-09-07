<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Controller\Adminhtml\Process;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * An execution's history — what a grid row cannot hold.
 *
 * Read-only like the listing, and for the same reason: resuming from a browser would bypass the
 * per-execution lock.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Gplanchat_DurableModule::process_history';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /*
         * ⚠ `Magento\Framework\View\Result\PageFactory` does not return a framework page in the
         * admin area: `module-backend/etc/adminhtml/di.xml` passes it
         * `instanceName = Magento\Backend\Model\View\Result\Page`, and that is the page which
         * carries `setActiveMenu()`. The annotation tells static analysis what the container does,
         * rather than silencing the error: it is verifiable in the `di.xml` cited.
         */
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Gplanchat_DurableModule::process_history');
        // `Title::prepend()` declares `string`; `__()` returns a `Phrase`. Rendering happens here
        // anyway — the title goes into the page — so the conversion costs no late translation and
        // honours the written contract.
        $page->getConfig()->getTitle()->prepend(
            (string) __('Execution %1', (string) $this->getRequest()->getParam('run_id')),
        );

        return $page;
    }
}
