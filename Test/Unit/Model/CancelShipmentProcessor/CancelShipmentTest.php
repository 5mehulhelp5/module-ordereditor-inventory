<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\CancelShipmentProcessor;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use MageWorx\OrderEditorInventory\Model\Stock\ReturnProcessor\CancelShipmentProcessor;

class CancelShipmentTest extends \PHPUnit\Framework\TestCase
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
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testCancelShipmentWorksWithEmptyItems()
    {
        $shipment = $this->getMockBuilder(
            \Magento\Sales\Model\Order\Shipment::class
        )->disableOriginalConstructor()
                         ->getMock();

        $shipment->expects($this->atLeastOnce())
                 ->method('getAllItems')
                 ->willReturn([]);

        $this->cancelShipmentProcessor->execute($shipment);
    }
}
