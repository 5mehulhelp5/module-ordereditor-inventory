<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditor\Api\StockManagerInterface;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;

class MultiSourceInventoryManager implements StockManagerInterface
{
    public function __construct(
        private StockQtyManagerInterface $stockQtyManager,
        private GetSkusByProductIdsInterface $getSkusByProductIds,
        private ItemToSellInterfaceFactory $itemsToSellFactory,
        private PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent,
        private SalesEventInterfaceFactory $salesEventFactory,
        private SalesEventExtensionFactory $salesEventExtensionFactory,
        private SalesChannelInterfaceFactory $salesChannelFactory,
        private WebsiteRepositoryInterface $websiteRepository,
        private GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private SourceItemsSaveInterface $sourceItemsSave
    ) {
    }

    /**
     * @inheritDoc
     */
    public function registerReturn(OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->returnQtyToStock($item, $qty);
    }

    /**
     * @inheritDoc
     */
    /**
     * Register stock adjustment by product ID via MSI reservations.
     *
     * Positive qty = return to stock (compensation reservation).
     * Negative qty = deduct from stock (sale reservation).
     * Used when replacing a configurable child product during order edit.
     */
    /**
     * Register stock adjustment by product ID.
     *
     * Directly adjusts source item qty (physical stock).
     * Salable qty updates automatically: salable = source_qty - sum(reservations).
     *
     * Positive qty = return to stock; negative qty = deduct from stock.
     * Used when replacing a configurable child product during order edit (Configure action).
     */
    public function registerReturnByProductId(int $productId, float $qty, int $websiteId): void
    {
        $productSkus = $this->getSkusByProductIds->execute([$productId]);
        $sku = $productSkus[$productId] ?? null;
        if (!$sku) {
            return;
        }

        $sourceItems = $this->getSourceItemsBySku->execute($sku);
        if (empty($sourceItems)) {
            return;
        }

        // Adjust the first enabled source item.
        // For multi-source setups the correct approach is to identify the shipment source,
        // but for order editing single-source is the common case.
        foreach ($sourceItems as $sourceItem) {
            if ((int) $sourceItem->getStatus() !== 1) {
                continue;
            }
            $newQty = $sourceItem->getQuantity() + $qty;
            $sourceItem->setQuantity(max($newQty, 0.0));
            $this->sourceItemsSave->execute([$sourceItem]);
            break;
        }
    }

    /**
     * @inheritDoc
     */
    public function registerSale(OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->deductQtyFromStock($item, $qty);
    }
}
