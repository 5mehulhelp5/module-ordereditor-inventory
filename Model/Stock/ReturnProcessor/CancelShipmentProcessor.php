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
use Magento\Framework\Validation\ValidationException;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;

class CancelShipmentProcessor implements \MageWorx\OrderEditorInventory\Api\CancelShipmentProcessorInterface
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
     * @param LoggerInterface $logger
     */
    public function __construct(
        SalesEventInterfaceFactory $salesEventFactory,
        ItemToSellInterfaceFactory $itemsToSellFactory,
        PlaceReservationsForSalesEventInterface $placeReservationsForSalesEvent,
        SalesEventExtensionFactory $salesEventExtensionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        OrderRepositoryInterface $orderRepository,
        SourceItemsSaveInterface $sourceItemsSave,
        GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        SalesChannelInterfaceFactory $salesChannelFactory,
        WebsiteRepositoryInterface $websiteRepository,
        LoggerInterface $logger
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
    }

    /**
     * @inheritDoc
     */
    public function execute(ShipmentInterface $shipment): void
    {
        $shipmentItems = $shipment->getAllItems();
        if (empty($shipmentItems)) {
            return;
        }

        $itemToSell = [];

        /** @var ShipmentItem $shipmentItem */
        foreach ($shipmentItems as $shipmentItem) {
            $sourceCode = null;
            /**
             * @var \Magento\Sales\Api\Data\ShipmentExtension|null $extensionAttributes
             */
            $extensionAttributes = $shipment->getExtensionAttributes();
            if (!is_null($extensionAttributes)) {
                $sourceCode = $extensionAttributes->getSourceCode();
            }
            if ($sourceCode === null) {
                continue; // Source code is not set, we can't return items
            }

            $orderItem = $this->orderItemRepository->get((int)$shipmentItem->getOrderItemId());
            if ($orderItem->getQtyShipped() > $orderItem->getQtyOrdered()) {
                /**
                 * Negative modification which will prevent double deduction
                 * because we already removed item from stock in
                 * \MageWorx\OrderEditorInventory\Model\StockQtyManager::returnQtyToStock() method
                 */
                $qtyModification = $orderItem->getQtyOrdered() - $orderItem->getQtyShipped();
            } else {
                $qtyModification = 0;
            }
            $backQty = $shipmentItem->getQty() + $qtyModification;
            $sku     = $shipmentItem->getSku();

            $sourceItem = $this->getSourceItemBySourceCodeAndSku->execute($sourceCode, $sku);
            $sourceItem->setQuantity($sourceItem->getQuantity() + $backQty);
            $sourceItems[] = $sourceItem;

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
                    $this->logger->error($localizedException->getLogMessage());
                }
            } catch (CouldNotSaveException $e) {
                $this->logger->error($e->getLogMessage());
            } catch (InputException | ValidationException $e) {
                $this->logger->notice($e->getLogMessage());
            }
        }
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
     * @throws \Magento\Framework\Exception\NoSuchEntityException
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
