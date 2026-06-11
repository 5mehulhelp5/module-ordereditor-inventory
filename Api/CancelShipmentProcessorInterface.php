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
     * Return shipped items to stock (cancel shipment).
     *
     * @param ShipmentInterface $shipment
     * @param array<int, float> $remainingByOrderItem Shared budget [orderItemId => qty left to return],
     *        carried across all shipments of one order cancelled in a single pass so the total
     *        returned equals the still-shippable qty (not the sum of per-shipment caps). Pass an
     *        empty array for a standalone cancel; it is initialized lazily per order item.
     */
    public function execute(ShipmentInterface $shipment, array &$remainingByOrderItem = []): void;
}
