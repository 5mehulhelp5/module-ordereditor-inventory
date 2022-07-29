<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageWorx\OrderEditorInventory\Model;

use Magento\Framework\Exception\BulkException;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Api\Data\ProductSalabilityErrorInterface;
use Magento\InventorySalesApi\Api\IsProductSalableForRequestedQtyInterface;

class CheckItemsQuantity
{
    /**
     * @var IsProductSalableForRequestedQtyInterface
     */
    private $isProductSalableForRequestedQty;

    /**
     * @param IsProductSalableForRequestedQtyInterface $isProductSalableForRequestedQty
     */
    public function __construct(
        IsProductSalableForRequestedQtyInterface $isProductSalableForRequestedQty
    ) {
        $this->isProductSalableForRequestedQty = $isProductSalableForRequestedQty;
    }

    /**
     * Check whether all items salable
     *
     * @param array $items [['sku' => 'qty'], ...]
     * @param int $stockId
     * @return void
     * @throws LocalizedException
     */
    public function execute(array $items, int $stockId): void
    {
        $bulkException = new BulkException();

        foreach ($items as $sku => $qty) {
            $isSalable = $this->isProductSalableForRequestedQty->execute((string)$sku, $stockId, (float)$qty);
            if (false === $isSalable->isSalable()) {
                $errors = $isSalable->getErrors();
                /** @var ProductSalabilityErrorInterface $errorMessage */
                $errorMessage = array_pop($errors);

                $bulkException->addException(new LocalizedException(__($errorMessage->getMessage())));
            }
        }

        if ($bulkException->wasErrorAdded()) {
            throw $bulkException;
        }
    }
}
