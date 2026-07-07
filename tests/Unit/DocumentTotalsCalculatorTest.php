<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\PriceMode;
use JohnWink\GobdInvoice\Tax\GroupedDocumentTotalsCalculator;
use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\DocumentTotals;
use JohnWink\GobdInvoice\ValueObjects\LineInput;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\PaymentTerms;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

/**
 * @param  array<int, JohnWink\GobdInvoice\Contracts\TaxableLine>  $lines
 * @param  array<int, AllowanceCharge>  $adjustments
 */
function totalsOf(array $lines, array $adjustments = [], ?Money $paid = null, ?PaymentTerms $terms = null): DocumentTotals
{
    return (new GroupedDocumentTotalsCalculator)->calculate(
        new TotalsInput($lines, $adjustments, $paid, $terms),
    );
}

it('builds the full EN 16931 chain for a mixed-rate net invoice', function (): void {
    $totals = totalsOf([
        new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0')),
        new LineInput(Money::fromMinorUnits(5000), '1', TaxRate::reduced('7.0')),
    ]);

    expect($totals->lineNetTotal->minorUnits)->toBe(15000)   // BT-106
        ->and($totals->allowanceTotal->minorUnits)->toBe(0)  // BT-107
        ->and($totals->chargeTotal->minorUnits)->toBe(0)     // BT-108
        ->and($totals->netTotal->minorUnits)->toBe(15000)    // BT-109
        ->and($totals->vatTotal->minorUnits)->toBe(2250)     // BT-110 (1900 + 350)
        ->and($totals->grossTotal->minorUnits)->toBe(17250)  // BT-112
        ->and($totals->paidAmount->minorUnits)->toBe(0)      // BT-113
        ->and($totals->roundingAmount->minorUnits)->toBe(0)  // BT-114
        ->and($totals->amountDue->minorUnits)->toBe(17250)   // BT-115
        ->and($totals->taxBreakdown->lines)->toHaveCount(2); // BR-S-8: one row per rate
});

it('folds a document allowance into its own rate group before VAT (REQ-14)', function (): void {
    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        [AllowanceCharge::allowance(Money::fromMinorUnits(2000), TaxRate::standard('19.0'), 'Rabatt')],
    );

    // Base 10000 - 2000 = 8000; VAT once on 8000 = 1520.
    expect($totals->lineNetTotal->minorUnits)->toBe(10000)
        ->and($totals->allowanceTotal->minorUnits)->toBe(2000)
        ->and($totals->netTotal->minorUnits)->toBe(8000)
        ->and($totals->vatTotal->minorUnits)->toBe(1520)
        ->and($totals->grossTotal->minorUnits)->toBe(9520)
        ->and($totals->taxBreakdown->lines)->toHaveCount(1);
});

it('routes a document charge to a different rate group than the lines', function (): void {
    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        [AllowanceCharge::charge(Money::fromMinorUnits(1000), TaxRate::reduced('7.0'), 'Versand')],
    );

    // 19% group: base 10000, VAT 1900. 7% group: base 1000, VAT 70.
    expect($totals->chargeTotal->minorUnits)->toBe(1000)
        ->and($totals->netTotal->minorUnits)->toBe(11000)   // 10000 + 1000
        ->and($totals->vatTotal->minorUnits)->toBe(1970)    // 1900 + 70
        ->and($totals->grossTotal->minorUnits)->toBe(12970)
        ->and($totals->taxBreakdown->lines)->toHaveCount(2);
});

it('resolves a percentage document allowance', function (): void {
    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        [AllowanceCharge::percentageAllowance('10.0', Money::fromMinorUnits(10000), TaxRate::standard('19.0'))],
    );

    expect($totals->allowanceTotal->minorUnits)->toBe(1000)
        ->and($totals->netTotal->minorUnits)->toBe(9000)
        ->and($totals->vatTotal->minorUnits)->toBe(1710)
        ->and($totals->grossTotal->minorUnits)->toBe(10710);
});

it('keeps the BT-109 = BT-106 - BT-107 + BT-108 identity with both an allowance and a charge', function (): void {
    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        [
            AllowanceCharge::allowance(Money::fromMinorUnits(2000), TaxRate::standard('19.0')),
            AllowanceCharge::charge(Money::fromMinorUnits(500), TaxRate::standard('19.0')),
        ],
    );

    $expectedNet = $totals->lineNetTotal->minus($totals->allowanceTotal)->plus($totals->chargeTotal);

    expect($totals->netTotal->minorUnits)->toBe(8500)
        ->and($totals->netTotal->equals($expectedNet))->toBeTrue()
        ->and($totals->vatTotal->minorUnits)->toBe(1615)   // 8500 * 19%
        ->and($totals->grossTotal->minorUnits)->toBe(10115);
});

it('rounds VAT once per group for gross-authored lines', function (): void {
    // 119.00 gross @ 19% -> net 100.00, VAT 19.00.
    $totals = totalsOf([
        new LineInput(Money::fromMinorUnits(11900), '1', TaxRate::standard('19.0'), PriceMode::Gross),
    ]);

    expect($totals->netTotal->minorUnits)->toBe(10000)
        ->and($totals->vatTotal->minorUnits)->toBe(1900)
        ->and($totals->grossTotal->minorUnits)->toBe(11900);
});

it('carries Skonto payment terms without changing the totals (REQ-15)', function (): void {
    $terms = new PaymentTerms(netDays: 30, skontoPercentage: '2.0', skontoDays: 10);

    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        terms: $terms,
    );

    expect($totals->grossTotal->minorUnits)->toBe(11900)
        ->and($totals->amountDue->minorUnits)->toBe(11900)
        ->and($totals->paymentTerms)->toBe($terms)
        ->and($totals->paymentTerms?->hasSkonto())->toBeTrue();
});

it('subtracts an already-paid amount from the amount due but not the gross total', function (): void {
    $totals = totalsOf(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        paid: Money::fromMinorUnits(5000),
    );

    expect($totals->grossTotal->minorUnits)->toBe(11900)   // BT-112 unchanged
        ->and($totals->paidAmount->minorUnits)->toBe(5000) // BT-113
        ->and($totals->amountDue->minorUnits)->toBe(6900);  // BT-115
});

it('charges no VAT for a reverse-charge (AE) supply', function (): void {
    $totals = totalsOf([
        new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::reverseCharge()),
    ]);

    expect($totals->vatTotal->minorUnits)->toBe(0)
        ->and($totals->grossTotal->minorUnits)->toBe(10000)
        ->and($totals->taxBreakdown->lines[0]->rate->categoryCode())->toBe('AE');
});

it('charges no VAT for an exempt (Kleinunternehmer) supply', function (): void {
    $totals = totalsOf([
        new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::exempt()),
    ]);

    expect($totals->vatTotal->minorUnits)->toBe(0)
        ->and($totals->grossTotal->minorUnits)->toBe(10000)
        ->and($totals->taxBreakdown->lines[0]->rate->categoryCode())->toBe('E');
});

it('merges equivalently-written rates into one breakdown row and rounds once (BR-S-8)', function (): void {
    // 3ct @ "19.0" + 3ct @ "19.00": one 19% group of 6ct -> 6 * 19% = 1.14 -> 1ct.
    // A raw-string split would give two rows of 1ct each (vatTotal 2) — a drift EN
    // 16931's zero tolerance rejects.
    $totals = totalsOf([
        new LineInput(Money::fromMinorUnits(3), '1', TaxRate::standard('19.0')),
        new LineInput(Money::fromMinorUnits(3), '1', TaxRate::standard('19.00')),
    ]);

    expect($totals->taxBreakdown->lines)->toHaveCount(1)
        ->and($totals->netTotal->minorUnits)->toBe(6)
        ->and($totals->vatTotal->minorUnits)->toBe(1);
});

it('produces an all-zero document for no lines', function (): void {
    $totals = totalsOf([]);

    expect($totals->lineNetTotal->minorUnits)->toBe(0)
        ->and($totals->netTotal->minorUnits)->toBe(0)
        ->and($totals->vatTotal->minorUnits)->toBe(0)
        ->and($totals->grossTotal->minorUnits)->toBe(0)
        ->and($totals->amountDue->minorUnits)->toBe(0)
        ->and($totals->taxBreakdown->lines)->toHaveCount(0);
});
