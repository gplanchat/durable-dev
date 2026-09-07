<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Controller\Adminhtml\Process;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * `System > Durable processes > Process history`.
 *
 * The screen is read-only and will stay that way: what an operator comes here for is to know
 * whether an order went through, not to re-run it by hand. Resuming an execution from a browser
 * would bypass the per-execution lock — which §1.5 showed to cost two handlers running in
 * parallel on one message.
 *
 * The namespace follows PSR-4, and that is not a detail: Magento resolves an action **by
 * convention from the module name** — `ActionList::get()` composes `Gplanchat_DurableModule` +
 * `\Controller\Adminhtml\…`. As long as the module name and the package's PSR-4 root agree,
 * there is nothing more to declare.
 *
 * They did not agree: the module was called `Gplanchat_Durable` and the package autoloaded under
 * `Gplanchat\DurableModule\`, which forced a **second** `psr-4` entry for that one directory. The
 * symptom was misleading — route declared, menu rendered, and a 404 served inside the admin
 * chrome. The cause was not Magento, it was two names that did not agree; making them agree made
 * it disappear.
 *
 * ⚠ `HttpGetActionInterface` is not decorative: since 2.3, the router **ignores** an action that
 * implements none of the verb interfaces, and Magento serves its 404 inside the admin chrome —
 * menu included. So the symptom looks like a badly declared route, when the declaration is right
 * and it is the class that is missing a marker.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class History extends Action implements HttpGetActionInterface
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
        // `Title::prepend()` declares `string`; `__()` returns a `Phrase`, rendered here anyway.
        $page->getConfig()->getTitle()->prepend((string) __('Process history'));

        return $page;
    }
}
