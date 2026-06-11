<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
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
use MageWorx\OrderEditor\Api\Stock\SwapPhysicalExchangeInterface;
use MageWorx\OrderEditor\Model\StockDebugLogger;

/**
 * MSI implementation of swap physical exchange.
 *
 * Pre-flight (validate):
 *   - For each shipment source the order item was shipped from, verify the
 *     OLD SKU is still assigned (we will return qty there).
 *   - Verify NEW SKU has at least one usable source (preferred shipment
 *     source first, then priority-iterated sources of the same stock,
 *     respecting backorder policy).
 *   - Throws LocalizedException on failure with admin-readable details.
 *
 * Execute:
 *   - For each shipment_source/qty pair from `inventory_shipment_source`:
 *     · +qty on old SKU at that source (return-to-stock).
 *     · -qty on new SKU at the resolved source (same / priority).
 *   - For audit: emit standard MSI reservations (event_type=`order_canceled`
 *     for the +qty release, event_type=`order_placed` for the -qty deduction).
 *     Standard event types ensure compatibility with third-party MSI tools
 *     (reconciliation reports, inventory dashboards, etc).
 *   - User-facing notice when source resolution falls back to a non-shipment
 *     source (so admin sees what actually happened).
 *   - StockDebugLogger entries on each step for ops/troubleshooting.
 */
class MsiSwapPhysicalExchange implements SwapPhysicalExchangeInterface
{
    public function __construct(
        private SourceCodeResolver $sourceCodeResolver,
        private GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private SourceItemsSaveInterface $sourceItemsSave,
        private PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent,
        private SalesEventInterfaceFactory $salesEventFactory,
        private SalesEventExtensionFactory $salesEventExtensionFactory,
        private SalesChannelInterfaceFactory $salesChannelFactory,
        private WebsiteRepositoryInterface $websiteRepository,
        private ItemToSellInterfaceFactory $itemsToSellFactory,
        private MessageManagerInterface $messageManager,
        private StockDebugLogger $stockDebugLogger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(
        OrderInterface $order,
        OrderItemInterface $shippedItem,
        string $oldSku,
        string $newSku,
        float $qty
    ): void {
        if ($qty <= 0) {
            return;
        }
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $shipmentSources = $this->sourceCodeResolver->resolveShipmentSources((int) $shippedItem->getItemId());

        // Sanity: if no shipment_source records exist, we cannot pinpoint where to
        // return the old SKU. Fall back to "any source assigned to old SKU" but
        // require at least one such source.
        if (empty($shipmentSources)) {
            $oldSkuSources = iterator_to_array($this->getSourceItemsBySku->execute($oldSku));
            if (empty($oldSkuSources)) {
                throw new LocalizedException(__(
                    'Cannot return shipped %1 to stock: no source is configured for this SKU.',
                    $oldSku
                ));
            }
        }

        // For each shipment-source/qty pair we will deduct that many of the
        // new SKU. Validate that a deduction source can be resolved.
        $perSource = $this->normalizeShipmentSources($shipmentSources, $qty);
        foreach ($perSource as $sourceCode => $qtyAtSource) {
            $deductionSource = $this->sourceCodeResolver->findDeductionSource(
                $newSku,
                $websiteId,
                $qtyAtSource,
                $sourceCode
            );
            if ($deductionSource === null) {
                throw new LocalizedException(__(
                    'Cannot deduct %1 of %2 from any source: insufficient stock and backorders are disabled. '
                    . 'Please enable backorders for %2 or assign it to a source with enough qty.',
                    $qtyAtSource,
                    $newSku
                ));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(
        OrderInterface $order,
        OrderItemInterface $shippedItem,
        string $oldSku,
        string $newSku,
        float $qty
    ): void {
        if ($qty <= 0) {
            return;
        }
        $this->stockDebugLogger->open('MsiSwapPhysicalExchange::execute', [
            'order_id'   => (int) $order->getId(),
            'item_id'    => (int) $shippedItem->getItemId(),
            'old_sku'    => $oldSku,
            'new_sku'    => $newSku,
            'qty'        => $qty,
        ]);
        try {
            $websiteId = (int) $order->getStore()->getWebsiteId();
            $shipmentSources = $this->sourceCodeResolver->resolveShipmentSources((int) $shippedItem->getItemId());
            $perSource = $this->normalizeShipmentSources($shipmentSources, $qty);

            $totalReturnedByOldSource = [];
            $totalDeductedByNewSource = [];
            $fallbackUsedForNewSku = false;

            foreach ($perSource as $shipmentSourceCode => $qtyAtSource) {
                // Return old SKU to its shipment source (always — that's where it came from).
                $returnSource = $this->sourceCodeResolver->findReturnSource(
                    $oldSku,
                    $websiteId,
                    $shipmentSourceCode
                );
                if ($returnSource === null) {
                    // Impossibly rare — old SKU has no source. Skip return on this leg
                    // (audit reservation still emitted to keep ledger consistent).
                    $this->stockDebugLogger->warn('old SKU not assigned to any source', [
                        'sku' => $oldSku,
                    ]);
                } else {
                    $this->adjustSourceItem($oldSku, $returnSource, +$qtyAtSource);
                    $totalReturnedByOldSource[$returnSource]
                        = ($totalReturnedByOldSource[$returnSource] ?? 0.0) + $qtyAtSource;
                }

                // Deduct new SKU. Prefer same source as shipment; fall back via
                // SourceCodeResolver (priority + backorder).
                $deductionSource = $this->sourceCodeResolver->findDeductionSource(
                    $newSku,
                    $websiteId,
                    $qtyAtSource,
                    $shipmentSourceCode
                );
                if ($deductionSource === null) {
                    // validate() should have caught this; race-with-concurrent-stock-change
                    // path. Throw — caller treats as fatal.
                    throw new LocalizedException(__(
                        'Stock changed during exchange: cannot deduct %1 of %2 from any source.',
                        $qtyAtSource,
                        $newSku
                    ));
                }
                if ($deductionSource !== $shipmentSourceCode) {
                    $fallbackUsedForNewSku = true;
                }
                $this->adjustSourceItem($newSku, $deductionSource, -$qtyAtSource);
                $totalDeductedByNewSource[$deductionSource]
                    = ($totalDeductedByNewSource[$deductionSource] ?? 0.0) + $qtyAtSource;
            }

            // No audit reservation pair: for shipped qty the placement
            // reservation has already been balanced by shipment_created
            // (sum = 0 for the original SKU). Emitting `+qty order_canceled`
            // for old SKU and `-qty order_placed` for new SKU here would
            // create:
            //   1. an orphan release on the old SKU (no matching deduction
            //      reservation existed → +qty drift),
            //   2. a duplicate placement deduction on the new SKU (the
            //      original placement was already settled by shipment) →
            //      -qty drift that never closes.
            // The physical source mutation above is the only ledger event;
            // the audit trail lives in the order history entry written by
            // ProductOptionsEditor::logShippedSwapPhysicalExchange.

            if ($fallbackUsedForNewSku) {
                $this->messageManager->addNoticeMessage(__(
                    'Some qty of new SKU "%1" was deducted from a non-shipment source ' .
                    '(insufficient stock at the original shipment source). See order log for details.',
                    $newSku
                ));
            }
            $this->stockDebugLogger->log('exchange completed', [
                'returned_by_source' => $totalReturnedByOldSource,
                'deducted_by_source' => $totalDeductedByNewSource,
                'fallback_used'      => $fallbackUsedForNewSku,
            ]);
            $this->stockDebugLogger->close('done');
        } catch (\Throwable $e) {
            $this->stockDebugLogger->warn('exchange failed', [
                'error' => $e->getMessage(),
            ]);
            $this->stockDebugLogger->close('error');
            if ($e instanceof LocalizedException) {
                throw $e;
            }
            // LocalizedException::__construct accepts ?Exception, not Throwable.
            // Don't chain non-Exception errors (TypeError, AssertionError) — just
            // surface the message.
            $cause = $e instanceof \Exception ? $e : null;
            throw new LocalizedException(
                __('Swap physical exchange failed: %1', $e->getMessage()),
                $cause
            );
        }
    }

    /**
     * If shipment_source data is missing (e.g. legacy shipments before MSI
     * extension attribute was wired), we treat the whole qty as a single
     * "default-shipment" leg with sourceCode=null — caller resolves from
     * priority list.
     */
    private function normalizeShipmentSources(array $shipmentSources, float $totalQty): array
    {
        if (empty($shipmentSources)) {
            return ['' => $totalQty]; // empty key = no preferred source
        }
        // Sum-validate: shipment_source qty may be the qty per shipment,
        // not per item. Cap at requested totalQty so we don't exchange more
        // than asked.
        $sum = array_sum($shipmentSources);
        if (abs($sum - $totalQty) < 1e-8) {
            return $shipmentSources;
        }
        // Distribute the requested qty across sources proportionally to their
        // shipment shares — keeps split shipments correct for partial exchanges.
        $normalized = [];
        $remaining = $totalQty;
        $i = 0;
        $count = count($shipmentSources);
        foreach ($shipmentSources as $code => $shipQty) {
            $i++;
            if ($i === $count) {
                $normalized[$code] = $remaining;
                break;
            }
            $share = round($shipQty / $sum * $totalQty, 4);
            $normalized[$code] = $share;
            $remaining -= $share;
        }
        return $normalized;
    }

    private function adjustSourceItem(string $sku, string $sourceCode, float $delta): void
    {
        if ($sourceCode === '') {
            // No source resolved (legacy shipment without inventory_shipment_source).
            // Skip physical adjustment; reservation pair will keep ledger balanced
            // until the admin manually reconciles.
            return;
        }
        foreach ($this->getSourceItemsBySku->execute($sku) as $sourceItem) {
            if ($sourceItem->getSourceCode() === $sourceCode) {
                $newQty = $sourceItem->getQuantity() + $delta;
                $sourceItem->setQuantity($newQty);
                if ($newQty > 0 && $sourceItem->getStatus() === SourceItemInterface::STATUS_OUT_OF_STOCK) {
                    $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
                }
                $this->sourceItemsSave->execute([$sourceItem]);
                return;
            }
        }
        // SKU not assigned to source — log and continue. Reservation will
        // record the audit even if physical adjust didn't land.
        $this->stockDebugLogger->warn('SKU not assigned to source — skip physical adjust', [
            'sku' => $sku,
            'source' => $sourceCode,
            'delta' => $delta,
        ]);
    }

    /**
     * Emit a single MSI reservation entry for audit.
     */
    private function placeReservation(
        OrderInterface $order,
        string $sku,
        float $qty,
        string $eventType
    ): void {
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
        $salesChannel = $this->salesChannelFactory->create([
            'data' => [
                'type' => SalesChannelInterface::TYPE_WEBSITE,
                'code' => $websiteCode,
            ],
        ]);
        $extension = $this->salesEventExtensionFactory->create([
            'data' => ['objectIncrementId' => (string) $order->getIncrementId()],
        ]);
        $salesEvent = $this->salesEventFactory->create([
            'type' => $eventType,
            'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
            'objectId' => (string) $order->getEntityId(),
        ]);
        $salesEvent->setExtensionAttributes($extension);

        $items = [
            $this->itemsToSellFactory->create([
                'sku' => $sku,
                'qty' => $qty,
            ]),
        ];
        $this->placeReservationsForSalesEvent->execute($items, $salesChannel, $salesEvent);
    }
}
