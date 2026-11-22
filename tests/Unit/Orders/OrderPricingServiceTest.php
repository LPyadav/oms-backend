<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Services\Orders\OrderPricingService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @phpstan-import-type LineInput from OrderPricingService
 */
final class OrderPricingServiceTest extends TestCase
{
    public function test_it_reconciles_the_assessment_totals(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 10_000, quantity: 2, taxBasisPoints: 1_000),
            self::line(2, 5_000, quantity: 3, taxBasisPoints: 1_000),
        ], 2_000);

        self::assertSame(35_000, $result['subtotal_minor']);
        self::assertSame(2_000, $result['order_discount_minor']);
        self::assertSame(2_000, $result['discount_total_minor']);
        self::assertSame(3_300, $result['tax_total_minor']);
        self::assertSame(36_300, $result['grand_total_minor']);
        self::assertSame([1_143, 857], array_column($result['items'], 'allocated_order_discount_minor'));
        self::assertSame([1_886, 1_414], array_column($result['items'], 'tax_minor'));
    }

    public function test_it_calculates_mixed_tax_after_item_and_order_discounts(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 10_000, taxBasisPoints: 1_000, itemDiscountMinor: 1_000),
            self::line(2, 6_000, taxBasisPoints: 500),
        ], 1_500);

        self::assertSame(16_000, $result['subtotal_minor']);
        self::assertSame(2_500, $result['discount_total_minor']);
        self::assertSame(1_080, $result['tax_total_minor']);
        self::assertNull($result['order_tax_rate_basis_points']);
        self::assertSame([1_000, 500], array_column($result['items'], 'base_tax_rate_basis_points'));
        self::assertSame([1_000, 500], array_column($result['items'], 'tax_rate_basis_points'));
        self::assertSame(14_580, $result['grand_total_minor']);
        self::assertSame([900, 600], array_column($result['items'], 'allocated_order_discount_minor'));
        self::assertSame([810, 270], array_column($result['items'], 'tax_minor'));
        self::assertSame([8_910, 5_670], array_column($result['items'], 'line_total_minor'));
    }

    public function test_order_override_rounds_once_after_discounts_and_allocates_tax_to_items(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(9, 10, taxBasisPoints: 1_000, itemDiscountMinor: 2),
            self::line(4, 9, taxBasisPoints: 2_000, itemDiscountMinor: 1),
            self::line(3, 4, taxBasisPoints: 800, itemDiscountMinor: 4),
        ], 6, orderTaxRateBasisPoints: 1_000);

        self::assertSame(23, $result['subtotal_minor']);
        self::assertSame(13, $result['discount_total_minor']);
        self::assertSame(1_000, $result['order_tax_rate_basis_points']);
        self::assertSame(1, $result['tax_total_minor']);
        self::assertSame(11, $result['grand_total_minor']);
        self::assertSame([3, 3, 0], array_column($result['items'], 'allocated_order_discount_minor'));
        self::assertSame([1_000, 2_000, 800], array_column($result['items'], 'base_tax_rate_basis_points'));
        self::assertSame([1_000, 1_000, 1_000], array_column($result['items'], 'tax_rate_basis_points'));
        self::assertSame([0, 1, 0], array_column($result['items'], 'tax_minor'));
        self::assertSame([5, 6, 0], array_column($result['items'], 'line_total_minor'));
    }

    public function test_zero_order_override_preserves_the_original_item_rate(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 100, taxBasisPoints: 1_000),
        ], 0, orderTaxRateBasisPoints: 0);

        self::assertSame(0, $result['order_tax_rate_basis_points']);
        self::assertSame(0, $result['tax_total_minor']);
        self::assertSame(100, $result['grand_total_minor']);
        self::assertSame(1_000, $result['items'][0]['base_tax_rate_basis_points']);
        self::assertSame(0, $result['items'][0]['tax_rate_basis_points']);
        self::assertSame(0, $result['items'][0]['tax_minor']);
    }

    public function test_order_override_on_a_fully_discounted_order_allocates_zero_tax(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 100, taxBasisPoints: 1_000, itemDiscountMinor: 60),
            self::line(2, 50, taxBasisPoints: 800),
        ], 90, orderTaxRateBasisPoints: 2_000);

        self::assertSame(2_000, $result['order_tax_rate_basis_points']);
        self::assertSame(0, $result['tax_total_minor']);
        self::assertSame(0, $result['grand_total_minor']);
        self::assertSame([0, 0], array_column($result['items'], 'tax_minor'));
    }

    public function test_it_breaks_equal_remainders_by_product_id_and_rounds_half_up_per_line(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(9, 1, taxBasisPoints: 5_000),
            self::line(4, 1, taxBasisPoints: 5_000),
            self::line(7, 1, taxBasisPoints: 5_000),
        ], 2);

        self::assertSame([9, 4, 7], array_column($result['items'], 'product_id'));
        self::assertSame([1, 2, 3], array_column($result['items'], 'position'));
        self::assertSame([0, 1, 1], array_column($result['items'], 'allocated_order_discount_minor'));
        self::assertSame([1, 0, 0], array_column($result['items'], 'tax_minor'));
        self::assertSame(2, $result['grand_total_minor']);
    }

    public function test_it_allows_a_fully_discounted_order(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 1_000, taxBasisPoints: 1_000, itemDiscountMinor: 1_000),
        ], 0);

        self::assertSame(1_000, $result['subtotal_minor']);
        self::assertSame(1_000, $result['discount_total_minor']);
        self::assertSame(0, $result['tax_total_minor']);
        self::assertSame(0, $result['grand_total_minor']);
    }

    public function test_it_allocates_large_discounts_without_overflowing_intermediate_products(): void
    {
        $result = (new OrderPricingService)->calculate([
            self::line(1, 10_000_000_000),
            self::line(2, 10_000_000_000),
        ], 15_000_000_001);

        self::assertSame([7_500_000_001, 7_500_000_000], array_column($result['items'], 'allocated_order_discount_minor'));
        self::assertSame(4_999_999_999, $result['grand_total_minor']);
    }

    /**
     * @param  list<LineInput>  $lines
     */
    #[DataProvider('invalidPricing')]
    public function test_it_reports_invalid_amounts_and_inputs(
        array $lines,
        int $discount,
        string $field,
        ?int $orderTaxRateBasisPoints = null,
    ): void {
        try {
            (new OrderPricingService)->calculate($lines, $discount, $orderTaxRateBasisPoints);
        } catch (ValidationException $exception) {
            self::assertSame([$field], array_keys($exception->errors()));

            return;
        }

        self::fail('Expected a pricing validation exception.');
    }

    /**
     * @return array<string, array{0: list<LineInput>, 1: int, 2: string, 3?: int}>
     */
    public static function invalidPricing(): array
    {
        return [
            'no items' => [[], 0, 'items'],
            'zero quantity' => [[self::line(1, 100, quantity: 0)], 0, 'items.0'],
            'catalog tax exceeds 100 percent' => [[self::line(1, 100, taxBasisPoints: 10_001)], 0, 'items.0'],
            'duplicate product' => [[self::line(1, 100), self::line(1, 100)], 0, 'items.1.product_id'],
            'item discount exceeds subtotal' => [[self::line(1, 100, itemDiscountMinor: 101)], 0, 'items.0.item_discount_minor'],
            'negative order discount' => [[self::line(1, 100)], -1, 'order_discount_minor'],
            'negative order tax rate' => [[self::line(1, 100)], 0, 'order_tax_rate_basis_points', -1],
            'order tax rate exceeds 100 percent' => [[self::line(1, 100)], 0, 'order_tax_rate_basis_points', 10_001],
            'order discount exceeds eligible amount' => [[self::line(1, 100, itemDiscountMinor: 50)], 51, 'order_discount_minor'],
            'line subtotal exceeds API integer range' => [[self::line(1, OrderPricingService::MAX_MINOR_UNITS, quantity: 2)], 0, 'items'],
            'grand total exceeds API integer range' => [[self::line(1, OrderPricingService::MAX_MINOR_UNITS, taxBasisPoints: 1)], 0, 'items'],
        ];
    }

    /**
     * @return LineInput
     */
    private static function line(
        int $productId,
        int $unitPriceMinor,
        int $quantity = 1,
        int $taxBasisPoints = 0,
        int $itemDiscountMinor = 0,
    ): array {
        return [
            'product_id' => $productId,
            'product_name' => 'Sample product '.$productId,
            'product_sku' => 'SKU-'.$productId,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'tax_rate_basis_points' => $taxBasisPoints,
            'item_discount_minor' => $itemDiscountMinor,
        ];
    }
}
