<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\PriceMode;
use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\LineInput;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

it('computes the net line amount (BT-131) from unit price and quantity', function (): void {
    $line = new LineInput(Money::fromMinorUnits(1000), '3', TaxRate::standard());

    expect($line->netAmount()->minorUnits)->toBe(3000)
        ->and($line->taxRate()->percent())->toBe('19.0');
});

it('supports a fractional quantity', function (): void {
    // 12.00 * 2.5 = 30.00
    $line = new LineInput(Money::fromMinorUnits(1200), '2.5', TaxRate::standard());

    expect($line->netAmount()->minorUnits)->toBe(3000);
});

it('extracts the net base for a gross-authored line', function (): void {
    // 11.90 gross/unit * 10 = 119.00 gross -> 100.00 net
    $line = new LineInput(Money::fromMinorUnits(1190), '10', TaxRate::standard('19.0'), PriceMode::Gross);

    expect($line->netAmount()->minorUnits)->toBe(10000);
});

it('rounds a gross-authored line net once, not twice, for a fractional quantity', function (): void {
    // 1.00 gross/unit * 0.335 kept exact -> 0.2815 net -> 0.28 (double-rounding gave 0.29).
    $line = new LineInput(Money::fromMinorUnits(100), '0.335', TaxRate::standard('19.0'), PriceMode::Gross);

    expect($line->netAmount()->minorUnits)->toBe(28);
});

it('applies line-level allowances and charges to the net (net after price mode)', function (): void {
    $line = new LineInput(
        Money::fromMinorUnits(10000),
        '1',
        TaxRate::standard(),
        PriceMode::Net,
        [
            AllowanceCharge::allowance(Money::fromMinorUnits(1000), TaxRate::standard()),
            AllowanceCharge::charge(Money::fromMinorUnits(500), TaxRate::standard()),
        ],
    );

    // 10000 - 1000 + 500 = 9500
    expect($line->netAmount()->minorUnits)->toBe(9500);
});
