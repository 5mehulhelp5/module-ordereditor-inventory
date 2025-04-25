<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Api;

use Magento\Sales\Api\Data\ShipmentInterface;

interface CancelShipmentProcessorInterface
{
    /**
     * Return all shipped items to stock (cancel shipment)
     *
     * @param ShipmentInterface $shipment
     */
    public function execute(ShipmentInterface $shipment): void;
}
