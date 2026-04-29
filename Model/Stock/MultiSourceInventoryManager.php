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
use Magento\Sales\Api\Data\OrderInterface;
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
     * Two semantically different modes:
     *
     * 1. **Legacy mode** (`$order === null`) — direct source_item adjustment.
     *    Positive qty = return to stock; negative = deduct. Used when there
     *    is no order context (rare). Salable updates automatically.
     *
     * 2. **Configure-swap mode** (`$order !== null`) — reservation-only swap
     *    of the *pending* portion of an order item between the old and new
     *    child SKU. Caller is responsible for passing only the pending qty
     *    (qty_ordered − qty_shipped). The shipped portion is already
     *    physically with the customer under the old SKU and must NOT be
     *    touched in inventory — touching it creates phantom inflation /
     *    deflation. See REFACTORING_ROADMAP.md §4.2.6.
     *
     *    In this mode `inventory_source_item` is **not** modified — there
     *    is no physical event yet, only a logical swap of which SKU the
     *    pending qty is reserved against:
     *      - Positive qty (release old SKU) → ORDER_CANCELED reservation
     *        compensating the original order_placed.
     *      - Negative qty (reserve new SKU) → ORDER_PLACED reservation.
     *    A real shipment_created reservation will be added later by the
     *    standard MSI flow when the user creates a shipment for the new SKU.
     */
    public function registerReturnByProductId(
        int $productId,
        float $qty,
        int $websiteId,
        ?OrderInterface $order = null
    ): void {
        $productSkus = $this->getSkusByProductIds->execute([$productId]);
        $sku = $productSkus[$productId] ?? null;
        if (!$sku) {
            return;
        }

        if ($order !== null) {
            // Configure-swap mode: reservation-only, no source_item touch.
            $this->placeConfigureSwapReservation($order, $sku, $qty, $websiteId);
            return;
        }

        // Legacy mode: direct source_item adjust.
        $sourceItems = $this->getSourceItemsBySku->execute($sku);
        if (empty($sourceItems)) {
            return;
        }
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
     * Create a single reservation reflecting one side of a Configure swap on
     * the pending portion of an order item:
     *  - $qty < 0  → ORDER_PLACED (reserves the new SKU's pending portion)
     *  - $qty > 0  → ORDER_CANCELED (releases the old SKU's pending portion)
     *
     * Errors are caught and ignored — Configure swap must not abort over an
     * MSI hiccup; salable qty drift becomes visible at most for one SKU.
     */
    private function placeConfigureSwapReservation(
        OrderInterface $order,
        string $sku,
        float $qty,
        int $websiteId
    ): void {
        if (abs($qty) < 0.00001) {
            return;
        }

        try {
            $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
            $salesChannel = $this->salesChannelFactory->create([
                'data' => [
                    'type' => SalesChannelInterface::TYPE_WEBSITE,
                    'code' => $websiteCode,
                ],
            ]);

            $extension = $this->salesEventExtensionFactory->create([
                'data' => [
                    'objectIncrementId' => (string) $order->getIncrementId(),
                ],
            ]);

            // Negative qty = reserve new SKU under ORDER_PLACED.
            // Positive qty = release old SKU under ORDER_CANCELED.
            $eventType = $qty < 0
                ? SalesEventInterface::EVENT_ORDER_PLACED
                : 'order_canceled';

            $salesEvent = $this->salesEventFactory->create([
                'type'       => $eventType,
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string) $order->getEntityId(),
            ]);
            $salesEvent->setExtensionAttributes($extension);

            $this->placeReservationsForSalesEvent->execute(
                [$this->itemsToSellFactory->create(['sku' => $sku, 'qty' => $qty])],
                $salesChannel,
                $salesEvent
            );
        } catch (\Throwable $e) {
            // Non-fatal: Configure swap proceeds; missing reservation may show
            // as small salable drift on this SKU only. Logged elsewhere by stock service.
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
