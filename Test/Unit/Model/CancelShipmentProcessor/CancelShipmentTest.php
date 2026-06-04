<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\CancelShipmentProcessor;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Inventory\Model\SourceItem;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku;
use Magento\Sales\Api\Data\ShipmentExtensionInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor\CancelShipmentProcessor;
use PHPUnit\Framework\TestCase;

class CancelShipmentTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var CancelShipmentProcessor
     */
    private $cancelShipmentProcessor;

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->cancelShipmentProcessor = $this->objectManagerHelper->getObject(
            CancelShipmentProcessor::class,
            [

            ]
        );
    }

    /**
     * Test that cancelShipment method returns nothing with empty items in shipment
     * and did not thrown an exception.
     *
     * @throws LocalizedException
     */
    public function testCancelShipmentWorksWithEmptyItems()
    {
        $shipment = $this->getMockBuilder(
            Shipment::class
        )->disableOriginalConstructor()
                         ->getMock();

        $shipment->expects($this->atLeastOnce())
                 ->method('getAllItems')
                 ->willReturn([]);

        $this->cancelShipmentProcessor->execute($shipment);
    }

    /**
     * Refunded qty is returned to stock by the credit memo flow, so cancelShipment
     * must exclude it: shipped 5, refunded 1 → return only 4 to source.
     */
    public function testExcludesRefundedQtyFromStockReturn(): void
    {
        $this->assertBackToSource(shipmentQty: 5.0, qtyShipped: 5.0, qtyOrdered: 5.0, qtyRefunded: 1.0, expectedBack: 4.0);
    }

    /**
     * Nothing refunded → full shipped qty returns to source.
     */
    public function testReturnsFullQtyWhenNothingRefunded(): void
    {
        $this->assertBackToSource(shipmentQty: 5.0, qtyShipped: 5.0, qtyOrdered: 5.0, qtyRefunded: 0.0, expectedBack: 5.0);
    }

    /**
     * Fully refunded (item removed) → credit memo owns the whole return, cancel returns 0.
     */
    public function testReturnsZeroWhenFullyRefunded(): void
    {
        $this->assertBackToSource(shipmentQty: 5.0, qtyShipped: 5.0, qtyOrdered: 5.0, qtyRefunded: 5.0, expectedBack: 0.0);
    }

    /**
     * Second sequential edit: the recreated shipment from edit #1 is 4, but only 2
     * remain shippable (ordered 5, cumulative refunded 3). Must return the shippable
     * qty 2 — not the old shipment qty 4, nor shipmentQty−cumulativeRefunded (4−3=1).
     */
    public function testUsesShippableQtyOnSecondEdit(): void
    {
        $this->assertBackToSource(shipmentQty: 4.0, qtyShipped: 4.0, qtyOrdered: 5.0, qtyRefunded: 3.0, expectedBack: 2.0);
    }

    /**
     * Build the processor with controlled collaborators, run execute() and assert the
     * source quantity was bumped by exactly $expectedBack (qtyBefore + expectedBack).
     * sourceItemsSave is made to throw so the reservation path is skipped — the
     * physical return (setQuantity) already happened in the loop before the save.
     */
    private function assertBackToSource(
        float $shipmentQty,
        float $qtyShipped,
        float $qtyOrdered,
        float $qtyRefunded,
        float $expectedBack
    ): void {
        $qtyBefore = 96.0;
        $sku       = 'WH12-XL-Purple';

        $ext = $this->createMock(ShipmentExtensionInterface::class);
        $ext->method('getSourceCode')->willReturn('default');

        $shipmentItem = $this->createMock(ShipmentItem::class);
        $shipmentItem->method('getOrderItemId')->willReturn(612);
        $shipmentItem->method('getSku')->willReturn($sku);
        $shipmentItem->method('getQty')->willReturn($shipmentQty);

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getAllItems')->willReturn([$shipmentItem]);
        $shipment->method('getExtensionAttributes')->willReturn($ext);
        $shipment->method('getOrderId')->willReturn(251);
        $shipment->method('getEntityId')->willReturn(80);

        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getQtyShipped')->willReturn($qtyShipped);
        $orderItem->method('getQtyOrdered')->willReturn($qtyOrdered);
        $orderItem->method('getQtyRefunded')->willReturn($qtyRefunded);

        $orderItemRepository = $this->createMock(OrderItemRepositoryInterface::class);
        $orderItemRepository->method('get')->with(612)->willReturn($orderItem);

        $sourceItem = $this->createMock(SourceItem::class);
        $sourceItem->method('getQuantity')->willReturn($qtyBefore);
        $sourceItem->expects($this->once())
            ->method('setQuantity')
            ->with($qtyBefore + $expectedBack);

        $getSourceItem = $this->createMock(GetSourceItemBySourceCodeAndSku::class);
        $getSourceItem->method('execute')->with('default', $sku)->willReturn($sourceItem);

        // Throw so execute() stops before the reservation path (keeps the test focused).
        $sourceItemsSave = $this->createMock(SourceItemsSaveInterface::class);
        $sourceItemsSave->method('execute')->willThrowException(new CouldNotSaveException(__('stop')));

        $processor = $this->objectManagerHelper->getObject(
            CancelShipmentProcessor::class,
            [
                'orderItemRepository'             => $orderItemRepository,
                'getSourceItemBySourceCodeAndSku' => $getSourceItem,
                'sourceItemsSave'                 => $sourceItemsSave,
            ]
        );

        $processor->execute($shipment);
    }
}
