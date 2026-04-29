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
     * Directly adjusts source item qty (physical stock).
     * Salable qty updates automatically: salable = source_qty - sum(reservations).
     *
     * Positive qty = return to stock; negative qty = deduct from stock.
     * Used when replacing a configurable child product during order edit (Configure action).
     *
     * When $order is provided AND $qty is negative (deduct case), also creates
     * paired reservations for the new SKU mirroring the lifecycle of the
     * original order item: ORDER_PLACED -|qty| + SHIPMENT_CREATED +|qty|
     * (net = 0). Without these, downstream operations (cancel shipment, void,
     * refund) misbehave for the swapped-in SKU because MSI has no record of
     * the virtual order_placed event for it. See REFACTORING_ROADMAP.md §4.2.3.
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

        // Pair reservations for the new SKU on deduct (Configure swap on shipped item).
        if ($order !== null && $qty < 0) {
            $this->placePairedReservationsForSwappedSku($order, $sku, abs($qty), $websiteId);
        }
    }

    /**
     * Create ORDER_PLACED -qty + SHIPMENT_CREATED +qty reservations for a SKU
     * that was swapped in during a Configure operation on an already-shipped
     * order item. Net effect on salable qty is zero (the source_item update
     * above is what actually moves the needle), but the reservation records
     * give MSI a complete lifecycle to reason about during cancel/void/refund.
     *
     * Errors are caught and ignored — failing to create reservations should
     * not abort the order edit, since the source_item has already been
     * updated (the user-visible part is correct).
     */
    private function placePairedReservationsForSwappedSku(
        OrderInterface $order,
        string $sku,
        float $absQty,
        int $websiteId
    ): void {
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

            // ORDER_PLACED: -qty
            $orderPlacedEvent = $this->salesEventFactory->create([
                'type'       => SalesEventInterface::EVENT_ORDER_PLACED,
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string) $order->getEntityId(),
            ]);
            $orderPlacedEvent->setExtensionAttributes($extension);
            $this->placeReservationsForSalesEvent->execute(
                [$this->itemsToSellFactory->create(['sku' => $sku, 'qty' => -$absQty])],
                $salesChannel,
                $orderPlacedEvent
            );

            // SHIPMENT_CREATED: +qty (compensation for the deduct above)
            $shipmentEvent = $this->salesEventFactory->create([
                'type'       => 'shipment_created',
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string) $order->getEntityId(),
            ]);
            $shipmentEvent->setExtensionAttributes($extension);
            $this->placeReservationsForSalesEvent->execute(
                [$this->itemsToSellFactory->create(['sku' => $sku, 'qty' => $absQty])],
                $salesChannel,
                $shipmentEvent
            );
        } catch (\Throwable $e) {
            // Non-fatal: source_item adjustment above is what users see.
            // Reservations matter only for downstream cancel/void/refund.
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
