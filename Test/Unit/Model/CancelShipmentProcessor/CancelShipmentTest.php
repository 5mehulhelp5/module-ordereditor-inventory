<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\CancelShipmentProcessor;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Model\Order\Shipment;
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
}
