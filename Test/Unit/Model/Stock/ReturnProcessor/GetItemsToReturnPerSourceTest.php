<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock\ReturnProcessor;

use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemFactory;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResult;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResultFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\Store;
use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor\GetItemsToReturnPerSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GetItemsToReturnPerSourceTest extends TestCase
{
    /** @var GetSkuFromOrderItemInterface|MockObject */
    private $getSkuFromOrderItem;

    /** @var StockByWebsiteIdResolverInterface|MockObject */
    private $stockByWebsiteIdResolver;

    /** @var GetSourcesAssignedToStockOrderedByPriorityInterface|MockObject */
    private $getSourcesAssignedToStockOrderedByPriority;

    /** @var GetSourceItemsBySkuInterface|MockObject */
    private $getSourceItemsBySku;

    /** @var DefaultSourceProviderInterface|MockObject */
    private $defaultSourceProvider;

    /** @var SourceDeductedOrderItemFactory|MockObject */
    private $sourceDeductedOrderItemFactory;

    /** @var SourceDeductedOrderItemsResultFactory|MockObject */
    private $sourceDeductedOrderItemsResultFactory;

    private GetItemsToReturnPerSource $processor;

    protected function setUp(): void
    {
        $this->getSkuFromOrderItem                        = $this->createMock(GetSkuFromOrderItemInterface::class);
        $this->stockByWebsiteIdResolver                   = $this->createMock(StockByWebsiteIdResolverInterface::class);
        $this->getSourcesAssignedToStockOrderedByPriority = $this->createMock(
            GetSourcesAssignedToStockOrderedByPriorityInterface::class
        );
        $this->getSourceItemsBySku                        = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->defaultSourceProvider                      = $this->createMock(DefaultSourceProviderInterface::class);
        $this->sourceDeductedOrderItemFactory             = $this->createMock(SourceDeductedOrderItemFactory::class);
        $this->sourceDeductedOrderItemsResultFactory      = $this->createMock(
            SourceDeductedOrderItemsResultFactory::class
        );

        $this->processor = new GetItemsToReturnPerSource(
            $this->getSkuFromOrderItem,
            $this->stockByWebsiteIdResolver,
            $this->getSourcesAssignedToStockOrderedByPriority,
            $this->getSourceItemsBySku,
            $this->defaultSourceProvider,
            $this->sourceDeductedOrderItemFactory,
            $this->sourceDeductedOrderItemsResultFactory
        );
    }

    public function testRegularOrderIsSkipped(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->sourceDeductedOrderItemsResultFactory->expects($this->never())->method('create');

        $result = $this->processor->execute($order, [1, 2]);
        $this->assertSame([], $result);
    }

    public function testReturnsHighestPrioritySource(): void
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getId')->willReturn(11);
        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('isDummy')->willReturn(false);

        $invoiceItem = $this->createMock(InvoiceItem::class);
        $invoiceItem->method('getOrderItem')->willReturn($orderItem);
        $invoiceItem->method('getQty')->willReturn(2.0);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getItems')->willReturn([$invoiceItem]);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $order = $this->createMock(Order::class);
        $order->method('getInvoiceCollection')->willReturn([$invoice]);
        $order->method('getStore')->willReturn($store);

        $this->getSkuFromOrderItem->method('execute')->willReturn('sku-1');

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(7);
        $this->stockByWebsiteIdResolver->method('execute')->with(1)->willReturn($stock);

        $this->defaultSourceProvider->method('getCode')->willReturn('default');

        $available = $this->createMock(SourceItemInterface::class);
        $available->method('getSourceCode')->willReturn('source-A');
        $this->getSourceItemsBySku->method('execute')->with('sku-1')->willReturn([$available]);

        $assignedHigh = $this->createMock(SourceInterface::class);
        $assignedHigh->method('getSourceCode')->willReturn('source-A');
        $assignedLow = $this->createMock(SourceInterface::class);
        $assignedLow->method('getSourceCode')->willReturn('source-B');
        $this->getSourcesAssignedToStockOrderedByPriority->method('execute')
                                                         ->with(7)
                                                         ->willReturn([$assignedHigh, $assignedLow]);

        $deductedItem = new \stdClass();
        $this->sourceDeductedOrderItemFactory->expects($this->once())
                                             ->method('create')
                                             ->with(['sku' => 'sku-1', 'quantity' => 2.0])
                                             ->willReturn($deductedItem);

        $resultObj = $this->createMock(SourceDeductedOrderItemsResult::class);
        $this->sourceDeductedOrderItemsResultFactory->expects($this->once())
                                                    ->method('create')
                                                    ->with(['sourceCode' => 'source-A', 'items' => [$deductedItem]])
                                                    ->willReturn($resultObj);

        $result = $this->processor->execute($order, [11]);
        $this->assertSame([$resultObj], $result);
    }

    public function testItemsNotInReturnListAreSkipped(): void
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getId')->willReturn(99);
        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('isDummy')->willReturn(false);

        $invoiceItem = $this->createMock(InvoiceItem::class);
        $invoiceItem->method('getOrderItem')->willReturn($orderItem);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getItems')->willReturn([$invoiceItem]);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $order = $this->createMock(Order::class);
        $order->method('getInvoiceCollection')->willReturn([$invoice]);
        $order->method('getStore')->willReturn($store);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $this->stockByWebsiteIdResolver->method('execute')->willReturn($stock);

        $this->sourceDeductedOrderItemFactory->expects($this->never())->method('create');

        $result = $this->processor->execute($order, [1]);
        $this->assertSame([], $result);
    }

    public function testDummyItemsAreSkipped(): void
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getId')->willReturn(11);
        $orderItem->method('isDummy')->willReturn(true);

        $invoiceItem = $this->createMock(InvoiceItem::class);
        $invoiceItem->method('getOrderItem')->willReturn($orderItem);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getItems')->willReturn([$invoiceItem]);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $order = $this->createMock(Order::class);
        $order->method('getInvoiceCollection')->willReturn([$invoice]);
        $order->method('getStore')->willReturn($store);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $this->stockByWebsiteIdResolver->method('execute')->willReturn($stock);

        $this->sourceDeductedOrderItemFactory->expects($this->never())->method('create');

        $result = $this->processor->execute($order, [11]);
        $this->assertSame([], $result);
    }
}
