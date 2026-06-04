<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validation\ValidationException;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentExtension;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditor\Model\StockDebugLogger;
use MageWorx\OrderEditorInventory\Api\CancelShipmentProcessorInterface;
use Psr\Log\LoggerInterface;

class CancelShipmentProcessor implements CancelShipmentProcessorInterface
{
    /**
     * @var SalesEventInterfaceFactory
     */
    private $salesEventFactory;

    /**
     * @var ItemToSellInterfaceFactory
     */
    private $itemsToSellFactory;

    /**
     * @var PlaceReservationsForSalesEventInterface
     */
    private $placeReservationsForSalesEvent;

    /**
     * @var SalesEventExtensionFactory;
     */
    private $salesEventExtensionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderItemRepositoryInterface
     */
    private $orderItemRepository;

    /**
     * @var SourceItemsSaveInterface
     */
    private $sourceItemsSave;

    /**
     * @var GetSourceItemBySourceCodeAndSku
     */
    private $getSourceItemBySourceCodeAndSku;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SalesChannelInterfaceFactory
     */
    private $salesChannelFactory;

    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;

    /**
     * @var StockDebugLogger
     */
    private $stockDebugLogger;

    /**
     * CancelShipmentProcessor constructor.
     *
     * @param SalesEventInterfaceFactory $salesEventFactory
     * @param ItemToSellInterfaceFactory $itemsToSellFactory
     * @param PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent
     * @param SalesEventExtensionFactory $salesEventExtensionFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SourceItemsSaveInterface $sourceItemsSave
     * @param GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku
     * @param SalesChannelInterfaceFactory $salesChannelFactory
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param LoggerInterface $logger
     * @param StockDebugLogger $stockDebugLogger
     */
    public function __construct(
        SalesEventInterfaceFactory              $salesEventFactory,
        ItemToSellInterfaceFactory              $itemsToSellFactory,
        PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent,
        SalesEventExtensionFactory              $salesEventExtensionFactory,
        OrderItemRepositoryInterface            $orderItemRepository,
        OrderRepositoryInterface                $orderRepository,
        SourceItemsSaveInterface                $sourceItemsSave,
        GetSourceItemBySourceCodeAndSku         $getSourceItemBySourceCodeAndSku,
        SalesChannelInterfaceFactory            $salesChannelFactory,
        WebsiteRepositoryInterface              $websiteRepository,
        LoggerInterface                         $logger,
        StockDebugLogger                        $stockDebugLogger
    ) {
        $this->salesEventFactory               = $salesEventFactory;
        $this->itemsToSellFactory              = $itemsToSellFactory;
        $this->placeReservationsForSalesEvent  = $placeReservationsForSalesEvent;
        $this->salesEventExtensionFactory      = $salesEventExtensionFactory;
        $this->orderRepository                 = $orderRepository;
        $this->orderItemRepository             = $orderItemRepository;
        $this->sourceItemsSave                 = $sourceItemsSave;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->salesChannelFactory             = $salesChannelFactory;
        $this->websiteRepository               = $websiteRepository;
        $this->logger                          = $logger;
        $this->stockDebugLogger                = $stockDebugLogger;
    }

    /**
     * @inheritDoc
     */
    public function execute(ShipmentInterface $shipment): void
    {
        $this->stockDebugLogger->open('CancelShipmentProcessor::execute', [
            'shipment_id' => (int)$shipment->getEntityId(),
            'order_id'    => (int)$shipment->getOrderId(),
            'source_code' => $shipment->getExtensionAttributes()
                ? $shipment->getExtensionAttributes()->getSourceCode()
                : null,
        ]);

        $shipmentItems = $shipment->getAllItems();
        if (empty($shipmentItems)) {
            $this->stockDebugLogger->warn('no shipment items — nothing to return');
            $this->stockDebugLogger->close('skipped');
            return;
        }

        $itemToSell  = [];
        $sourceItems = [];

        /** @var ShipmentItem $shipmentItem */
        foreach ($shipmentItems as $shipmentItem) {
            $sourceCode = null;
            /**
             * @var ShipmentExtension|null $extensionAttributes
             */
            $extensionAttributes = $shipment->getExtensionAttributes();
            if ($extensionAttributes !== null) {
                $sourceCode = $extensionAttributes->getSourceCode();
            }
            if ($sourceCode === null) {
                // Source code is not set, we can't return items. Silent skip here
                // means deleted-shipment stock is never given back — log it loudly.
                $this->stockDebugLogger->warn('source_code is null — stock NOT returned for item', [
                    'order_item_id' => (int)$shipmentItem->getOrderItemId(),
                    'sku'           => $shipmentItem->getSku(),
                    'qty'           => (float)$shipmentItem->getQty(),
                ]);
                continue;
            }

            $orderItem = $this->orderItemRepository->get((int)$shipmentItem->getOrderItemId());

            // Return only the still-shippable qty — the amount the recreated
            // shipment will re-deduct — so the delete+recreate pair is stock-neutral.
            // The refunded/canceled portions are returned by the credit memo / cancel
            // flow, which stay their sole owners (avoids double return).
            //
            // Using the old shipment qty (or shipmentQty − refunded with the CUMULATIVE
            // refunded) breaks on a SECOND edit: the old shipment qty is already reduced
            // and qty_refunded is cumulative, so the math drifts. qtyToShip is derived
            // from the order item and is correct across any number of sequential edits.
            // Capped at the shipment qty (can't return more than this shipment moved).
            $qtyToShip = (float)$orderItem->getQtyOrdered()
                - (float)$orderItem->getQtyRefunded()
                - (float)$orderItem->getQtyCanceled();
            $backQty   = max(min($qtyToShip, (float)$shipmentItem->getQty()), 0.0);
            $sku       = $shipmentItem->getSku();

            $sourceItem = $this->getSourceItemBySourceCodeAndSku->execute($sourceCode, $sku);
            $qtyBefore  = (float)$sourceItem->getQuantity();
            $sourceItem->setQuantity($sourceItem->getQuantity() + $backQty);
            $sourceItems[] = $sourceItem;

            $this->stockDebugLogger->log('return shipment qty to source', [
                'sku'              => $sku,
                'source_code'      => $sourceCode,
                'shipment_qty'     => (float)$shipmentItem->getQty(),
                'qty_ordered'      => (float)$orderItem->getQtyOrdered(),
                'qty_refunded'     => (float)$orderItem->getQtyRefunded(),
                'qty_canceled'     => (float)$orderItem->getQtyCanceled(),
                'qty_to_ship'      => $qtyToShip,
                'back_qty'         => (float)$backQty,
                'source_before'    => $qtyBefore,
                'source_after'     => $qtyBefore + $backQty,
                'reservation_comp' => (float)-$backQty,
            ]);

            // Reservation compensation should be negative!
            $itemToSell[] = $this->itemsToSellFactory->create(
                [
                    'sku' => $sku,
                    'qty' => (float)-$backQty
                ]
            );
        }

        if (!empty($sourceItems)) {
            try {
                $this->sourceItemsSave->execute($sourceItems);
                try {
                    $this->placeReservationForCancelShipmentEvent($shipment, $itemToSell);
                } catch (LocalizedException $localizedException) {
                    $this->stockDebugLogger->warn('reservation compensation failed', [
                        'error' => $localizedException->getLogMessage(),
                    ]);
                    $this->logger->error($localizedException->getLogMessage());
                }
            } catch (CouldNotSaveException $e) {
                $this->stockDebugLogger->warn('source items save failed', ['error' => $e->getLogMessage()]);
                $this->logger->error($e->getLogMessage());
            } catch (InputException|ValidationException $e) {
                $this->stockDebugLogger->warn('source items save invalid', ['error' => $e->getLogMessage()]);
                $this->logger->notice($e->getLogMessage());
            }
        } else {
            $this->stockDebugLogger->warn('no source items collected — no stock returned for this shipment');
        }

        $this->stockDebugLogger->close('cancel shipment done');
    }

    /**
     * Add reservation compensation to the `inventory_reservation` table (correct salable qty)
     *
     * @param ShipmentInterface $shipment
     * @param array $itemToSell
     * @throws LocalizedException
     * @throws CouldNotSaveException
     * @throws InputException
     */
    private function placeReservationForCancelShipmentEvent(ShipmentInterface $shipment, array $itemToSell): void
    {
        $order        = $this->orderRepository->get($shipment->getOrderId());
        $salesChannel = $this->getSalesChannelForOrder($order);

        /** @var SalesEventExtensionInterface */
        $salesEventExtension = $this->salesEventExtensionFactory->create(
            [
                'data' => ['objectIncrementId' => (string)$order->getIncrementId()]
            ]
        );

        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create(
            [
                'type'       => 'shipment_cancelled',
                'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
                'objectId'   => (string)$order->getEntityId()
            ]
        );
        $salesEvent->setExtensionAttributes($salesEventExtension);

        $this->placeReservationsForSalesEvent->execute($itemToSell, $salesChannel, $salesEvent);
    }

    /**
     * @param OrderInterface $order
     * @return SalesChannelInterface
     * @throws NoSuchEntityException
     */
    private function getSalesChannelForOrder(OrderInterface $order): SalesChannelInterface
    {
        $websiteId   = (int)$order->getStore()->getWebsiteId();
        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();

        return $this->salesChannelFactory->create(
            [
                'data' => [
                    'type' => SalesChannelInterface::TYPE_WEBSITE,
                    'code' => $websiteCode
                ]
            ]
        );
    }
}
