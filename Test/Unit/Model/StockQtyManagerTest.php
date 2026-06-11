<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\InputException;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\ProcessRefundItemsInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterfaceFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Store;
use MageWorx\OrderEditorInventory\Api\CancelShipmentProcessorInterface;
use MageWorx\OrderEditorInventory\Model\CheckItemsQuantity;
use MageWorx\OrderEditorInventory\Model\StockQtyManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StockQtyManagerTest extends TestCase
{
    /** @var PlaceReservationsForSalesEventInterface|MockObject */
    private $placeReservationsForSalesEvent;

    /** @var GetSkusByProductIdsInterface|MockObject */
    private $getSkusByProductIds;

    /** @var WebsiteRepositoryInterface|MockObject */
    private $websiteRepository;

    /** @var SalesChannelInterfaceFactory|MockObject */
    private $salesChannelFactory;

    /** @var SalesEventInterfaceFactory|MockObject */
    private $salesEventFactory;

    /** @var ItemToSellInterfaceFactory|MockObject */
    private $itemsToSellFactory;

    /** @var CheckItemsQuantity|MockObject */
    private $checkItemsQuantity;

    /** @var StockByWebsiteIdResolverInterface|MockObject */
    private $stockByWebsiteIdResolver;

    /** @var GetProductTypesBySkusInterface|MockObject */
    private $getProductTypesBySkus;

    /** @var IsSourceItemManagementAllowedForProductTypeInterface|MockObject */
    private $isSourceItemManagementAllowed;

    /** @var SalesEventExtensionFactory|MockObject */
    private $salesEventExtensionFactory;

    /** @var GetSkuFromOrderItemInterface|MockObject */
    private $getSkuFromOrderItem;

    /** @var ItemsToRefundInterfaceFactory|MockObject */
    private $itemsToRefundFactory;

    /** @var ProcessRefundItemsInterface|MockObject */
    private $processRefundItems;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var CancelShipmentProcessorInterface|MockObject */
    private $cancelShipmentProcessor;

    private StockQtyManager $stockQtyManager;

    protected function setUp(): void
    {
        $this->placeReservationsForSalesEvent = $this->createMock(PlaceReservationsForSalesEventInterface::class);
        $this->getSkusByProductIds            = $this->createMock(GetSkusByProductIdsInterface::class);
        $this->websiteRepository              = $this->createMock(WebsiteRepositoryInterface::class);
        $this->salesChannelFactory            = $this->createMock(SalesChannelInterfaceFactory::class);
        $this->salesEventFactory              = $this->createMock(SalesEventInterfaceFactory::class);
        $this->itemsToSellFactory             = $this->createMock(ItemToSellInterfaceFactory::class);
        $this->checkItemsQuantity             = $this->createMock(CheckItemsQuantity::class);
        $this->stockByWebsiteIdResolver       = $this->createMock(StockByWebsiteIdResolverInterface::class);
        $this->getProductTypesBySkus          = $this->createMock(GetProductTypesBySkusInterface::class);
        $this->isSourceItemManagementAllowed  = $this->createMock(
            IsSourceItemManagementAllowedForProductTypeInterface::class
        );
        $this->salesEventExtensionFactory     = $this->createMock(SalesEventExtensionFactory::class);
        $this->getSkuFromOrderItem            = $this->createMock(GetSkuFromOrderItemInterface::class);
        $this->itemsToRefundFactory           = $this->createMock(ItemsToRefundInterfaceFactory::class);
        $this->processRefundItems             = $this->createMock(ProcessRefundItemsInterface::class);
        $this->orderRepository                = $this->createMock(OrderRepositoryInterface::class);
        $this->cancelShipmentProcessor        = $this->createMock(CancelShipmentProcessorInterface::class);

        $this->stockQtyManager = new StockQtyManager(
            $this->placeReservationsForSalesEvent,
            $this->getSkusByProductIds,
            $this->websiteRepository,
            $this->salesChannelFactory,
            $this->salesEventFactory,
            $this->itemsToSellFactory,
            $this->checkItemsQuantity,
            $this->stockByWebsiteIdResolver,
            $this->getProductTypesBySkus,
            $this->isSourceItemManagementAllowed,
            $this->salesEventExtensionFactory,
            $this->getSkuFromOrderItem,
            $this->itemsToRefundFactory,
            $this->processRefundItems,
            $this->orderRepository,
            $this->cancelShipmentProcessor
        );
    }

    public function testDeductQtyFromStockSimpleProductPlacesReservation(): void
    {
        $orderItem = $this->createOrderItemMock(
            productId: 100,
            productType: 'simple',
            qtyOrdered: 3.0,
            order: $this->createOrderMock(orderId: 5, websiteId: 1)
        );

        $this->getSkusByProductIds->expects($this->once())
                                  ->method('execute')
                                  ->with([100])
                                  ->willReturn([100 => 'sku-simple']);

        $this->isSourceItemManagementAllowed->expects($this->once())
                                            ->method('execute')
                                            ->with('simple')
                                            ->willReturn(true);

        $this->itemsToSellFactory->expects($this->once())->method('create')->willReturn(
            $this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class)
        );

        $this->mockWebsiteAndStock(websiteId: 1, websiteCode: 'base', stockId: 1);
        $this->mockSalesEvent();

        $this->checkItemsQuantity->expects($this->once())
                                 ->method('execute')
                                 ->with(['sku-simple' => 3.0], 1);

        $this->placeReservationsForSalesEvent->expects($this->once())->method('execute');

        $this->stockQtyManager->deductQtyFromStock($orderItem);
    }

    public function testDeductQtyFromStockConfigurableProcessesChildren(): void
    {
        $childOrderItem = $this->createOrderItemMock(
            productId: 200,
            productType: 'simple',
            qtyOrdered: 1.0,
            order: $this->createOrderMock(orderId: 10, websiteId: 1)
        );

        $parent = $this->createConfiguredMock(OrderItem::class, []);
        $parent->method('getProductType')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getChildrenItems')->willReturn([$childOrderItem]);
        $parent->method('getParentItemId')->willReturn(null);

        $this->getSkusByProductIds->expects($this->once())
                                  ->method('execute')
                                  ->with([200])
                                  ->willReturn([200 => 'sku-child']);

        $this->isSourceItemManagementAllowed->method('execute')->willReturn(true);
        $this->itemsToSellFactory->method('create')->willReturn(
            $this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class)
        );

        $this->mockWebsiteAndStock(websiteId: 1, websiteCode: 'base', stockId: 1);
        $this->mockSalesEvent();

        $this->placeReservationsForSalesEvent->expects($this->once())->method('execute');

        $this->stockQtyManager->deductQtyFromStock($parent);
    }

    public function testDeductQtyFromStockBundleProcessesChildren(): void
    {
        $childOrderItem = $this->createOrderItemMock(
            productId: 300,
            productType: 'simple',
            qtyOrdered: 2.0,
            order: $this->createOrderMock(orderId: 11, websiteId: 1)
        );

        $bundle = $this->createConfiguredMock(OrderItem::class, []);
        $bundle->method('getProductType')->willReturn(BundleType::TYPE_CODE);
        $bundle->method('getChildrenItems')->willReturn([$childOrderItem]);
        $bundle->method('getParentItemId')->willReturn(null);

        $this->getSkusByProductIds->expects($this->once())
                                  ->method('execute')
                                  ->with([300])
                                  ->willReturn([300 => 'sku-bundle-child']);

        $this->isSourceItemManagementAllowed->method('execute')->willReturn(true);
        $this->itemsToSellFactory->method('create')->willReturn(
            $this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class)
        );

        $this->mockWebsiteAndStock(websiteId: 1, websiteCode: 'base', stockId: 1);
        $this->mockSalesEvent();

        $this->placeReservationsForSalesEvent->expects($this->once())->method('execute');

        $this->stockQtyManager->deductQtyFromStock($bundle);
    }

    public function testDeductQtyFromStockChildItemSkipped(): void
    {
        $child = $this->createConfiguredMock(OrderItem::class, []);
        $child->method('getProductType')->willReturn('simple');
        $child->method('getParentItemId')->willReturn(50);

        $this->getSkusByProductIds->expects($this->never())->method('execute');
        $this->placeReservationsForSalesEvent->expects($this->never())->method('execute');

        $this->stockQtyManager->deductQtyFromStock($child);
    }

    public function testDeductQtyFromStockThrowsWhenOrderMissing(): void
    {
        $orderItem = $this->createConfiguredMock(OrderItem::class, []);
        $orderItem->method('getProductType')->willReturn('simple');
        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('getProductId')->willReturn(1);
        $orderItem->method('getQtyOrdered')->willReturn(1.0);
        $orderItem->method('getOrder')->willReturn(null);

        $this->expectException(InputException::class);
        $this->stockQtyManager->deductQtyFromStock($orderItem);
    }

    public function testDeductQtyFromStockSkipsItemsNotAllowedForSourceManagement(): void
    {
        $orderItem = $this->createOrderItemMock(
            productId: 400,
            productType: 'virtual',
            qtyOrdered: 1.0,
            order: $this->createOrderMock(orderId: 12, websiteId: 1)
        );

        $this->getSkusByProductIds->method('execute')->willReturn([400 => 'sku-virtual']);
        $this->isSourceItemManagementAllowed->expects($this->once())
                                            ->method('execute')
                                            ->with('virtual')
                                            ->willReturn(false);

        $this->itemsToSellFactory->expects($this->never())->method('create');
        $this->mockWebsiteAndStock(websiteId: 1, websiteCode: 'base', stockId: 1);
        $this->mockSalesEvent();

        $this->checkItemsQuantity->expects($this->once())->method('execute')->with([], 1);
        $this->placeReservationsForSalesEvent->expects($this->once())
                                             ->method('execute')
                                             ->with([], $this->anything(), $this->anything());

        $this->stockQtyManager->deductQtyFromStock($orderItem);
    }

    public function testReturnQtyToStockThrowsWhenOrderMissing(): void
    {
        $orderItem = $this->createConfiguredMock(OrderItem::class, []);
        $orderItem->method('getOrder')->willReturn(null);

        $this->expectException(InputException::class);
        $this->stockQtyManager->returnQtyToStock($orderItem, 1.0);
    }

    public function testReturnQtyToStockSimpleProductDelegatesToProcessRefundItems(): void
    {
        $order     = $this->createOrderMock(orderId: 20, websiteId: 1);
        $orderItem = $this->createConfiguredMock(OrderItem::class, []);
        $orderItem->method('getProductType')->willReturn('simple');
        $orderItem->method('getOrder')->willReturn($order);
        $orderItem->method('getItemId')->willReturn(99);
        $orderItem->method('getQtyInvoiced')->willReturn(4.0);
        $orderItem->method('getQtyRefunded')->willReturn(2.0);

        $this->getSkuFromOrderItem->method('execute')->with($orderItem)->willReturn('sku-r');
        $this->isSourceItemManagementAllowed->method('execute')->willReturn(true);

        $this->itemsToRefundFactory->expects($this->once())
                                   ->method('create')
                                   ->with(
                                       $this->callback(static function (array $data): bool {
                                           // native formula: qtyInvoiced(4) - qtyRefunded(2) + returnQty(3) = 5
                                           return $data['sku'] === 'sku-r'
                                               && $data['qty'] === 3.0
                                               && $data['processedQty'] === 5.0;
                                       })
                                   )
                                   ->willReturn(
                                       $this->createMock(
                                           \Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterface::class
                                       )
                                   );

        $this->processRefundItems->expects($this->once())
                                 ->method('execute')
                                 ->with($order, $this->isType('array'), [99]);

        $this->stockQtyManager->returnQtyToStock($orderItem, -3.0);
    }

    public function testReturnQtyToStockConfigurableUsesChildren(): void
    {
        $order = $this->createOrderMock(orderId: 21, websiteId: 1);

        $child = $this->createConfiguredMock(OrderItem::class, []);
        $child->method('getProductType')->willReturn('simple');
        $child->method('getItemId')->willReturn(101);
        $child->method('getQtyOrdered')->willReturn(4.0);
        $child->method('getQtyCanceled')->willReturn(0.0);
        $child->method('getQtyRefunded')->willReturn(0.0);

        $parent = $this->createConfiguredMock(OrderItem::class, []);
        $parent->method('getProductType')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getOrder')->willReturn($order);
        $parent->method('getChildrenItems')->willReturn([$child]);

        $this->getSkuFromOrderItem->method('execute')->willReturn('sku-child');
        $this->isSourceItemManagementAllowed->method('execute')->willReturn(true);
        $this->itemsToRefundFactory->method('create')->willReturn(
            $this->createMock(\Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterface::class)
        );

        $this->processRefundItems->expects($this->once())
                                 ->method('execute')
                                 ->with($order, $this->isType('array'), [101]);

        $this->stockQtyManager->returnQtyToStock($parent, -2.0);
    }

    public function testReturnQtyToStockSkipsInvalidItems(): void
    {
        $order     = $this->createOrderMock(orderId: 22, websiteId: 1);
        $orderItem = $this->createConfiguredMock(OrderItem::class, []);
        $orderItem->method('getProductType')->willReturn('virtual');
        $orderItem->method('getOrder')->willReturn($order);

        $this->getSkuFromOrderItem->method('execute')->willReturn('sku-virtual');
        $this->isSourceItemManagementAllowed->method('execute')->with('virtual')->willReturn(false);

        $this->itemsToRefundFactory->expects($this->never())->method('create');
        $this->processRefundItems->expects($this->never())->method('execute');

        $this->stockQtyManager->returnQtyToStock($orderItem, -1.0);
    }

    public function testCancelShipmentDelegatesToProcessor(): void
    {
        $shipment = $this->createMock(\Magento\Sales\Api\Data\ShipmentInterface::class);
        $this->cancelShipmentProcessor->expects($this->once())->method('execute')->with($shipment);
        $this->stockQtyManager->cancelShipment($shipment);
    }

    /**
     * @param int $orderId
     * @param int $websiteId
     * @return Order|MockObject
     */
    private function createOrderMock(int $orderId, int $websiteId)
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getIncrementId')->willReturn('100000' . $orderId);
        $order->method('getStore')->willReturn($store);

        return $order;
    }

    /**
     * @return OrderItem|MockObject
     */
    private function createOrderItemMock(int $productId, string $productType, float $qtyOrdered, Order $order)
    {
        $orderItem = $this->createConfiguredMock(OrderItem::class, []);
        $orderItem->method('getProductType')->willReturn($productType);
        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('getProductId')->willReturn($productId);
        $orderItem->method('getQtyOrdered')->willReturn($qtyOrdered);
        $orderItem->method('getOrder')->willReturn($order);

        return $orderItem;
    }

    private function mockWebsiteAndStock(int $websiteId, string $websiteCode, int $stockId): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($websiteCode);
        $this->websiteRepository->method('getById')->with($websiteId)->willReturn($website);

        $stock = $this->createMock(\Magento\InventoryApi\Api\Data\StockInterface::class);
        $stock->method('getStockId')->willReturn($stockId);
        $this->stockByWebsiteIdResolver->method('execute')->with($websiteId)->willReturn($stock);
    }

    private function mockSalesEvent(): void
    {
        $extension = $this->createMock(SalesEventExtensionInterface::class);
        $this->salesEventExtensionFactory->method('create')->willReturn($extension);

        $event = $this->createMock(SalesEventInterface::class);
        $this->salesEventFactory->method('create')->willReturn($event);

        $channel = $this->createMock(SalesChannelInterface::class);
        $this->salesChannelFactory->method('create')->willReturn($channel);
    }
}
