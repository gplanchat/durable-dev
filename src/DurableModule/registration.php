<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * Le paquet Composer s'appelle `gplanchat/durable-magento`, le module Magento
 * `Gplanchat_Durable`. Les deux conventions ne se croisent pas : Packagist veut
 * la famille en tête, `bin/magento module:status` veut le vendor Magento.
 */
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Gplanchat_Durable', __DIR__);
