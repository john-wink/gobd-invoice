<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\TaxableLine;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Tax\GroupedTotalsCalculator;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

function taxableLine(int $netMinor, string $rate, TaxCategory $category = TaxCategory::Standard): TaxableLine
{
    return new class($netMinor, $rate, $category) implements TaxableLine
    {
        public function __construct(
            private readonly int $netMinor,
            private readonly string $rate,
            private readonly TaxCategory $category,
        ) {}

        public function netAmount(): Money
        {
            return Money::fromMinorUnits($this->netMinor);
        }

        public function taxRate(): TaxRate
        {
            return new TaxRate($this->rate, $this->category);
        }
    };
}

it('groups VAT per rate and reconciles the totals', function (): void {
    $breakdown = (new GroupedTotalsCalculator)->calculate([
        taxableLine(10000, '19.0'),
        taxableLine(5000, '7.0'),
    ]);

    expect($breakdown->lines)->toHaveCount(2)
        ->and($breakdown->netTotal->minorUnits)->toBe(15000)
        ->and($breakdown->vatTotal->minorUnits)->toBe(2250) // 1900 + 350
        ->and($breakdown->grossTotal->minorUnits)->toBe(17250);
});

it('rounds VAT once per group, not per line (EN 16931 zero tolerance)', function (): void {
    // Two lines of 0.33 EUR at 19%. Per-line rounding would give 0.06 + 0.06 = 0.12.
    // Per-group: 0.66 * 19% = 0.1254 -> 0.13.
    $breakdown = (new GroupedTotalsCalculator)->calculate([
        taxableLine(33, '19.0'),
        taxableLine(33, '19.0'),
    ]);

    expect($breakdown->lines)->toHaveCount(1)
        ->and($breakdown->vatTotal->minorUnits)->toBe(13);
});

it('charges no VAT for exempt (Kleinunternehmer) lines', function (): void {
    $breakdown = (new GroupedTotalsCalculator)->calculate([
        taxableLine(10000, '0.0', TaxCategory::Exempt),
    ]);

    expect($breakdown->vatTotal->minorUnits)->toBe(0)
        ->and($breakdown->grossTotal->minorUnits)->toBe(10000)
        ->and($breakdown->lines[0]->rate->categoryCode())->toBe('E');
});
