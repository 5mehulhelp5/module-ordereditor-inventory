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
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
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

    private MultiSourceInventoryManager $manager;

    protected function setUp(): void
    {
        $this->stockQtyManager     = $this->createMock(StockQtyManagerInterface::class);
        $this->getSkusByProductIds = $this->createMock(GetSkusByProductIdsInterface::class);
        $this->getSourceItemsBySku = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->sourceItemsSave     = $this->createMock(SourceItemsSaveInterface::class);

        $this->manager = new MultiSourceInventoryManager(
            $this->stockQtyManager,
            $this->getSkusByProductIds,
            $this->createMock(ItemToSellInterfaceFactory::class),
            $this->createMock(PlaceReservationsForSalesEventInterface::class),
            $this->createMock(SalesEventInterfaceFactory::class),
            $this->createMock(SalesEventExtensionFactory::class),
            $this->createMock(SalesChannelInterfaceFactory::class),
            $this->createMock(WebsiteRepositoryInterface::class),
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
}
