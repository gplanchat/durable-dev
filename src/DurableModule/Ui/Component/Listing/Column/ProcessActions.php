<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The column that leads to an execution's detail.
 *
 * A grid that leads nowhere says only what a row holds, and a row does not hold a history.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
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
        // ⚠ No `?? []` here: `foreach` over a temporary takes a reference that leads nowhere,
        // and the column then renders empty cells without throwing. Measured.
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (($item['execution_id'] ?? '') === '') {
                continue;
            }

            $item[$this->getData('name')]['view'] = [
                // By the execution id (#514), in the query string: an execution id may hold a
                // slash, which a path segment would split. The page reads it with getParam().
                'href' => $this->urlBuilder->getUrl('durable/process/view', ['_query' => ['run_id' => $item['execution_id']]]),
                'label' => __('History'),
            ];
        }

        return $dataSource;
    }
}
