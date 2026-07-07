<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\DocumentTotals;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

/**
 * Computes a document's full EN 16931 monetary chain (BT-106 → BT-115) from its
 * lines, document-level allowances/charges and payment data. Implementations
 * build on a {@see TotalsCalculator} for the per-(category, rate) VAT breakdown
 * and MUST only ever add already-rounded amounts for the document totals (EN
 * 16931 zero rounding tolerance) — see docs/research/06-money-tax-and-rounding.md.
 */
interface DocumentTotalsCalculator
{
    public function calculate(TotalsInput $totalsInput): DocumentTotals;
}
