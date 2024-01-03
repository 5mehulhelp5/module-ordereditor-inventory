<?php

namespace MageWorx\OrderEditorInventory\Observer;

use Psr\Log\LoggerInterface;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

use Magento\Inventory\Model\SourceRepository;
use Magento\InventoryInStorePickupShippingApi\Model\Carrier\InStorePickup;
use Magento\InventoryInStorePickupShippingApi\Model\Carrier\GetCarrierTitle;
use Magento\InventoryInStorePickupQuote\Model\Address\SetAddressPickupLocation;
use Magento\InventoryInStorePickupSalesAdminUi\Model\GetShippingAddressBySourceCodeAndOriginalAddress;

use Magento\Quote\Model\Quote;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Address\ToOrderAddress as ToOrderAddressConverter;

use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\OrderAddressRepositoryInterface;

use MageWorx\OrderEditorInventory\Model\InventoryPickupLocationTableManager;

/**
 * @todo Should I change only for shipping? Or should I change Billing data as well, e.g. shipping_description?
 */
class InStorePickupHandler implements ObserverInterface
{
    private ?\Magento\Store\Model\Store $store = null;

    private LoggerInterface $logger;
    private GetCarrierTitle $getCarrierTitle;
    private SourceRepository $sourceRepository;
    private CartRepositoryInterface $cartRepository;
    private OrderExtensionFactory $orderExtensionFactory;
    private SetAddressPickupLocation $setAddressPickupLocation;
    private ToOrderAddressConverter $quoteAddressToOrderAddress;
    private OrderAddressRepositoryInterface $orderAddressRepository;
    private InventoryPickupLocationTableManager $inventoryPickupLocationTableManager;
    private GetShippingAddressBySourceCodeAndOriginalAddress $getShippingAddressBySourceCodeAndOriginalAddress;

    /**
     * @param LoggerInterface $logger
     * @param GetCarrierTitle $getCarrierTitle
     * @param SourceRepository $sourceRepository
     * @param CartRepositoryInterface $cartRepository
     * @param OrderExtensionFactory $orderExtensionFactory
     * @param SetAddressPickupLocation $setAddressPickupLocation
     * @param ToOrderAddressConverter $quoteAddressToOrderAddress
     * @param OrderAddressRepositoryInterface $orderAddressRepository
     * @param InventoryPickupLocationTableManager $inventoryPickupLocationTableManager
     * @param GetShippingAddressBySourceCodeAndOriginalAddress $getShippingAddressBySourceCodeAndOriginalAddress
     */
    public function __construct(
        LoggerInterface $logger,
        GetCarrierTitle $getCarrierTitle,
        SourceRepository $sourceRepository,
        CartRepositoryInterface $cartRepository,
        OrderExtensionFactory $orderExtensionFactory,
        SetAddressPickupLocation $setAddressPickupLocation,
        ToOrderAddressConverter $quoteAddressToOrderAddress,
        OrderAddressRepositoryInterface $orderAddressRepository,
        InventoryPickupLocationTableManager $inventoryPickupLocationTableManager,
        GetShippingAddressBySourceCodeAndOriginalAddress $getShippingAddressBySourceCodeAndOriginalAddress
    ) {
        $this->logger = $logger;
        $this->cartRepository = $cartRepository;
        $this->getCarrierTitle = $getCarrierTitle;
        $this->sourceRepository = $sourceRepository;
        $this->orderExtensionFactory = $orderExtensionFactory;
        $this->setAddressPickupLocation = $setAddressPickupLocation;
        $this->quoteAddressToOrderAddress = $quoteAddressToOrderAddress;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->inventoryPickupLocationTableManager = $inventoryPickupLocationTableManager;
        $this->getShippingAddressBySourceCodeAndOriginalAddress = $getShippingAddressBySourceCodeAndOriginalAddress;
    }

    public function execute(Observer $observer)
    {
        try {
            $shippingMethod = (string)$observer->getData('shipping_method');
            /**
             * @var \MageWorx\OrderEditor\Model\Order $order
             */
            $order = $observer->getData('order');
            if (!is_a($order, \Magento\Sales\Model\Order::class)) {
                return;
            }

            $quote = $order->getQuote();

            if ($shippingMethod === InStorePickup::DELIVERY_METHOD) {

                if ($quote->isVirtual()) {
                    return;
                }

                $shippingModel = $observer->getData('shipping_model');
                $pickupLocationCode = $shippingModel->getPickUpStore() ?? '';

                $this->changeQuoteAddressToPickupLocation($quote, $pickupLocationCode);
                $this->changeOrderAddressToPickupLocation($order, $quote, $pickupLocationCode);
                $this->setOrderExtensionAttribute($order, $pickupLocationCode);

                /**
                 * @todo add a pickup_location_code column update to the sales_order_grid table
                 */

                $description = $this->getOrderShippingDescription($order, $pickupLocationCode);

                $order->setShippingDescription($description);
            } else {
                $this->inventoryPickupLocationTableManager->removeRowByOrderId((int)$order->getEntityId());

                $quoteShippingAddress = $quote->getShippingAddress();
                $this->inventoryPickupLocationTableManager->removeRowByQuoteAddressId($quoteShippingAddress->getData('address_id') ?? 0);
            }
        } catch (LocalizedException $localizedException) {
            $this->logger->alert($localizedException->getLogMessage());
        }
    }

    /**
     * @throws NoSuchEntityException
     */
    protected function changeQuoteAddressToPickupLocation(Quote $quote, string $pickupLocationCode): void
    {
        $address = $quote->getShippingAddress();
        if ($pickupLocationCode && $address->getShippingMethod() === InStorePickup::DELIVERY_METHOD) {
            $this->setAddressPickupLocation->execute($address, $pickupLocationCode);
            $quote->setShippingAddress(
                $this->getShippingAddressBySourceCodeAndOriginalAddress->execute($pickupLocationCode, $address)
            );
        }
        $this->cartRepository->save($quote);
    }

    /**
     * @throws NoSuchEntityException
     */
    protected function changeOrderAddressToPickupLocation(Order $order, Quote $quote, string $pickupLocationCode): void
    {
        $quoteShippingAddress = $quote->getShippingAddress();

        try {
            $source = $this->sourceRepository->get($pickupLocationCode);
            $additionalAddressInformation = [
                'address_type' => 'shipping',
                'firstname'    => $source->getData('frontend_name') ?? $source->getName(),
                'email'        => $source->getEmail(),
                'telephone'    => $source->getPhone()
            ];
        } catch (NoSuchEntityException $exception) {
            $additionalAddressInformation = [
                'address_type' => 'shipping',
                'email'        => $quote->getCustomerEmail()
            ];
        }

        $additionalAddressInformation['middlename'] = '';
        $additionalAddressInformation['lastname'] = '';

        $shippingAddress = $this->quoteAddressToOrderAddress->convert(
            $quoteShippingAddress,
            $additionalAddressInformation
        );

        $shippingAddress->setData('quote_address_id', $quote->getShippingAddress()->getId());
        $orderShippingAddress = $order->getShippingAddress();

        if (!is_null($orderShippingAddress) && !empty($shippingAddress->getData())) {
            $orderShippingAddress->addData($shippingAddress->getData());
            $this->orderAddressRepository->save($orderShippingAddress);
        }
    }

    protected function setOrderExtensionAttribute(Order $order, string $pickupLocationCode = ''): void
    {
        if ('' === $pickupLocationCode) {
            return;
        }

        $extension = $order->getExtensionAttributes();

        if (null === $extension) {
            $extension = $this->orderExtensionFactory->create();
        }

        $extension->setPickupLocationCode($pickupLocationCode);
        $order->setExtensionAttributes($extension);
    }

    public function getShippingDescription(string $carrierName, string $methodName): string
    {
        return $carrierName . ' - ' . $methodName;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getOrderShippingDescription(\Magento\Sales\Model\Order $order, string $pickupLocationCode): string
    {
        $source = $this->sourceRepository->get($pickupLocationCode);
        $sourceName = $source->getData('frontend_name') ?? $source->getName();
        $carrierTitle = $this->getCarrierTitle->execute($order->getStoreId());
        if (empty($carrierTitle) || empty($sourceName)) {
            return $order->getShippingDescription();
        }
        return $this->getShippingDescription($carrierTitle, $sourceName);
    }
}
