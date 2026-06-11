<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageWorx\OrderEditorInventory\Model\Stock\BackorderPolicy;
use PHPUnit\Framework\TestCase;

class BackorderPolicyTest extends TestCase
{
    private const SKU = 'cfg-1-XL';
    private const WEBSITE_ID = 1;
    private const STOCK_ID = 7;

    /**
     * @dataProvider backordersProvider
     */
    public function testReturnsTrueWhenBackordersEnabled(int $backordersValue, bool $expected): void
    {
        $policy = $this->buildPolicy($backordersValue);

        self::assertSame($expected, $policy->isBackorderAllowed(self::SKU, self::WEBSITE_ID));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function backordersProvider(): array
    {
        return [
            'no backorders'       => [0, false],
            'backorders yes'      => [1, true],
            'backorders + notify' => [2, true],
        ];
    }

    public function testReturnsFalseWhenConfigurationUnreadable(): void
    {
        $websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);
        $websiteRepository->method('getById')
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('no website')));

        $policy = (new ObjectManagerHelper($this))->getObject(BackorderPolicy::class, [
            'websiteRepository' => $websiteRepository,
        ]);

        self::assertFalse(
            $policy->isBackorderAllowed(self::SKU, self::WEBSITE_ID),
            'conservative default — no backorders when config cannot be read'
        );
    }

    private function buildPolicy(int $backordersValue): BackorderPolicy
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);
        $websiteRepository->method('getById')->with(self::WEBSITE_ID)->willReturn($website);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(self::STOCK_ID);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);

        $config = $this->createMock(StockItemConfigurationInterface::class);
        $config->method('getBackorders')->willReturn($backordersValue);
        $getConfig = $this->createMock(GetStockItemConfigurationInterface::class);
        $getConfig->method('execute')->with(self::SKU, self::STOCK_ID)->willReturn($config);

        return (new ObjectManagerHelper($this))->getObject(BackorderPolicy::class, [
            'getStockItemConfiguration' => $getConfig,
            'stockResolver'             => $stockResolver,
            'websiteRepository'         => $websiteRepository,
        ]);
    }
}
