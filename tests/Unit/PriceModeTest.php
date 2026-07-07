<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\PriceMode;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

it('leaves a net-authored price as the net line total', function (): void {
    $net = PriceMode::Net->lineNet(Money::fromMinorUnits(10000), '1', TaxRate::standard());

    expect($net->minorUnits)->toBe(10000);
});

it('extracts the net base from a gross-authored price', function (): void {
    $net = PriceMode::Gross->lineNet(Money::fromMinorUnits(11900), '1', TaxRate::standard('19.0'));

    expect($net->minorUnits)->toBe(10000);
});

it('treats gross as net for an untaxed category', function (): void {
    $net = PriceMode::Gross->lineNet(Money::fromMinorUnits(10000), '1', TaxRate::exempt());

    expect($net->minorUnits)->toBe(10000);
});

it('rounds the gross line net exactly once for a fractional quantity', function (): void {
    // 1.00 gross/unit * 0.335 = 0.335 gross (kept exact) / 1.19 = 0.2815 -> 0.28.
    // Rounding gross to 0.34 first (the old double-round bug) would give 0.29.
    $net = PriceMode::Gross->lineNet(Money::fromMinorUnits(100), '0.335', TaxRate::standard('19.0'));

    expect($net->minorUnits)->toBe(28);
});
