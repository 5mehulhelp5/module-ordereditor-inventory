<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Store;
use MageWorx\OrderEditor\Model\StockDebugLogger;
use MageWorx\OrderEditorInventory\Model\Stock\MsiSwapPhysicalExchange;
use MageWorx\OrderEditorInventory\Model\Stock\SourceCodeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MsiSwapPhysicalExchangeTest extends TestCase
{
    private SourceCodeResolver|MockObject $resolver;
    private GetSourceItemsBySkuInterface|MockObject $getSourceItemsBySku;
    private SourceItemsSaveInterface|MockObject $sourceItemsSave;
    private PlaceReservationsForSalesEventInterface|MockObject $placeReservations;
    private MessageManagerInterface|MockObject $messageManager;
    private MsiSwapPhysicalExchange $service;
    private array $sourceItemsBySku = [];

    protected function setUp(): void
    {
        $this->sourceItemsBySku = [];
        $this->resolver = $this->createMock(SourceCodeResolver::class);
        $this->getSourceItemsBySku = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->sourceItemsSave = $this->createMock(SourceItemsSaveInterface::class);
        $this->placeReservations = $this->createMock(PlaceReservationsForSalesEventInterface::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);

        $salesEventFactory = $this->createMock(SalesEventInterfaceFactory::class);
        $salesEventFactory->method('create')->willReturnCallback(function () {
            $event = $this->createMock(SalesEventInterface::class);
            $event->method('getType')->willReturn('');
            return $event;
        });
        $salesEventExtensionFactory = $this->createMock(SalesEventExtensionFactory::class);
        $salesEventExtensionFactory->method('create')
            ->willReturn($this->createMock(SalesEventExtensionInterface::class));
        $salesChannelFactory = $this->createMock(SalesChannelInterfaceFactory::class);
        $salesChannelFactory->method('create')
            ->willReturn($this->createMock(SalesChannelInterface::class));
        $itemsToSellFactory = $this->createMock(ItemToSellInterfaceFactory::class);
        $itemsToSellFactory->method('create')
            ->willReturn($this->createMock(ItemToSellInterface::class));

        $websiteRepo = $this->createMock(WebsiteRepositoryInterface::class);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $websiteRepo->method('getById')->willReturn($website);

        $stockDebugLogger = $this->createMock(StockDebugLogger::class);

        // Single callback that respects per-test sourceItemsBySku map.
        $this->getSourceItemsBySku->method('execute')
            ->willReturnCallback(fn(string $sku) => $this->sourceItemsBySku[$sku] ?? []);

        $this->service = new MsiSwapPhysicalExchange(
            $this->resolver,
            $this->getSourceItemsBySku,
            $this->sourceItemsSave,
            $this->placeReservations,
            $salesEventFactory,
            $salesEventExtensionFactory,
            $salesChannelFactory,
            $websiteRepo,
            $itemsToSellFactory,
            $this->messageManager,
            $stockDebugLogger
        );
    }

    public function testValidatePassesWhenSourcesAreSufficient(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn(['default' => 2.0]);
        $this->resolver->method('findDeductionSource')
            ->with('cfg-1-XL', 1, 2.0, 'default')
            ->willReturn('default');

        $this->service->validate($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
        self::assertTrue(true); // no exception
    }

    public function testValidateThrowsWhenNoDeductionSource(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn(['default' => 2.0]);
        $this->resolver->method('findDeductionSource')->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Cannot deduct/');
        $this->service->validate($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
    }

    public function testValidateThrowsWhenOldSkuHasNoSourceAndShipmentSourceMissing(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn([]);
        $this->getSourceItemsBySku->method('execute')->willReturn([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/no source is configured/');
        $this->service->validate($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
    }

    public function testValidateNoOpForZeroQty(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->expects(self::never())->method('resolveShipmentSources');
        $this->service->validate($order, $item, 'cfg-1-XS', 'cfg-1-XL', 0.0);
    }

    public function testExecuteReturnsOldSkuToShipmentSourceAndDeductsNewFromSame(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn(['default' => 2.0]);
        $this->resolver->method('findReturnSource')->willReturn('default');
        $this->resolver->method('findDeductionSource')->willReturn('default');

        $this->mockSourceItemForSku('cfg-1-XS', 'default', 17.0);
        $this->mockSourceItemForSku('cfg-1-XL', 'default', 5.0);

        // Two source updates: +2 to XS, -2 from XL.
        $this->sourceItemsSave->expects(self::exactly(2))->method('execute');

        // No reservation pair — shipped qty placement is already balanced by
        // shipment_created (see MsiSwapPhysicalExchange::execute comment).
        // History log entry lives elsewhere (ProductOptionsEditor).
        $this->placeReservations->expects(self::never())->method('execute');

        // No notice when same source served both legs.
        $this->messageManager->expects(self::never())->method('addNoticeMessage');

        $this->service->execute($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
    }

    public function testExecuteEmitsNoticeWhenDeductionFallsBackToDifferentSource(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn(['default' => 2.0]);
        $this->resolver->method('findReturnSource')->willReturn('default');
        // Fallback path: shipment source is `default` but new SKU deducted from `eu_source`.
        $this->resolver->method('findDeductionSource')->willReturn('eu_source');

        $this->mockSourceItemForSku('cfg-1-XS', 'default', 17.0);
        $this->mockSourceItemForSku('cfg-1-XL', 'eu_source', 10.0);

        $this->sourceItemsSave->expects(self::exactly(2))->method('execute');
        $this->placeReservations->expects(self::never())->method('execute');
        $this->messageManager->expects(self::once())->method('addNoticeMessage');

        $this->service->execute($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
    }

    public function testExecuteThrowsWhenDeductionSourceUnresolvableAtRuntime(): void
    {
        $order = $this->buildOrder();
        $item = $this->buildOrderItem();

        $this->resolver->method('resolveShipmentSources')->willReturn(['default' => 2.0]);
        $this->resolver->method('findReturnSource')->willReturn('default');
        $this->resolver->method('findDeductionSource')->willReturn(null);

        $this->mockSourceItemForSku('cfg-1-XS', 'default', 17.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Stock changed during exchange/');
        $this->service->execute($order, $item, 'cfg-1-XS', 'cfg-1-XL', 2.0);
    }

    // ── Helpers ─────────────────────────────────────────────

    private function buildOrder(): Order|MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->createMock(Order::class);
        $order->method('getStore')->willReturn($store);
        $order->method('getId')->willReturn(123);
        $order->method('getEntityId')->willReturn(123);
        $order->method('getIncrementId')->willReturn('ORD-TEST-001');
        return $order;
    }

    private function buildOrderItem(): OrderItemInterface|MockObject
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn(456);
        return $item;
    }

    private function mockSourceItemForSku(string $sku, string $sourceCode, float $qty): void
    {
        $sourceItem = $this->createMock(SourceItemInterface::class);
        $sourceItem->method('getSourceCode')->willReturn($sourceCode);
        $sourceItem->method('getQuantity')->willReturn($qty);
        $sourceItem->method('getStatus')->willReturn(SourceItemInterface::STATUS_IN_STOCK);
        $sourceItem->expects(self::any())->method('setQuantity');
        $sourceItem->expects(self::any())->method('setStatus');

        $this->sourceItemsBySku[$sku] = [$sourceItem];
    }
}
