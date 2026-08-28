<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Controller\Adminhtml\Process;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * L'historique d'une exécution — ce qu'une ligne de grille ne peut pas tenir.
 *
 * En lecture seule comme la liste, et pour la même raison : reprendre depuis un navigateur
 * contournerait le verrou par exécution.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
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
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Gplanchat_DurableModule::process_history');
        $page->getConfig()->getTitle()->prepend(
            __('Execution %1', (string) $this->getRequest()->getParam('run_id')),
        );

        return $page;
    }
}
