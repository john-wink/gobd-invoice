<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

it('gives numerically equivalent rates the same group key (BR-S-8)', function (): void {
    $key = fn (string $rate): string => (new TaxRate($rate, TaxCategory::Standard))->groupKey();

    expect($key('19'))->toBe($key('19.0'))
        ->and($key('19.0'))->toBe($key('19.00'))
        ->and($key('7.5'))->toBe($key('7.50'));
});

it('keeps genuinely different rates in different groups', function (): void {
    $standard = new TaxRate('19.0', TaxCategory::Standard);
    $reduced = new TaxRate('7.0', TaxCategory::Standard);

    expect($standard->groupKey())->not->toBe($reduced->groupKey());
});

it('rejects a non-numeric rate at construction', function (): void {
    expect(fn (): TaxRate => new TaxRate('nineteen', TaxCategory::Standard))
        ->toThrow(InvalidArgumentException::class);
});

it('computes VAT identically regardless of how the equivalent rate is written', function (): void {
    $base = Money::fromMinorUnits(10000);

    expect((new TaxRate('19', TaxCategory::Standard))->vatOf($base)->minorUnits)
        ->toBe((new TaxRate('19.00', TaxCategory::Standard))->vatOf($base)->minorUnits);
});
