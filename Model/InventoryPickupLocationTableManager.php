<?php

namespace MageWorx\OrderEditorInventory\Model;

use Magento\Framework\App\ResourceConnection;

class InventoryPickupLocationTableManager
{
    private const CONNECTION_NAME                         = 'sales';
    private const INVENTORY_PICKUP_LOCATION_ORDER         = 'inventory_pickup_location_order';
    private const INVENTORY_PICKUP_LOCATION_QUOTE_ADDRESS = 'inventory_pickup_location_quote_address';

    private ResourceConnection $connection;

    /**
     * @param ResourceConnection $connection
     */
    public function __construct(
        ResourceConnection $connection
    ) {
        $this->connection = $connection;
    }

    public function removeRowByOrderId(int $id)
    {
        if ($this->isValidId($id)) {
            $table = $this->connection->getTableName(self::INVENTORY_PICKUP_LOCATION_ORDER, self::CONNECTION_NAME);
            $this->removeFromTableById($table, ['order_id = ?' => $id]);
        }
    }

    public function removeRowByQuoteAddressId(int $id)
    {
        if ($this->isValidId($id)) {
            $table =
                $this->connection->getTableName(self::INVENTORY_PICKUP_LOCATION_QUOTE_ADDRESS, self::CONNECTION_NAME);
            $this->removeFromTableById($table, ['address_id = ?' => $id]);
        }
    }

    protected function removeFromTableById(string $table, array $where)
    {
        $connection = $this->connection->getConnection(self::CONNECTION_NAME);
        $connection->delete($table, $where);
    }

    protected function isValidId(int $id)
    {
        return $id > 0;
    }
}