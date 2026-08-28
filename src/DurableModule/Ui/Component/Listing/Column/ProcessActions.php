<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * La colonne qui mène au détail d'une exécution.
 *
 * Une grille qui ne mène nulle part ne dit que ce qu'une ligne tient, et une ligne ne tient pas un
 * historique.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        // ⚠ Pas `?? []` ici : `foreach` sur un temporaire prend une référence qui ne mène nulle
        // part, et la colonne rend alors des cellules vides sans lever. Mesuré.
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (($item['run_id'] ?? '') === '') {
                continue;
            }

            $item[$this->getData('name')]['view'] = [
                'href' => $this->urlBuilder->getUrl('durable/process/view', ['run_id' => $item['run_id']]),
                'label' => __('History'),
            ];
        }

        return $dataSource;
    }
}
