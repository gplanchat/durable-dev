<?php

declare(strict_types=1);

/*
 * Sonde — le §5.2 : une commande passée déclenche une exécution durable.
 *
 * Elle fabrique ce qu'il faut pour qu'une commande puisse exister — un produit simple, un panier
 * invité, une adresse, un mode de livraison et un mode de paiement — puis la passe. Ce qui est
 * mesuré n'est pas le tunnel de commande : c'est que `sales_order_place_after`, l'événement que
 * `Magento\Sales\Model\Order::place()` émet pour de vrai, démarre une exécution **sur la grappe**.
 * Le même événement part du tunnel, de l'API REST et de l'admin.
 *
 *   php probe-order.php
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$sku = 'durable-probe-sku';

$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

try {
    $product = $productRepository->get($sku, true);
    echo "produit  : $sku (déjà là)\n";
} catch (\Magento\Framework\Exception\NoSuchEntityException) {
    /** @var \Magento\Catalog\Model\Product $product */
    $product = $om->create(\Magento\Catalog\Model\Product::class);
    $product->setSku($sku)
        ->setName('Durable probe article')
        ->setAttributeSetId(4)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setPrice(19.99)
        ->setStockData(['use_config_manage_stock' => 0, 'qty' => 1000, 'is_in_stock' => 1]);
    $product = $productRepository->save($product);
    echo "produit  : $sku (créé)\n";
}

// Sans site ni source d'inventaire, le produit existe et n'est pas *vendable* — et le message que
// Magento rend alors, « Product that you are trying to add is not available », ne dit ni lequel des
// deux manque ni qu'il en manque un. Mesuré.
$product->setWebsiteIds([1]);
$product->setStockData(['use_config_manage_stock' => 0, 'qty' => 1000, 'is_in_stock' => 1]);
$productRepository->save($product);

$sourceItems = $om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class);
$sourceItem = $om->create(\Magento\InventoryApi\Api\Data\SourceItemInterface::class);
$sourceItem->setSourceCode('default');
$sourceItem->setSku($sku);
$sourceItem->setQuantity(1000);
$sourceItem->setStatus(\Magento\InventoryApi\Api\Data\SourceItemInterface::STATUS_IN_STOCK);
$sourceItems->execute([$sourceItem]);
echo "stock    : 1000 sur la source par défaut\n";

$cartManagement = $om->get(\Magento\Quote\Api\GuestCartManagementInterface::class);
$cartRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$maskedId = $cartManagement->createEmptyCart();

$quoteIdMask = $om->create(\Magento\Quote\Model\QuoteIdMask::class)->load($maskedId, 'masked_id');
/** @var \Magento\Quote\Model\Quote $quote */
$quote = $cartRepository->get((int) $quoteIdMask->getQuoteId());
$quote->setStoreId(1);
$quote->addProduct($product, 1);

$address = [
    'firstname' => 'Durable', 'lastname' => 'Probe', 'street' => ['1 rue du Journal'],
    'city' => 'Lyon', 'country_id' => 'FR', 'postcode' => '69000',
    'telephone' => '0102030405', 'email' => 'probe@example.com',
];
$quote->getBillingAddress()->addData($address);
$shipping = $quote->getShippingAddress()->addData($address);
$shipping->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');

$quote->setCheckoutMethod(\Magento\Quote\Api\CartManagementInterface::METHOD_GUEST);
$quote->getPayment()->importData(['method' => 'checkmo']);
$quote->collectTotals();
$cartRepository->save($quote);

$orderId = $om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder((int) $quote->getId());
$order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get((int) $orderId);

printf("commande : %s (id %d)\n", $order->getIncrementId(), $orderId);
