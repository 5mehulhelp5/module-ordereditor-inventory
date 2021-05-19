<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\CancelShipmentProcessor;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor\CancelShipmentProcessor;
use MageWorx\OrderEditorInventory\Model\StockQtyManager;

class StockQtyManagerTest extends \PHPUnit\Framework\TestCase
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
     * @var StockQtyManager
     */
    private $stockQtyManager;

    /**
     * @var CancelShipmentProcessor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $processCancelledShipmentItemsMock;

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        $this->processCancelledShipmentItemsMock = $this->createMock(CancelShipmentProcessor::class);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->stockQtyManager     = $this->objectManagerHelper->getObject(
            StockQtyManager::class,
            [
                'processCancelledShipmentItems' => $this->processCancelledShipmentItemsMock
            ]
        );
    }

    /**
     * Test that stockQtyManager call the execute method of cancel shipment processor just once and
     * did not thrown an exception.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testCancelShipmentWorks()
    {
        $shipment = $this->getMockBuilder(
            \Magento\Sales\Api\Data\ShipmentInterface::class
        )->disableOriginalConstructor()
                         ->getMock();

        $this->processCancelledShipmentItemsMock->expects($this->once())
                                                ->method('execute')
                                                ->with($shipment);

        $this->stockQtyManager->cancelShipment($shipment);
    }
}
