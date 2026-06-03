<?php
/** Copyright © MageWorx. All rights reserved. See LICENSE.txt */
declare(strict_types = 1);

namespace MageWorx\OrderEditorInventory\Test\Unit\Model\Stock;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use MageWorx\OrderEditorInventory\Model\Stock\SourceCodeResolver;
use PHPUnit\Framework\TestCase;

class SourceCodeResolverTest extends TestCase
{
    private ObjectManagerHelper $omHelper;

    protected function setUp(): void
    {
        $this->omHelper = new ObjectManagerHelper($this);
    }

    /**
     * @dataProvider isSourceUsableProvider
     */
    public function testIsSourceUsable(
        array $availability,
        string $sourceCode,
        float $qtyNeeded,
        bool $backorderAllowed,
        bool $expected
    ): void {
        $resolver = $this->omHelper->getObject(SourceCodeResolver::class);

        $method = new \ReflectionMethod(SourceCodeResolver::class, 'isSourceUsable');
        $method->setAccessible(true);

        self::assertSame(
            $expected,
            $method->invoke($resolver, $availability, $sourceCode, $qtyNeeded, $backorderAllowed)
        );
    }

    /**
     * @return array<string, array{0: array, 1: string, 2: float, 3: bool, 4: bool}>
     */
    public static function isSourceUsableProvider(): array
    {
        $enabled = static fn(float $qty): array => ['quantity' => $qty, 'status' => 1, 'source_enabled' => true];

        return [
            'unknown source'            => [[], 'default', 1.0, false, false],
            'disabled source'           => [['default' => ['quantity' => 99, 'status' => 1, 'source_enabled' => false]], 'default', 1.0, true, false],
            'enough qty'                => [['default' => $enabled(5)], 'default', 3.0, false, true],
            'insufficient, no backorder'=> [['default' => $enabled(1)], 'default', 3.0, false, false],
            'insufficient, backorder ok'=> [['default' => $enabled(1)], 'default', 3.0, true, true],
        ];
    }

    public function testFindReturnSourcePrefersGivenSourceWhenAvailable(): void
    {
        $resolver = $this->buildResolverWithSources([
            'east' => ['qty' => 0.0, 'status' => 2, 'enabled' => true],
            'west' => ['qty' => 5.0, 'status' => 1, 'enabled' => true],
        ]);

        self::assertSame('west', $resolver->findReturnSource('sku', 1, 'west'));
    }

    public function testFindReturnSourceFallsBackToFirstEnabledSource(): void
    {
        $resolver = $this->buildResolverWithSources([
            'east' => ['qty' => 0.0, 'status' => 2, 'enabled' => true],
            'west' => ['qty' => 5.0, 'status' => 1, 'enabled' => true],
        ]);

        // No preferred → first enabled/in-stock source wins (insertion order).
        self::assertSame('east', $resolver->findReturnSource('sku', 1, null));
    }

    public function testFindReturnSourceReturnsNullWhenNoSources(): void
    {
        $resolver = $this->buildResolverWithSources([]);

        self::assertNull($resolver->findReturnSource('sku', 1, null));
    }

    /**
     * @param array<string, array{qty: float, status: int, enabled: bool}> $sources
     */
    private function buildResolverWithSources(array $sources): SourceCodeResolver
    {
        $sourceItems = [];
        $sourceRepository = $this->createMock(SourceRepositoryInterface::class);
        $repoMap = [];

        foreach ($sources as $code => $info) {
            $sourceItem = $this->createMock(SourceItemInterface::class);
            $sourceItem->method('getSourceCode')->willReturn($code);
            $sourceItem->method('getQuantity')->willReturn($info['qty']);
            $sourceItem->method('getStatus')->willReturn($info['status']);
            $sourceItems[] = $sourceItem;

            $source = $this->createMock(SourceInterface::class);
            $source->method('isEnabled')->willReturn($info['enabled']);
            $repoMap[$code] = $source;
        }

        $sourceRepository->method('get')->willReturnCallback(
            fn(string $code) => $repoMap[$code] ?? throw new \Magento\Framework\Exception\NoSuchEntityException(__('x'))
        );

        $getSourceItemsBySku = $this->createMock(GetSourceItemsBySkuInterface::class);
        $getSourceItemsBySku->method('execute')->willReturn($sourceItems);

        return $this->omHelper->getObject(SourceCodeResolver::class, [
            'getSourceItemsBySku' => $getSourceItemsBySku,
            'sourceRepository'    => $sourceRepository,
        ]);
    }
}
