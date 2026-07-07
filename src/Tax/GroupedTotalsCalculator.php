<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tax;

use JohnWink\GobdInvoice\Contracts\TotalsCalculator;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdown;
use JohnWink\GobdInvoice\ValueObjects\TaxBreakdownLine;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

/**
 * Default {@see TotalsCalculator}. It groups lines by (category, rate), sums the
 * net base per group, then rounds VAT exactly once per group before summing the
 * already-rounded group amounts into the document totals. This avoids the
 * rounding drift that per-line VAT rounding produces and satisfies EN 16931's
 * zero rounding tolerance. See docs/research/06-money-tax-and-rounding.md.
 */
final class GroupedTotalsCalculator implements TotalsCalculator
{
    public function calculate(iterable $lines, string $currency = 'EUR'): TaxBreakdown
    {
        /** @var array<string, array{rate: TaxRate, net: Money}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $rate = $line->taxRate();
            $key = $rate->groupKey();

            $groups[$key] ??= ['rate' => $rate, 'net' => Money::zero($currency)];
            $groups[$key]['net'] = $groups[$key]['net']->plus($line->netAmount());
        }

        $breakdownLines = [];
        $netTotal = Money::zero($currency);
        $vatTotal = Money::zero($currency);
        $grossTotal = Money::zero($currency);

        foreach ($groups as $group) {
            $net = $group['net'];
            $vat = $group['rate']->vatOf($net);
            $gross = $net->plus($vat);

            $breakdownLines[] = new TaxBreakdownLine($group['rate'], $net, $vat, $gross);
            $netTotal = $netTotal->plus($net);
            $vatTotal = $vatTotal->plus($vat);
            $grossTotal = $grossTotal->plus($gross);
        }

        return new TaxBreakdown($breakdownLines, $netTotal, $vatTotal, $grossTotal);
    }
}
