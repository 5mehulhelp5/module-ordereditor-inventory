<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace MageWorx\OrderEditorInventory\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Item as OrderItem;

interface StockQtyManagerInterface
{
    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function deductQtyFromStock(OrderItem $orderItem, float $qty = null): void;

    /**
     * @param OrderItem $orderItem
     * @param float|null $qty
     */
    public function returnQtyToStock(OrderItem $orderItem, float $qty = null): void;

    /**
     * Return all shipment items to stock (cancel\delete shipment)
     *
     * @param \Magento\Sales\Api\Data\ShipmentInterface $shipment
     */
    public function cancelShipment(\Magento\Sales\Api\Data\ShipmentInterface $shipment): void;
}
