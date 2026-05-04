<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Model\Stock;

use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Decides whether a given SKU is allowed to deduct beyond the available
 * physical source qty (i.e. allow source_item.quantity to go negative or stay
 * at 0 with a backorder accounting in MSI).
 *
 * Magento per-product `Stock\Item::getBackorders()` values:
 *   0 = NO        — must respect physical qty; insufficient stock is an error.
 *   1 = YES       — backorders allowed (silent).
 *   2 = YES + notify customer.
 *
 * For the swap physical exchange use case any non-zero value is treated as
 * "admin can deduct beyond available qty" — the admin has explicitly
 * confirmed they are physically shipping the new SKU, so MSI should not
 * second-guess them. Stock can either go negative (legacy mode) or be
 * recorded as backorder qty (MSI handles the accounting).
 */
class BackorderPolicy
{
    public function __construct(
        private GetStockItemConfigurationInterface $getStockItemConfiguration,
        private StockResolverInterface $stockResolver,
        private WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * @param string $sku
     * @param int $websiteId
     * @return bool true if backorders are allowed for this SKU on the
     *              website's stock; false if NOT allowed (No-backorder).
     */
    public function isBackorderAllowed(string $sku, int $websiteId): bool
    {
        try {
            $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
            $stock = $this->stockResolver->execute(ScopeInterface::SCOPE_WEBSITE, $websiteCode);
            $stockItemConfiguration = $this->getStockItemConfiguration->execute(
                $sku,
                (int) $stock->getStockId()
            );
            return $stockItemConfiguration->getBackorders() !== 0;
        } catch (\Throwable $e) {
            // Conservative default: if we cannot read configuration (missing
            // assignment, deleted product), treat as "no backorders" so the
            // exchange routine falls back to source priority instead of
            // silently allowing negative stock.
            return false;
        }
    }
}
