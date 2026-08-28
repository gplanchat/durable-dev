<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Controller\Adminhtml\Process;

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
 * ⚠⚠ **L'espace de noms de ce fichier n'est pas celui du reste du module, et ce n'est pas une
 * inadvertance.** Magento résout une action par **convention depuis le nom du module**, pas depuis
 * l'autochargement : `ActionList::get()` compose `Gplanchat_Durable` + `\Controller\Adminhtml\…`,
 * donc `Gplanchat\Durable\Controller\…`. Le paquet, lui, s'autocharge sous
 * `Gplanchat\Durable\Magento\` — c'est le prix des deux conventions que le design a choisies
 * (nom de paquet côté famille, nom de module côté Magento). Le `composer.json` du module ajoute donc
 * une seconde entrée `psr-4` pour ce seul dossier. Sans elle, la route est déclarée, le menu
 * s'affiche, et Magento rend un **404 dans le châssis d'admin** : tout a l'air juste sauf la page.
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
    public const ADMIN_RESOURCE = 'Gplanchat_Durable::process_history';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Gplanchat_Durable::process_history');
        $page->getConfig()->getTitle()->prepend(__('Process history'));

        return $page;
    }
}
