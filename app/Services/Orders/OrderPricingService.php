<?php

declare(strict_types=1);

namespace App\Services\Orders;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type LineInput array{
 *     product_id: int,
 *     product_name: string,
 *     product_sku: string,
 *     quantity: int,
 *     unit_price_minor: int,
 *     tax_rate_basis_points: int,
 *     item_discount_minor: int
 * }
 * @phpstan-type PricedLine array{
 *     product_id: int,
 *     product_name: string,
 *     product_sku: string,
 *     quantity: int,
 *     unit_price_minor: int,
 *     tax_rate_basis_points: int,
 *     item_discount_minor: int,
 *     position: int,
 *     subtotal_minor: int,
 *     allocated_order_discount_minor: int,
 *     tax_minor: int,
 *     line_total_minor: int
 * }
 * @phpstan-type PricedOrder array{
 *     subtotal_minor: int,
 *     order_discount_minor: int,
 *     discount_total_minor: int,
 *     tax_total_minor: int,
 *     grand_total_minor: int,
 *     items: list<PricedLine>
 * }
 * @phpstan-type PricingPreview array{
 *     currency: string,
 *     subtotal_minor: int,
 *     order_discount_minor: int,
 *     discount_total_minor: int,
 *     tax_total_minor: int,
 *     grand_total_minor: int,
 *     items: list<PricedLine>
 * }
 */
final class OrderPricingService
{
    // Keep all returned money values exactly representable by JSON number consumers.
    public const int MAX_MINOR_UNITS = 9_007_199_254_740_991;

    private const int BASIS_POINTS_PER_UNIT = 10_000;

    public const int MAX_TAX_RATE_BASIS_POINTS = self::BASIS_POINTS_PER_UNIT;

    /**
     * @param  list<LineInput>  $lines
     * @return PricedOrder
     */
    public function calculate(array $lines, int $orderDiscountMinor): array
    {
        if ($lines === []) {
            $this->invalid('items', 'items_required');
        }

        $subtotals = [];
        $eligibleAmounts = [];
        $subtotal = 0;
        $itemDiscountTotal = 0;
        $products = [];

        foreach ($lines as $index => $line) {
            $productId = $line['product_id'];

            if (isset($products[$productId])) {
                $this->invalid("items.{$index}.product_id", 'product_repeated');
            }

            $products[$productId] = true;
            $this->validateLineInput($line, $index);

            $lineSubtotal = $this->boundedAmount(
                BigInteger::of($line['unit_price_minor'])->multipliedBy($line['quantity']),
            );
            $itemDiscount = $line['item_discount_minor'];

            if ($itemDiscount < 0 || $itemDiscount > $lineSubtotal) {
                $this->invalid("items.{$index}.item_discount_minor", 'item_discount_exceeds_subtotal');
            }

            $subtotals[] = $lineSubtotal;
            $eligibleAmounts[] = $lineSubtotal - $itemDiscount;
            $subtotal = $this->boundedAmount($subtotal + $lineSubtotal);
            $itemDiscountTotal += $itemDiscount;
        }

        $eligibleTotal = $subtotal - $itemDiscountTotal;
        if ($orderDiscountMinor < 0 || $orderDiscountMinor > $eligibleTotal) {
            $this->invalid('order_discount_minor', 'order_discount_exceeds_eligible_amount');
        }

        $shares = $this->allocateOrderDiscount($lines, $eligibleAmounts, $eligibleTotal, $orderDiscountMinor);
        $taxTotal = 0;
        $grandTotal = 0;
        $pricedLines = [];

        foreach ($lines as $index => $line) {
            $taxable = $eligibleAmounts[$index] - $shares[$index];
            $tax = BigInteger::of($taxable)
                ->multipliedBy($line['tax_rate_basis_points'])
                ->dividedBy(self::BASIS_POINTS_PER_UNIT, RoundingMode::HalfUp)
                ->toInt();
            $lineTotal = $this->boundedAmount($taxable + $tax);
            $taxTotal += $tax;
            $grandTotal = $this->boundedAmount($grandTotal + $lineTotal);

            $pricedLines[] = [
                ...$line,
                'position' => $index + 1,
                'subtotal_minor' => $subtotals[$index],
                'allocated_order_discount_minor' => $shares[$index],
                'tax_minor' => $tax,
                'line_total_minor' => $lineTotal,
            ];
        }

        return [
            'subtotal_minor' => $subtotal,
            'order_discount_minor' => $orderDiscountMinor,
            'discount_total_minor' => $itemDiscountTotal + $orderDiscountMinor,
            'tax_total_minor' => $taxTotal,
            'grand_total_minor' => $grandTotal,
            'items' => $pricedLines,
        ];
    }

    /**
     * @param  LineInput  $line
     */
    private function validateLineInput(array $line, int $index): void
    {
        if (
            $line['product_id'] < 1
            || $line['quantity'] < 1
            || $line['unit_price_minor'] < 0
            || $line['tax_rate_basis_points'] < 0
            || $line['tax_rate_basis_points'] > self::MAX_TAX_RATE_BASIS_POINTS
        ) {
            $this->invalid("items.{$index}", 'invalid_pricing_input');
        }
    }

    /**
     * @param  list<LineInput>  $lines
     * @param  list<int>  $eligibleAmounts
     * @return list<int>
     */
    private function allocateOrderDiscount(array $lines, array $eligibleAmounts, int $eligibleTotal, int $discount): array
    {
        if ($discount === 0) {
            return array_fill(0, count($lines), 0);
        }

        $shares = [];
        $rankedRemainders = [];
        $remaining = $discount;
        $discountAmount = BigInteger::of($discount);

        foreach ($lines as $index => $line) {
            [$share, $remainder] = $discountAmount
                ->multipliedBy($eligibleAmounts[$index])
                ->quotientAndRemainder($eligibleTotal);
            $shareMinor = $share->toInt();
            $shares[] = $shareMinor;
            $remaining -= $shareMinor;
            $rankedRemainders[] = [
                'index' => $index,
                'product_id' => $line['product_id'],
                'remainder' => $remainder->toInt(),
            ];
        }

        usort(
            $rankedRemainders,
            static fn (array $left, array $right): int => ($right['remainder'] <=> $left['remainder'])
                ?: ($left['product_id'] <=> $right['product_id']),
        );

        // The sum of fractional shares determines how many single minor units remain.
        for ($rank = 0; $rank < $remaining; $rank++) {
            $shares[$rankedRemainders[$rank]['index']]++;
        }

        return array_values($shares);
    }

    private function boundedAmount(BigInteger|int $amount): int
    {
        $exactAmount = BigInteger::of($amount);
        if ($exactAmount->isGreaterThan(self::MAX_MINOR_UNITS)) {
            $this->invalid('items', 'amount_out_of_range');
        }

        return $exactAmount->toInt();
    }

    private function invalid(string $field, string $messageKey): never
    {
        throw ValidationException::withMessages([
            $field => [Lang::string('api.orders.'.$messageKey)],
        ]);
    }
}
