<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * A class to manage records in the inventory_pickup_location_order and inventory_pickup_location_quote_address tables
 */
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

    /**
     * Deletes one record by its id from inventory_pickup_location_order table
     * @param int $id
     * @return void
     */
    public function removeRowByOrderId(int $id): void
    {
        if ($this->isValidId($id)) {
            $table = $this->connection->getTableName(self::INVENTORY_PICKUP_LOCATION_ORDER, self::CONNECTION_NAME);
            $this->removeFromTableById($table, ['order_id = ?' => $id]);
        }
    }

    /**
     * Deletes one record by its id from inventory_pickup_location_quote_address table
     * @param int $id
     * @return void
     */
    public function removeRowByQuoteAddressId(int $id): void
    {
        if ($this->isValidId($id)) {
            $table =
                $this->connection->getTableName(self::INVENTORY_PICKUP_LOCATION_QUOTE_ADDRESS, self::CONNECTION_NAME);
            $this->removeFromTableById($table, ['address_id = ?' => $id]);
        }
    }

    /**
     * Deletes one record by its id
     * @param string $table
     * @param array $where
     * @return void
     */
    protected function removeFromTableById(string $table, array $where): void
    {
        $connection = $this->connection->getConnection(self::CONNECTION_NAME);
        $connection->delete($table, $where);
    }

    protected function isValidId(int $id): bool
    {
        return $id > 0;
    }
}
