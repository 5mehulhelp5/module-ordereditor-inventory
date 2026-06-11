<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model;

use Magento\Framework\Exception\BulkException;
use Magento\InventorySalesApi\Api\Data\ProductSalabilityErrorInterface;
use Magento\InventorySalesApi\Api\Data\ProductSalableResultInterface;
use Magento\InventorySalesApi\Api\IsProductSalableForRequestedQtyInterface;
use MageWorx\OrderEditorInventory\Model\CheckItemsQuantity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckItemsQuantityTest extends TestCase
{
    /**
     * @var IsProductSalableForRequestedQtyInterface|MockObject
     */
    private $isProductSalableForRequestedQty;

    /**
     * @var CheckItemsQuantity
     */
    private CheckItemsQuantity $checkItemsQuantity;

    protected function setUp(): void
    {
        $this->isProductSalableForRequestedQty = $this->createMock(IsProductSalableForRequestedQtyInterface::class);
        $this->checkItemsQuantity              = new CheckItemsQuantity(
            $this->isProductSalableForRequestedQty
        );
    }

    public function testEmptyItemsReturnsWithoutCallingService(): void
    {
        $this->isProductSalableForRequestedQty->expects($this->never())->method('execute');

        $this->checkItemsQuantity->execute([], 1);
    }

    public function testAllItemsSalableNoException(): void
    {
        $items = [
            'sku-1' => 1.0,
            'sku-2' => 2.5,
        ];

        $salable = $this->createSalableResult(true);
        $this->isProductSalableForRequestedQty->expects($this->exactly(2))
                                              ->method('execute')
                                              ->willReturn($salable);

        $this->checkItemsQuantity->execute($items, 1);
    }

    public function testPartialFailuresAggregatedAsBulkException(): void
    {
        $items = [
            'sku-good' => 1.0,
            'sku-bad'  => 5.0,
        ];

        $okResult   = $this->createSalableResult(true);
        $failResult = $this->createSalableResult(false, 'Out of stock');

        $this->isProductSalableForRequestedQty->method('execute')
                                              ->willReturnCallback(
                                                  function (string $sku) use ($okResult, $failResult) {
                                                      return $sku === 'sku-good' ? $okResult : $failResult;
                                                  }
                                              );

        $this->expectException(BulkException::class);
        $this->checkItemsQuantity->execute($items, 1);
    }

    public function testAllItemsFailingThrowsBulkExceptionWithAllErrors(): void
    {
        $items = [
            'sku-1' => 1.0,
            'sku-2' => 2.0,
        ];

        $failResult = $this->createSalableResult(false, 'Not enough qty');
        $this->isProductSalableForRequestedQty->method('execute')->willReturn($failResult);

        try {
            $this->checkItemsQuantity->execute($items, 1);
            $this->fail('BulkException expected');
        } catch (BulkException $e) {
            $this->assertCount(2, $e->getErrors());
        }
    }

    /**
     * @param bool $salable
     * @param string $errorMessage
     * @return ProductSalableResultInterface|MockObject
     */
    private function createSalableResult(bool $salable, string $errorMessage = '')
    {
        $result = $this->createMock(ProductSalableResultInterface::class);
        $result->method('isSalable')->willReturn($salable);

        if (!$salable) {
            $error = $this->createMock(ProductSalabilityErrorInterface::class);
            $error->method('getMessage')->willReturn($errorMessage);
            $result->method('getErrors')->willReturn([$error]);
        }

        return $result;
    }
}
