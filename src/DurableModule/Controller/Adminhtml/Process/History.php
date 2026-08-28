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
 * L'écran est en lecture seule et le restera : ce qu'un exploitant vient y chercher est de savoir
 * si une commande est passée, pas de la relancer à la main. Reprendre une exécution depuis un
 * navigateur contournerait le verrou par exécution — ce que la 1.5 a montré coûter deux
 * gestionnaires en parallèle sur un même message.
 *
 * L'espace de noms suit PSR-4, et ce n'est pas un détail : Magento résout une action **par
 * convention depuis le nom du module** — `ActionList::get()` compose `Gplanchat_DurableModule` +
 * `\Controller\Adminhtml\…`. Tant que le nom du module et la racine PSR-4 du paquet se
 * correspondent, il n'y a rien à déclarer de plus.
 *
 * Ils ne se correspondaient pas : le module s'appelait `Gplanchat_Durable` et le paquet
 * s'autochargeait sous `Gplanchat\DurableModule\`, ce qui obligeait à une **seconde** entrée
 * `psr-4` pour ce seul dossier. Le symptôme était trompeur — route déclarée, menu affiché, et un
 * 404 rendu dans le châssis d'admin. La cause n'était pas Magento, c'était deux noms qui ne
 * s'accordaient pas ; les accorder l'a fait disparaître.
 *
 * ⚠ `HttpGetActionInterface` n'est pas décoratif : depuis 2.3, le routeur **ignore** une action qui
 * n'implémente aucune des interfaces de verbe, et Magento rend son 404 dans le châssis d'admin —
 * menu compris. Le symptôme ressemble donc à une route mal déclarée, alors que la déclaration est
 * juste et que c'est la classe qui manque un marqueur.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
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
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Gplanchat_DurableModule::process_history');
        $page->getConfig()->getTitle()->prepend(__('Process history'));

        return $page;
    }
}
