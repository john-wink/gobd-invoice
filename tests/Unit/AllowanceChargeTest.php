<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

it('models a fixed allowance as a negative signed net', function (): void {
    $allowance = AllowanceCharge::allowance(Money::fromMinorUnits(500), TaxRate::standard(), 'Treuerabatt');

    expect($allowance->isCharge)->toBeFalse()
        ->and($allowance->amount->minorUnits)->toBe(500)
        ->and($allowance->netAmount()->minorUnits)->toBe(-500)
        ->and($allowance->reason)->toBe('Treuerabatt');
});

it('models a fixed charge as a positive signed net', function (): void {
    $charge = AllowanceCharge::charge(Money::fromMinorUnits(500), TaxRate::standard());

    expect($charge->isCharge)->toBeTrue()
        ->and($charge->netAmount()->minorUnits)->toBe(500);
});

it('resolves a percentage allowance and keeps the percentage and base', function (): void {
    $allowance = AllowanceCharge::percentageAllowance('10.0', Money::fromMinorUnits(10000), TaxRate::standard());

    expect($allowance->amount->minorUnits)->toBe(1000)
        ->and($allowance->netAmount()->minorUnits)->toBe(-1000)
        ->and($allowance->percentage)->toBe('10.0')
        ->and($allowance->baseAmount?->minorUnits)->toBe(10000);
});

it('rejects a negative magnitude (direction is carried by isCharge)', function (): void {
    expect(fn (): AllowanceCharge => AllowanceCharge::charge(Money::fromMinorUnits(-1), TaxRate::standard()))
        ->toThrow(InvalidArgumentException::class);
});

it('carries its own tax rate for per-group folding', function (): void {
    $charge = AllowanceCharge::charge(Money::fromMinorUnits(100), TaxRate::reduced('7.0'));

    expect($charge->taxRate()->percent())->toBe('7.0')
        ->and($charge->taxRate()->categoryCode())->toBe('S');
});
