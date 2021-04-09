<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

class MultiSourceInventoryManager implements \MageWorx\OrderEditor\Api\StockManagerInterface
{
    /**
     * @var \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface
     */
    private $stockQtyManager;

    /**
     * MultiSourceInventoryManager constructor.
     *
     * @param \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface $stockQtyManager
     */
    public function __construct(
        \MageWorx\OrderEditorInventory\Api\StockQtyManagerInterface $stockQtyManager
    ) {
        $this->stockQtyManager = $stockQtyManager;
    }

    /**
     * @inheritDoc
     */
    public function registerReturn(\Magento\Sales\Api\Data\OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->returnQtyToStock($item, $qty);
    }

    /**
     * @inheritDoc
     */
    public function registerReturnByProductId(int $productId, float $qty, int $websiteId): void
    {
        // TODO: Implement registerReturnByProductId() method.
    }

    /**
     * @inheritDoc
     */
    public function registerSale(\Magento\Sales\Api\Data\OrderItemInterface $item, float $qty): void
    {
        $this->stockQtyManager->deductQtyFromStock($item, $qty);
    }
}
