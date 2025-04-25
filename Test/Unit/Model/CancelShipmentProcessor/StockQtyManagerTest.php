<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\CancelShipmentProcessor;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Api\Data\ShipmentInterface;
use MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor\CancelShipmentProcessor;
use MageWorx\OrderEditorInventory\Model\StockQtyManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StockQtyManagerTest extends TestCase
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
     * @var CancelShipmentProcessor|MockObject
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
     * @throws LocalizedException
     */
    public function testCancelShipmentWorks()
    {
        $shipment = $this->getMockBuilder(
            ShipmentInterface::class
        )->disableOriginalConstructor()
                         ->getMock();

        $this->processCancelledShipmentItemsMock->expects($this->once())
                                                ->method('execute')
                                                ->with($shipment);

        $this->stockQtyManager->cancelShipment($shipment);
    }
}
