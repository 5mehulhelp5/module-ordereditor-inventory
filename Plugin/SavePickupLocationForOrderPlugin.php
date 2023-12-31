<?php

namespace MageWorx\OrderEditorInventory\Plugin;

use Magento\InventoryInStorePickupSales\Model\Order\GetPickupLocationCode;
use Magento\InventoryInStorePickupSales\Model\ResourceModel\OrderPickupLocation\SaveOrderPickupLocation;
use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditor\Model\Order\OrderRepository;

class SavePickupLocationForOrderPlugin
{
    /**
     * @var SaveOrderPickupLocation
     */
    private $saveOrderPickupLocation;

    /**
     * @var GetPickupLocationCode
     */
    private $getPickupLocationCode;

    /**
     * @param SaveOrderPickupLocation $saveOrderPickupLocation
     * @param GetPickupLocationCode $getPickupLocationCode
     */
    public function __construct(
        SaveOrderPickupLocation $saveOrderPickupLocation,
        GetPickupLocationCode $getPickupLocationCode
    ) {
        $this->saveOrderPickupLocation = $saveOrderPickupLocation;
        $this->getPickupLocationCode = $getPickupLocationCode;
    }

    /**
     * @param OrderRepository $subject
     * @param Order $result
     * @param Order $entity
     * @return Order
     */
    public function afterSave(OrderRepository $subject, Order $result, Order $entity): Order
    {
        $pickupLocationCode = $this->getPickupLocationCode->execute($result);

        if ($pickupLocationCode) {
            $this->saveOrderPickupLocation->execute((int)$result->getEntityId(), $pickupLocationCode);
        }

        return $result;
    }
}