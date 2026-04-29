<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface;
use MageWorx\OrderEditorInventory\Model\Stock\MultiSourceInventoryManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MultiSourceInventoryManagerTest extends TestCase
{
    /** @var StockQtyManagerInterface|MockObject */
    private $stockQtyManager;

    /** @var GetSkusByProductIdsInterface|MockObject */
    private $getSkusByProductIds;

    /** @var GetSourceItemsBySkuInterface|MockObject */
    private $getSourceItemsBySku;

    /** @var SourceItemsSaveInterface|MockObject */
    private $sourceItemsSave;

    /** @var ItemToSellInterfaceFactory|MockObject */
    private $itemsToSellFactory;

    /** @var PlaceReservationsForSalesEventInterface|MockObject */
    private $placeReservationsForSalesEvent;

    /** @var SalesEventInterfaceFactory|MockObject */
    private $salesEventFactory;

    /** @var SalesEventExtensionFactory|MockObject */
    private $salesEventExtensionFactory;

    /** @var SalesChannelInterfaceFactory|MockObject */
    private $salesChannelFactory;

    /** @var WebsiteRepositoryInterface|MockObject */
    private $websiteRepository;

    private MultiSourceInventoryManager $manager;

    protected function setUp(): void
    {
        $this->stockQtyManager                = $this->createMock(StockQtyManagerInterface::class);
        $this->getSkusByProductIds            = $this->createMock(GetSkusByProductIdsInterface::class);
        $this->getSourceItemsBySku            = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->sourceItemsSave                = $this->createMock(SourceItemsSaveInterface::class);
        $this->itemsToSellFactory             = $this->createMock(ItemToSellInterfaceFactory::class);
        $this->placeReservationsForSalesEvent = $this->createMock(PlaceReservationsForSalesEventInterface::class);
        $this->salesEventFactory              = $this->createMock(SalesEventInterfaceFactory::class);
        $this->salesEventExtensionFactory     = $this->createMock(SalesEventExtensionFactory::class);
        $this->salesChannelFactory            = $this->createMock(SalesChannelInterfaceFactory::class);
        $this->websiteRepository              = $this->createMock(WebsiteRepositoryInterface::class);

        $this->manager = new MultiSourceInventoryManager(
            $this->stockQtyManager,
            $this->getSkusByProductIds,
            $this->itemsToSellFactory,
            $this->placeReservationsForSalesEvent,
            $this->salesEventFactory,
            $this->salesEventExtensionFactory,
            $this->salesChannelFactory,
            $this->websiteRepository,
            $this->getSourceItemsBySku,
            $this->sourceItemsSave
        );
    }

    public function testRegisterSaleDelegatesToStockQtyManager(): void
    {
        $item = $this->createMock(OrderItem::class);
        $this->stockQtyManager->expects($this->once())
                              ->method('deductQtyFromStock')
                              ->with($item, 4.0);
        $this->manager->registerSale($item, 4.0);
    }

    public function testRegisterReturnDelegatesToStockQtyManager(): void
    {
        $item = $this->createMock(OrderItem::class);
        $this->stockQtyManager->expects($this->once())
                              ->method('returnQtyToStock')
                              ->with($item, 2.0);
        $this->manager->registerReturn($item, 2.0);
    }

    public function testRegisterReturnByProductIdAdjustsFirstEnabledSource(): void
    {
        $this->getSkusByProductIds->method('execute')->with([5])->willReturn([5 => 'sku-x']);

        $disabled = $this->createMock(SourceItemInterface::class);
        $disabled->method('getStatus')->willReturn(0);
        $disabled->expects($this->never())->method('setQuantity');

        $enabled = $this->createMock(SourceItemInterface::class);
        $enabled->method('getStatus')->willReturn(1);
        $enabled->method('getQuantity')->willReturn(10.0);
        $enabled->expects($this->once())->method('setQuantity')->with(13.0);

        $this->getSourceItemsBySku->method('execute')->with('sku-x')->willReturn([$disabled, $enabled]);

        $this->sourceItemsSave->expects($this->once())->method('execute')->with([$enabled]);

        $this->manager->registerReturnByProductId(5, 3.0, 1);
    }

    public function testRegisterReturnByProductIdNegativeQtyClampsToZero(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([5 => 'sku-x']);

        $enabled = $this->createMock(SourceItemInterface::class);
        $enabled->method('getStatus')->willReturn(1);
        $enabled->method('getQuantity')->willReturn(2.0);
        $enabled->expects($this->once())->method('setQuantity')->with(0.0);

        $this->getSourceItemsBySku->method('execute')->willReturn([$enabled]);
        $this->sourceItemsSave->expects($this->once())->method('execute');

        $this->manager->registerReturnByProductId(5, -5.0, 1);
    }

    public function testRegisterReturnByProductIdReturnsEarlyWhenSkuMissing(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([]);
        $this->getSourceItemsBySku->expects($this->never())->method('execute');
        $this->sourceItemsSave->expects($this->never())->method('execute');

        $this->manager->registerReturnByProductId(99, 1.0, 1);
    }

    public function testRegisterReturnByProductIdReturnsEarlyWhenNoSourceItems(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([5 => 'sku-x']);
        $this->getSourceItemsBySku->method('execute')->willReturn([]);
        $this->sourceItemsSave->expects($this->never())->method('execute');

        $this->manager->registerReturnByProductId(5, 1.0, 1);
    }

    /**
     * Roadmap §4.2.3 — when called with negative qty AND an order context,
     * the swapped-in SKU must get paired reservations
     * (ORDER_PLACED -|qty| + SHIPMENT_CREATED +|qty|).
     */
    public function testRegisterReturnByProductIdPlacesPairedReservationsOnDeductWithOrder(): void
    {
        $this->setUpEnabledSource('MH09-XL-Blue', 87.0);
        $order = $this->createOrderMock(210, 'ORD-26-04-28-150');
        $this->setUpWebsite(1, 'base');

        // 4 factory calls: salesChannel, extension, ORDER_PLACED event, SHIPMENT_CREATED event
        $this->salesChannelFactory->expects($this->once())->method('create')
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::class));
        $this->salesEventExtensionFactory->expects($this->once())->method('create')
            ->willReturn($this->createMock(SalesEventExtensionInterface::class));

        $orderPlacedEvent = $this->createMock(SalesEventInterface::class);
        $shipmentEvent    = $this->createMock(SalesEventInterface::class);
        $this->salesEventFactory->expects($this->exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($orderPlacedEvent, $shipmentEvent);

        // 2 ItemToSell: one for -2, one for +2
        $itemToSellNegative = $this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class);
        $itemToSellPositive = $this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class);
        $factoryCalls = [];
        $this->itemsToSellFactory->expects($this->exactly(2))->method('create')
            ->willReturnCallback(function (array $args) use (&$factoryCalls, $itemToSellNegative, $itemToSellPositive) {
                $factoryCalls[] = $args;
                return $args['qty'] < 0 ? $itemToSellNegative : $itemToSellPositive;
            });

        // placeReservationsForSalesEvent called twice: once for each event
        $this->placeReservationsForSalesEvent->expects($this->exactly(2))->method('execute');

        $this->manager->registerReturnByProductId(254, -2.0, 1, $order);

        // Verify factory was called with correct sku/qty pairs
        $this->assertCount(2, $factoryCalls);
        $this->assertSame('MH09-XL-Blue', $factoryCalls[0]['sku']);
        $this->assertSame(-2.0, $factoryCalls[0]['qty']);
        $this->assertSame('MH09-XL-Blue', $factoryCalls[1]['sku']);
        $this->assertSame(2.0, $factoryCalls[1]['qty']);
    }

    /**
     * Without an order, no paired reservations — backward compat with old callers.
     */
    public function testRegisterReturnByProductIdSkipsPairedReservationsWithoutOrder(): void
    {
        $this->setUpEnabledSource('MH09-XL-Blue', 87.0);

        $this->placeReservationsForSalesEvent->expects($this->never())->method('execute');
        $this->salesChannelFactory->expects($this->never())->method('create');
        $this->salesEventFactory->expects($this->never())->method('create');

        $this->manager->registerReturnByProductId(254, -2.0, 1);
    }

    /**
     * Positive qty (return case) does NOT create paired reservations even with order —
     * the old SKU's existing reservations remain untouched (we don't manufacture
     * compensation for them).
     */
    public function testRegisterReturnByProductIdSkipsPairedReservationsOnPositiveQty(): void
    {
        $this->setUpEnabledSource('MH09-L-Red', 98.0);
        $order = $this->createOrderMock(210, 'ORD-26-04-28-150');

        $this->placeReservationsForSalesEvent->expects($this->never())->method('execute');

        $this->manager->registerReturnByProductId(99, 2.0, 1, $order);
    }

    /**
     * Reservation creation failure must not abort the flow — source_item update
     * (the user-visible part) has already happened.
     */
    public function testRegisterReturnByProductIdSwallowsReservationException(): void
    {
        $this->setUpEnabledSource('MH09-XL-Blue', 87.0);
        $order = $this->createOrderMock(210, 'ORD-26-04-28-150');
        $this->setUpWebsite(1, 'base');

        $this->salesChannelFactory->method('create')
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::class));
        $this->salesEventExtensionFactory->method('create')
            ->willReturn($this->createMock(SalesEventExtensionInterface::class));
        $this->salesEventFactory->method('create')
            ->willReturn($this->createMock(SalesEventInterface::class));
        $this->itemsToSellFactory->method('create')
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class));

        $this->placeReservationsForSalesEvent->method('execute')
            ->willThrowException(new \RuntimeException('MSI broke'));

        // Must not propagate
        $this->manager->registerReturnByProductId(254, -2.0, 1, $order);
        $this->addToAssertionCount(1);
    }

    // ── Helpers ─────────────────────────────────────────────

    private function setUpEnabledSource(string $sku, float $qty): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([$sku === 'MH09-L-Red' ? 99 : 254 => $sku]);
        $enabled = $this->createMock(SourceItemInterface::class);
        $enabled->method('getStatus')->willReturn(1);
        $enabled->method('getQuantity')->willReturn($qty);
        $this->getSourceItemsBySku->method('execute')->willReturn([$enabled]);
    }

    private function setUpWebsite(int $websiteId, string $code): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($code);
        $this->websiteRepository->method('getById')->with($websiteId)->willReturn($website);
    }

    private function createOrderMock(int $entityId, string $incrementId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn($incrementId);
        return $order;
    }
}
