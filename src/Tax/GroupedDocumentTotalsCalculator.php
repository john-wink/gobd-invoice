<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tax;

use JohnWink\GobdInvoice\Contracts\DocumentTotalsCalculator;
use JohnWink\GobdInvoice\Contracts\TotalsCalculator;
use JohnWink\GobdInvoice\ValueObjects\DocumentTotals;
use JohnWink\GobdInvoice\ValueObjects\ExchangeRate;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

/**
 * The default {@see DocumentTotalsCalculator}. It assembles the full EN 16931
 * chain by delegating the per-(category, rate) VAT rounding to a
 * {@see TotalsCalculator} (the vertikale Methode: round once per group), then
 * only ever *adding* the already-rounded results.
 *
 * Document-level allowances/charges are grouped alongside the lines: each
 * carries its own (category, rate) and contributes a signed net, so it is folded
 * into the matching VAT group's taxable base (BT-116) BEFORE the group VAT is
 * computed (REQ-14). The breakdown's net/VAT/gross totals are therefore already
 * the post-adjustment document totals (BT-109/BT-110/BT-112).
 *
 * See docs/research/06-money-tax-and-rounding.md (Section 5).
 */
final readonly class GroupedDocumentTotalsCalculator implements DocumentTotalsCalculator
{
    public function __construct(
        private TotalsCalculator $totalsCalculator = new GroupedTotalsCalculator,
    ) {}

    public function calculate(TotalsInput $totalsInput): DocumentTotals
    {
        $currency = $totalsInput->currency;

        // BT-106: sum of the (already 2-dp) line net amounts — lines only.
        $lineNetTotal = Money::zero($currency);
        foreach ($totalsInput->lines as $line) {
            $lineNetTotal = $lineNetTotal->plus($line->netAmount());
        }

        // BT-107 / BT-108: document-level allowance and charge magnitudes.
        $allowanceTotal = Money::zero($currency);
        $chargeTotal = Money::zero($currency);
        foreach ($totalsInput->documentAllowancesCharges as $allowanceCharge) {
            if ($allowanceCharge->isCharge) {
                $chargeTotal = $chargeTotal->plus($allowanceCharge->amount);
            } else {
                $allowanceTotal = $allowanceTotal->plus($allowanceCharge->amount);
            }
        }

        // Group lines AND document-level adjustments (each a signed TaxableLine)
        // so the adjustments land in the correct rate group before VAT is rounded.
        // The resulting breakdown totals are BT-109 / BT-110 / BT-112.
        $taxBreakdown = $this->totalsCalculator->calculate(
            [...array_values($totalsInput->lines), ...array_values($totalsInput->documentAllowancesCharges)],
            $currency,
        );

        $paidAmount = $totalsInput->paidAmount ?? Money::zero($currency);

        // BT-111: when the invoice currency is not the accounting currency (EUR),
        // also express the total VAT in the accounting currency, converting the
        // already-rounded BT-110 once at the supplied rate (§16 Abs. 6 UStG).
        $accountingRate = $totalsInput->accountingRate;
        $vatAccountingTotal = $accountingRate instanceof ExchangeRate
            ? $accountingRate->convert($taxBreakdown->vatTotal)
            : null;

        return new DocumentTotals(
            lineNetTotal: $lineNetTotal,
            allowanceTotal: $allowanceTotal,
            chargeTotal: $chargeTotal,
            netTotal: $taxBreakdown->netTotal,
            taxBreakdown: $taxBreakdown,
            vatTotal: $taxBreakdown->vatTotal,
            grossTotal: $taxBreakdown->grossTotal,
            paidAmount: $paidAmount,
            // BT-114 (payable rounding) is 0.00 under the per-group algorithm; a
            // cash-rounding strategy can populate it later without touching the
            // reconciled breakdown.
            roundingAmount: Money::zero($currency),
            // BT-115 = BT-112 − BT-113 (+ BT-114 = 0): a sum of already-rounded
            // amounts, never re-rounded (REQ-10).
            amountDue: $taxBreakdown->grossTotal->minus($paidAmount),
            paymentTerms: $totalsInput->paymentTerms,
            vatAccountingTotal: $vatAccountingTotal,
            accountingRate: $accountingRate,
        );
    }
}
