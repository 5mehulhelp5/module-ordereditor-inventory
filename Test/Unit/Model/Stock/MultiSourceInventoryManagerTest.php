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
     * Roadmap §4.2.6 — when called with negative qty AND an order context,
     * places ONE reservation (ORDER_PLACED -qty) on the new SKU; does NOT
     * touch the source_item (no physical event yet, only logical swap of
     * the pending portion).
     */
    public function testRegisterReturnByProductIdPlacesOrderPlacedReservationOnDeductWithOrder(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([254 => 'MH09-XL-Blue']);
        $order = $this->createOrderMock(210, 'ORD-26-04-28-150');
        $this->setUpWebsite(1, 'base');

        // No source_item update in configure-swap mode
        $this->getSourceItemsBySku->expects($this->never())->method('execute');
        $this->sourceItemsSave->expects($this->never())->method('execute');

        $this->salesChannelFactory->expects($this->once())->method('create')
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::class));
        $this->salesEventExtensionFactory->expects($this->once())->method('create')
            ->willReturn($this->createMock(SalesEventExtensionInterface::class));

        // ONE event: ORDER_PLACED only (no SHIPMENT_CREATED — pending isn't shipped yet)
        $orderPlacedEvent = $this->createMock(SalesEventInterface::class);
        $this->salesEventFactory->expects($this->once())->method('create')
            ->with(self::callback(fn ($args) => ($args['type'] ?? null) === SalesEventInterface::EVENT_ORDER_PLACED))
            ->willReturn($orderPlacedEvent);

        $this->itemsToSellFactory->expects($this->once())->method('create')
            ->with(self::callback(
                fn ($args) => ($args['sku'] ?? null) === 'MH09-XL-Blue' && ($args['qty'] ?? null) === -2.0
            ))
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class));

        $this->placeReservationsForSalesEvent->expects($this->once())->method('execute');

        $this->manager->registerReturnByProductId(254, -2.0, 1, $order);
    }

    /**
     * Roadmap §4.2.6 — positive qty WITH order context releases the old SKU's
     * pending reservation via ORDER_CANCELED event.
     */
    public function testRegisterReturnByProductIdPlacesOrderCanceledReservationOnReleaseWithOrder(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([99 => 'MH09-L-Red']);
        $order = $this->createOrderMock(210, 'ORD-26-04-28-150');
        $this->setUpWebsite(1, 'base');

        // No source_item update
        $this->getSourceItemsBySku->expects($this->never())->method('execute');
        $this->sourceItemsSave->expects($this->never())->method('execute');

        $this->salesChannelFactory->method('create')
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::class));
        $this->salesEventExtensionFactory->method('create')
            ->willReturn($this->createMock(SalesEventExtensionInterface::class));

        // ORDER_CANCELED event for release (not ORDER_PLACED)
        $this->salesEventFactory->expects($this->once())->method('create')
            ->with(self::callback(fn ($args) => ($args['type'] ?? null) === 'order_canceled'))
            ->willReturn($this->createMock(SalesEventInterface::class));

        $this->itemsToSellFactory->expects($this->once())->method('create')
            ->with(self::callback(
                fn ($args) => ($args['sku'] ?? null) === 'MH09-L-Red' && ($args['qty'] ?? null) === 2.0
            ))
            ->willReturn($this->createMock(\Magento\InventorySalesApi\Api\Data\ItemToSellInterface::class));

        $this->placeReservationsForSalesEvent->expects($this->once())->method('execute');

        $this->manager->registerReturnByProductId(99, 2.0, 1, $order);
    }

    /**
     * Without an order, no reservations — legacy mode falls through to direct
     * source_item adjustment (BC for callers that pass only 3 args).
     */
    public function testRegisterReturnByProductIdLegacyModeWithoutOrder(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([254 => 'MH09-XL-Blue']);
        $enabled = $this->createMock(SourceItemInterface::class);
        $enabled->method('getStatus')->willReturn(1);
        $enabled->method('getQuantity')->willReturn(87.0);
        $enabled->expects($this->once())->method('setQuantity')->with(85.0);
        $this->getSourceItemsBySku->method('execute')->willReturn([$enabled]);
        $this->sourceItemsSave->expects($this->once())->method('execute');

        // No reservations created
        $this->placeReservationsForSalesEvent->expects($this->never())->method('execute');
        $this->salesChannelFactory->expects($this->never())->method('create');

        $this->manager->registerReturnByProductId(254, -2.0, 1);
    }

    /**
     * Reservation creation failure must not abort the Configure swap.
     * Configure swap proceeds even if MSI hiccups; salable drift on the
     * affected SKU is the worst outcome.
     */
    public function testRegisterReturnByProductIdSwallowsReservationException(): void
    {
        $this->getSkusByProductIds->method('execute')->willReturn([254 => 'MH09-XL-Blue']);
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
