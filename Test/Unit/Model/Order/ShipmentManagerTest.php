<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Order;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Registry;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface as OriginalOrderRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterfaceFactory as OriginalOrderRepositoryInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoaderFactory;
use MageWorx\OrderEditor\Api\OrderItemRepositoryInterface;
use MageWorx\OrderEditor\Api\OrderRepositoryInterface;
use MageWorx\OrderEditor\Helper\Data as Helper;
use MageWorx\OrderEditor\Model\Config\Source\Shipments\UpdateMode;
use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;
use MageWorx\OrderEditorInventory\Model\Order\ShipmentManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShipmentManagerTest extends TestCase
{
    /** @var Helper|MockObject */
    private $helperData;

    /** @var Registry|MockObject */
    private $registry;

    /** @var TransactionFactory|MockObject */
    private $transactionFactory;

    /** @var ShipmentLoaderFactory|MockObject */
    private $shipmentLoaderFactory;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var ShipmentRepositoryInterface|MockObject */
    private $shipmentRepository;

    /** @var OrderItemRepositoryInterface|MockObject */
    private $orderItemRepository;

    /** @var OrderPaymentRepositoryInterface|MockObject */
    private $orderPaymentRepository;

    /** @var OriginalOrderRepositoryInterface|MockObject */
    private $originalOrderRepository;

    /** @var OriginalOrderRepositoryInterfaceFactory|MockObject */
    private $originalOrderRepositoryFactory;

    /** @var StockQtyManagerInterface|MockObject */
    private $stockQtyManager;

    /** @var GetSkuFromOrderItemInterface|MockObject */
    private $getSkuFromOrderItem;

    private ShipmentManager $manager;

    protected function setUp(): void
    {
        $this->helperData                     = $this->createMock(Helper::class);
        $this->registry                       = $this->createMock(Registry::class);
        $this->transactionFactory             = $this->createMock(TransactionFactory::class);
        $this->shipmentLoaderFactory          = $this->createMock(ShipmentLoaderFactory::class);
        $this->orderRepository                = $this->createMock(OrderRepositoryInterface::class);
        $this->shipmentRepository             = $this->createMock(ShipmentRepositoryInterface::class);
        $this->orderItemRepository            = $this->createMock(OrderItemRepositoryInterface::class);
        $this->orderPaymentRepository         = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->originalOrderRepository        = $this->createMock(OriginalOrderRepositoryInterface::class);
        $this->originalOrderRepositoryFactory = $this->createMock(OriginalOrderRepositoryInterfaceFactory::class);
        $this->stockQtyManager                = $this->createMock(StockQtyManagerInterface::class);
        $this->getSkuFromOrderItem            = $this->createMock(GetSkuFromOrderItemInterface::class);

        $this->manager = new ShipmentManager(
            $this->helperData,
            $this->registry,
            $this->transactionFactory,
            $this->shipmentLoaderFactory,
            $this->orderRepository,
            $this->shipmentRepository,
            $this->orderItemRepository,
            $this->orderPaymentRepository,
            $this->originalOrderRepository,
            $this->originalOrderRepositoryFactory,
            $this->stockQtyManager,
            $this->getSkuFromOrderItem
        );
    }

    public function testReturnsOrderEarlyWhenNoShipments(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('hasShipments')->willReturn(false);

        $this->helperData->expects($this->never())->method('getUpdateShipmentMode');
        $this->shipmentRepository->expects($this->never())->method('delete');

        $result = $this->manager->updateShipmentsOnOrderEdit($order);
        $this->assertSame($order, $result);
    }

    public function testModeNothingWithoutDecreasesIsNoOp(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_NOTHING);
        $order->method('hasRemovedItems')->willReturn(false);
        $order->method('hasItemsWithDecreasedQty')->willReturn(false);

        $this->shipmentRepository->expects($this->never())->method('delete');
        $this->orderRepository->expects($this->never())->method('save');
        $this->orderPaymentRepository->expects($this->never())->method('save');

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    public function testModeNothingWithRemovedItemsTriggersRemoveAll(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_NOTHING);
        $order->method('hasRemovedItems')->willReturn(true);
        $order->method('hasItemsWithDecreasedQty')->willReturn(false);

        $this->expectRemoveAllShipmentsCalls($order);

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    public function testModeNothingWithDecreasedQtyTriggersRemoveAll(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_NOTHING);
        $order->method('hasRemovedItems')->willReturn(false);
        $order->method('hasItemsWithDecreasedQty')->willReturn(true);

        $this->expectRemoveAllShipmentsCalls($order);

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    public function testModeRebuildAlwaysRemovesAndCreates(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_REBUILD);
        $order->method('canShip')->willReturn(false);

        $this->expectRemoveAllShipmentsCalls($order);

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    public function testModeAddWithOnlyIncreasedQtyDoesNotRemoveFirst(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_ADD);
        $order->method('hasItemsWithIncreasedQty')->willReturn(true);
        $order->method('hasAddedItems')->willReturn(false);
        $order->method('hasItemsWithDecreasedQty')->willReturn(false);
        $order->method('hasRemovedItems')->willReturn(false);
        $order->method('canShip')->willReturn(false);

        $this->orderRepository->expects($this->never())->method('save');
        $this->orderPaymentRepository->expects($this->never())->method('save');

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    public function testModeAddWithMixedChangesRemovesFirst(): void
    {
        $order = $this->createOrderForMode(UpdateMode::MODE_UPDATE_ADD);
        $order->method('hasItemsWithIncreasedQty')->willReturn(true);
        $order->method('hasAddedItems')->willReturn(false);
        $order->method('hasItemsWithDecreasedQty')->willReturn(true);
        $order->method('hasRemovedItems')->willReturn(false);
        $order->method('canShip')->willReturn(false);

        $this->expectRemoveAllShipmentsCalls($order);

        $this->manager->updateShipmentsOnOrderEdit($order);
    }

    /**
     * @param string $mode
     * @return Order|MockObject
     */
    private function createOrderForMode(string $mode)
    {
        $this->helperData->method('getUpdateShipmentMode')->willReturn($mode);

        $order = $this->createMock(Order::class);
        $order->method('hasShipments')->willReturn(true);
        $order->method('getShipmentsCollection')->willReturn(new \ArrayIterator([]));
        $order->method('getItems')->willReturn([]);

        return $order;
    }

    /**
     * @param Order|MockObject $order
     */
    private function expectRemoveAllShipmentsCalls($order): void
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('setShippingCaptured')->willReturnSelf();
        $payment->method('setBaseShippingCaptured')->willReturnSelf();
        $payment->method('setShippingRefunded')->willReturnSelf();
        $payment->method('setBaseShippingRefunded')->willReturnSelf();
        $order->method('getPayment')->willReturn($payment);

        $order->expects($this->once())->method('setState')->with(SalesOrder::STATE_PROCESSING);
        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->orderPaymentRepository->expects($this->once())->method('save')->with($payment);
    }
}
