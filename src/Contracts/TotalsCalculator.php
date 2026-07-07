<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\TaxBreakdown;

/**
 * Computes a document's VAT breakdown and totals. Implementations MUST round
 * VAT once per (category, rate) group, never per line — see
 * docs/research/06-money-tax-and-rounding.md.
 */
interface TotalsCalculator
{
    /**
     * @param  iterable<int, TaxableLine>  $lines
     */
    public function calculate(iterable $lines, string $currency = 'EUR'): TaxBreakdown;
}
