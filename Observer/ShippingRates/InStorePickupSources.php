<?php

namespace MageWorx\OrderEditorInventory\Observer\ShippingRates;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventoryInStorePickupSalesAdminUi\Model\GetPickupSources;
use Magento\InventoryInStorePickupSales\Model\ResourceModel\OrderPickupLocation\GetPickupLocationCodeByOrderId;

class InStorePickupSources extends \MageWorx\OrderEditor\Observer\ShippingRates\AbstractPickupSourcesObserver
{
    private const CODE = 'instore_pickup';

    protected StockResolverInterface $stockResolver;
    protected GetPickupSources $getPickupSources;
    protected GetPickupLocationCodeByOrderId $getPickupLocationCodeByOrderId;

    public function __construct(
        StockResolverInterface $stockResolver,
        GetPickupSources $getPickupSources,
        GetPickupLocationCodeByOrderId $getPickupLocationCodeByOrderId

    ) {
        $this->stockResolver = $stockResolver;
        $this->getPickupSources = $getPickupSources;
        $this->getPickupLocationCodeByOrderId = $getPickupLocationCodeByOrderId;
    }

    /**
     * @return array
     */
    protected function getList(): array
    {
        $list = [];
        try {
            $stockId = $this->getStockId();
            if (is_null($stockId)) {
                return $list;
            }

            $currentPickupLocCode = '';
            if ($this->getOrder()) {
                $currentPickupLocCode =
                    (string)$this->getPickupLocationCodeByOrderId->execute($this->getOrder()->getEntityId());
            }

            $pickupSources = $this->getPickupSources->execute($stockId) ?? [];
            /** @var SourceInterface $source */
            foreach ($pickupSources as $source) {
                if ($currentPickupLocCode !== '' && $this->isSelectedSource($source, $currentPickupLocCode)) {
                    $list[$source->getSourceCode()] = ['name' => $source->getName(), 'attributes' => 'selected'];
                } else {
                    $list[$source->getSourceCode()] = $source->getName();
                }
            }
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            //
        }

        return $list;
    }

    /**
     * @return int|null
     * @throws NoSuchEntityException
     * @see \Magento\InventoryInStorePickupSalesAdminUi\ViewModel\CreateOrder\SourcesForm::getStockId
     */
    protected function getStockId(): ?int
    {
        if ($this->getQuote() === null) {
            return null;
        }

        return $this->stockResolver->execute(
            SalesChannelInterface::TYPE_WEBSITE,
            $this->getQuote()->getStore()->getWebsite()->getCode()
        )->getStockId();
    }

    protected function getCode(): string
    {
        return self::CODE;
    }

    protected function isSelectedSource(\Magento\InventoryApi\Api\Data\SourceInterface $source, string $pickupCode): bool
    {
        return (string)$source->getSourceCode() === $pickupCode;
    }
}
