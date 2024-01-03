<?php

namespace MageWorx\OrderEditorInventory\Observer\ShippingRates;

use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventoryInStorePickupSalesAdminUi\Model\GetPickupSources;
use Magento\InventoryInStorePickupSales\Model\ResourceModel\OrderPickupLocation\GetPickupLocationCodeByOrderId;

use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditor\Model\Quote;

class InStorePickupSources implements \Magento\Framework\Event\ObserverInterface
{
    private const CODE = 'instore_pickup';

    protected ?Quote $quote;
    protected ?Order $order;
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
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $rates = $observer->getData('rates');

        if (!empty($rates)) {
            /**
             * @var \MageWorx\OrderEditor\Block\Adminhtml\Sales\Order\Edit\Form\Shipping\Method $shippingMethodForm
             */
            $shippingMethodForm = $observer->getData('shipping_method_form');
            if (
                !is_a($shippingMethodForm, \Magento\Framework\DataObject::class) ||
                !is_a($shippingMethodForm->getQuote(), \Magento\Quote\Model\Quote::class)
            ) {
                return;
            }

            $this->quote = $shippingMethodForm->getQuote();
            $this->order = $observer->getData('order');
            if (!is_a($this->order, \Magento\Framework\DataObject::class)) {
                $this->order = null;
            }

            $sourcesList = $this->getSourcesList();

            if (empty($sourcesList)) {
                return;
            }

            foreach ($rates as $rateList) {
                foreach ($rateList as $rate) {
                    if ((string)$rate->getCode() === $this->getCode()) {
                        $rate->setData('child_elements', $sourcesList);
                        break 2;
                    }
                }
            }
        }
    }

    /**
     * @return array
     */
    protected function getSourcesList(): array
    {
        $sourcesList = [];
        try {
            $stockId = $this->getStockId();
            if (is_null($stockId)) {
                return $sourcesList;
            }

            $currentPickupLocCode = '';
            if ($this->order) {
                $currentPickupLocCode = (string)$this->getPickupLocationCodeByOrderId->execute($this->order->getEntityId());
            }

            $pickupSources = $this->getPickupSources->execute($stockId) ?? [];
            /** @var SourceInterface $source */
            foreach ($pickupSources as $source) {
                if ($currentPickupLocCode !== '' && $this->isSelectedSource($source, $currentPickupLocCode)) {
                    $sourcesList[$source->getSourceCode()] = ['name' => $source->getName(), 'attributes' => 'selected'];
                } else {
                    $sourcesList[$source->getSourceCode()] = $source->getName();
                }
            }
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            //
        }

        return $sourcesList;
    }

    /**
     * @return int|null
     * @throws NoSuchEntityException
     * @see \Magento\InventoryInStorePickupSalesAdminUi\ViewModel\CreateOrder\SourcesForm::getStockId
     */
    protected function getStockId(): ?int
    {
        if ($this->quote === null) {
            return null;
        }

        return $this->stockResolver->execute(
            SalesChannelInterface::TYPE_WEBSITE,
            $this->quote->getStore()->getWebsite()->getCode()
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
