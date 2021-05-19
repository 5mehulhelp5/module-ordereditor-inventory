<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Api;

interface CancelShipmentProcessorInterface
{
    /**
     * Return all shipped items to stock (cancel shipment)
     *
     * @param \Magento\Sales\Api\Data\ShipmentInterface $shipment
     */
    public function execute(\Magento\Sales\Api\Data\ShipmentInterface $shipment): void;
}
