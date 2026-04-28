<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Plugin;

use Magento\InventoryInStorePickupSales\Model\Order\GetPickupLocationCode;
use Magento\InventoryInStorePickupSales\Model\ResourceModel\OrderPickupLocation\SaveOrderPickupLocation;
use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditor\Model\Order\OrderRepository;
use MageWorx\OrderEditorInventory\Plugin\SavePickupLocationForOrderPlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SavePickupLocationForOrderPluginTest extends TestCase
{
    /** @var SaveOrderPickupLocation|MockObject */
    private $saveOrderPickupLocation;

    /** @var GetPickupLocationCode|MockObject */
    private $getPickupLocationCode;

    /** @var OrderRepository|MockObject */
    private $repository;

    private SavePickupLocationForOrderPlugin $plugin;

    protected function setUp(): void
    {
        $this->saveOrderPickupLocation = $this->createMock(SaveOrderPickupLocation::class);
        $this->getPickupLocationCode   = $this->createMock(GetPickupLocationCode::class);
        $this->repository              = $this->createMock(OrderRepository::class);

        $this->plugin = new SavePickupLocationForOrderPlugin(
            $this->saveOrderPickupLocation,
            $this->getPickupLocationCode
        );
    }

    public function testSavesPickupLocationWhenCodeIsPresent(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);

        $this->getPickupLocationCode->expects($this->once())
                                    ->method('execute')
                                    ->with($order)
                                    ->willReturn('source-A');

        $this->saveOrderPickupLocation->expects($this->once())
                                      ->method('execute')
                                      ->with(42, 'source-A');

        $result = $this->plugin->afterSave($this->repository, $order, $order);
        $this->assertSame($order, $result);
    }

    public function testNoOpWhenPickupCodeIsEmpty(): void
    {
        $order = $this->createMock(Order::class);

        $this->getPickupLocationCode->method('execute')->willReturn('');
        $this->saveOrderPickupLocation->expects($this->never())->method('execute');

        $result = $this->plugin->afterSave($this->repository, $order, $order);
        $this->assertSame($order, $result);
    }

    public function testNoOpWhenPickupCodeIsNull(): void
    {
        $order = $this->createMock(Order::class);

        $this->getPickupLocationCode->method('execute')->willReturn(null);
        $this->saveOrderPickupLocation->expects($this->never())->method('execute');

        $result = $this->plugin->afterSave($this->repository, $order, $order);
        $this->assertSame($order, $result);
    }
}
