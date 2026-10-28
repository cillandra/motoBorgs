<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const int MAX_CENTAVOS = 999999999;

    public static function parse(string $amount): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', trim($amount), $parts)) {
            throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
        }
        $value = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($value > self::MAX_CENTAVOS) {
            throw new InvalidArgumentException('Amount exceeds the supported transaction limit.');
        }

        return $value;
    }

    public static function format(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').intdiv(abs($centavos), 100).'.'.str_pad((string) (abs($centavos) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function display(int $centavos): string
    {
        return preg_replace('/\B(?=(\d{3})+\.)/', ',', self::format($centavos));
    }

    /** @param array<int, int> $lineGross @return array<int, int> */
    public static function allocate(int $discount, array $lineGross): array
    {
        ksort($lineGross, SORT_NUMERIC);
        $total = 0;
        foreach ($lineGross as $gross) {
            if (! is_int($gross) || $gross < 0 || $gross > self::MAX_CENTAVOS - $total) {
                throw new InvalidArgumentException('Invalid line amount or transaction limit.');
            }
            $total += $gross;
        }
        if ($discount < 0 || $discount > $total) {
            throw new InvalidArgumentException('Discount exceeds the merchandise total.');
        }
        $allocations = array_fill_keys(array_keys($lineGross), 0);
        if ($total === 0) {
            return $allocations;
        }
        $remainders = [];
        foreach ($lineGross as $id => $gross) {
            $product = $discount * $gross;
            $allocations[$id] = intdiv($product, $total);
            $remainders[$id] = $product % $total;
        }
        $ids = array_keys($remainders);
        usort($ids, fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        $remaining = $discount - array_sum($allocations);
        for ($index = 0; $index < $remaining; $index++) {
            $allocations[$ids[$index]]++;
        }

        return $allocations;
    }

    public static function refundForUnits(int $sold, int $price, int $discount, int $alreadyReturned, int $returning): int
    {
        if ($sold < 1 || $price < 0 || $price * $sold > self::MAX_CENTAVOS || $discount < 0 || $discount > $price * $sold
            || $alreadyReturned < 0 || $returning < 1 || $alreadyReturned + $returning > $sold) {
            throw new InvalidArgumentException('Invalid original sale or return quantity.');
        }
        $baseDiscount = intdiv($discount, $sold);
        $extraDiscountUnits = $discount % $sold;
        $extraInRange = min($alreadyReturned + $returning, $extraDiscountUnits) - min($alreadyReturned, $extraDiscountUnits);

        return $returning * ($price - $baseDiscount) - $extraInRange;
    }
}
