<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Inventory\Model\SourceRepository;
use Magento\InventoryInStorePickupQuote\Model\Address\SetAddressPickupLocation;
use Magento\InventoryInStorePickupSalesAdminUi\Model\GetShippingAddressBySourceCodeAndOriginalAddress;
use Magento\InventoryInStorePickupShippingApi\Model\Carrier\GetCarrierTitle;
use Magento\InventoryInStorePickupShippingApi\Model\Carrier\InStorePickup;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Address\ToOrderAddress as ToOrderAddressConverter;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use MageWorx\OrderEditor\Model\Order as OrderEditorOrder;
use MageWorx\OrderEditorInventory\Model\InventoryPickupLocationTableManager;
use MageWorx\OrderEditorInventory\Observer\InStorePickupHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InStorePickupHandlerTest extends TestCase
{
    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var GetCarrierTitle|MockObject */
    private $getCarrierTitle;

    /** @var SourceRepository|MockObject */
    private $sourceRepository;

    /** @var CartRepositoryInterface|MockObject */
    private $cartRepository;

    /** @var OrderExtensionFactory|MockObject */
    private $orderExtensionFactory;

    /** @var SetAddressPickupLocation|MockObject */
    private $setAddressPickupLocation;

    /** @var ToOrderAddressConverter|MockObject */
    private $quoteAddressToOrderAddress;

    /** @var OrderAddressRepositoryInterface|MockObject */
    private $orderAddressRepository;

    /** @var InventoryPickupLocationTableManager|MockObject */
    private $tableManager;

    /** @var GetShippingAddressBySourceCodeAndOriginalAddress|MockObject */
    private $getShippingAddressBySourceCodeAndOriginalAddress;

    private InStorePickupHandler $handler;

    protected function setUp(): void
    {
        $this->logger                                          = $this->createMock(LoggerInterface::class);
        $this->getCarrierTitle                                 = $this->createMock(GetCarrierTitle::class);
        $this->sourceRepository                                = $this->createMock(SourceRepository::class);
        $this->cartRepository                                  = $this->createMock(CartRepositoryInterface::class);
        $this->orderExtensionFactory                           = $this->createMock(OrderExtensionFactory::class);
        $this->setAddressPickupLocation                        = $this->createMock(SetAddressPickupLocation::class);
        $this->quoteAddressToOrderAddress                      = $this->createMock(ToOrderAddressConverter::class);
        $this->orderAddressRepository                          = $this->createMock(
            OrderAddressRepositoryInterface::class
        );
        $this->tableManager                                    = $this->createMock(
            InventoryPickupLocationTableManager::class
        );
        $this->getShippingAddressBySourceCodeAndOriginalAddress = $this->createMock(
            GetShippingAddressBySourceCodeAndOriginalAddress::class
        );

        $this->handler = new InStorePickupHandler(
            $this->logger,
            $this->getCarrierTitle,
            $this->sourceRepository,
            $this->cartRepository,
            $this->orderExtensionFactory,
            $this->setAddressPickupLocation,
            $this->quoteAddressToOrderAddress,
            $this->orderAddressRepository,
            $this->tableManager,
            $this->getShippingAddressBySourceCodeAndOriginalAddress
        );
    }

    public function testReturnsWhenOrderIsNotMagentoSalesOrder(): void
    {
        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->willReturnMap([
            ['shipping_method', null, InStorePickup::DELIVERY_METHOD],
            ['order', null, new \stdClass()],
        ]);

        $this->cartRepository->expects($this->never())->method('save');
        $this->tableManager->expects($this->never())->method('removeRowByOrderId');

        $this->handler->execute($observer);
    }

    public function testNonPickupMethodCleansPickupTables(): void
    {
        $quoteAddress = $this->createMock(QuoteAddress::class);
        $quoteAddress->method('getData')->with('address_id')->willReturn(77);

        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($quoteAddress);

        $order = $this->createMock(OrderEditorOrder::class);
        $order->method('getQuote')->willReturn($quote);
        $order->method('getEntityId')->willReturn(123);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->willReturnMap([
            ['shipping_method', null, 'flatrate_flatrate'],
            ['order', null, $order],
        ]);

        $this->tableManager->expects($this->once())->method('removeRowByOrderId')->with(123);
        $this->tableManager->expects($this->once())->method('removeRowByQuoteAddressId')->with(77);

        $this->handler->execute($observer);
    }

    public function testVirtualQuoteIsSkipped(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);

        $order = $this->createMock(OrderEditorOrder::class);
        $order->method('getQuote')->willReturn($quote);

        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->willReturnMap([
            ['shipping_method', null, InStorePickup::DELIVERY_METHOD],
            ['order', null, $order],
        ]);

        $this->cartRepository->expects($this->never())->method('save');
        $this->tableManager->expects($this->never())->method('removeRowByOrderId');

        $this->handler->execute($observer);
    }

    public function testGetShippingDescriptionConcatenatesCarrierAndMethod(): void
    {
        $description = $this->handler->getShippingDescription('In-Store Pickup', 'Main Store');
        $this->assertSame('In-Store Pickup - Main Store', $description);
    }

    public function testNonSalesOrderIsIgnoredEvenIfMethodMatches(): void
    {
        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->willReturnMap([
            ['shipping_method', null, InStorePickup::DELIVERY_METHOD],
            ['order', null, null],
        ]);

        $this->cartRepository->expects($this->never())->method('save');
        $this->handler->execute($observer);
    }
}
