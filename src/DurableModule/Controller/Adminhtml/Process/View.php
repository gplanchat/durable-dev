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
        /*
         * ⚠ `Magento\Framework\View\Result\PageFactory` ne rend pas une page de framework dans
         * l'aire d'administration : `module-backend/etc/adminhtml/di.xml` lui passe
         * `instanceName = Magento\Backend\Model\View\Result\Page`, et c'est cette page-là qui
         * porte `setActiveMenu()`. L'annotation dit à l'analyse ce que le conteneur fait, plutôt
         * que de faire taire l'erreur : c'est vérifiable dans le `di.xml` cité.
         */
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Gplanchat_DurableModule::process_history');
        // `Title::prepend()` déclare `string` ; `__()` rend une `Phrase`. Le rendu a lieu ici de
        // toute façon — le titre part dans la page — donc la conversion ne coûte aucune traduction
        // tardive et respecte le contrat écrit.
        $page->getConfig()->getTitle()->prepend(
            (string) __('Execution %1', (string) $this->getRequest()->getParam('run_id')),
        );

        return $page;
    }
}
