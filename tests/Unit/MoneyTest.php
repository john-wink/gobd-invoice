<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\Money;

it('builds from a decimal string', function (): void {
    expect(Money::fromDecimal('1234.56')->minorUnits)->toBe(123456)
        ->and(Money::fromDecimal('0.07')->minorUnits)->toBe(7)
        ->and(Money::fromDecimal('-12')->minorUnits)->toBe(-1200);
});

it('rejects floats and malformed strings', function (string $value): void {
    expect(fn (): Money => Money::fromDecimal($value))->toThrow(InvalidArgumentException::class);
})->with(['1.999', 'abc', '1,50', '']);

it('adds and subtracts within the same currency', function (): void {
    $sum = Money::fromMinorUnits(1000)->plus(Money::fromMinorUnits(250));
    expect($sum->minorUnits)->toBe(1250)
        ->and(Money::fromMinorUnits(1000)->minus(Money::fromMinorUnits(250))->minorUnits)->toBe(750);
});

it('refuses to mix currencies', function (): void {
    expect(fn (): Money => Money::fromMinorUnits(100, 'EUR')->plus(Money::fromMinorUnits(100, 'USD')))
        ->toThrow(InvalidArgumentException::class);
});

it('applies commercial rounding (half away from zero) to percentages', function (): void {
    // 5% of 0.10 = 0.005 -> rounds away from zero to 0.01
    expect(Money::fromMinorUnits(10)->percentage('5.0')->minorUnits)->toBe(1)
        // 19% of 100.00 = 19.00
        ->and(Money::fromMinorUnits(10000)->percentage('19.0')->minorUnits)->toBe(1900)
        // 19% of 0.66 = 0.1254 -> 0.13
        ->and(Money::fromMinorUnits(66)->percentage('19.0')->minorUnits)->toBe(13);
});

it('multiplies by a decimal quantity', function (): void {
    // 12.00 EUR * 2.5 = 30.00
    expect(Money::fromMinorUnits(1200)->multipliedBy('2.5')->minorUnits)->toBe(3000);
});

it('formats as a fixed two-decimal string', function (): void {
    expect(Money::fromMinorUnits(123456)->toDecimal())->toBe('1234.56')
        ->and(Money::fromMinorUnits(-5)->toDecimal())->toBe('-0.05')
        ->and((string) Money::fromMinorUnits(100, 'EUR'))->toBe('1.00 EUR');
});

it('extracts the net base contained in a gross amount', function (): void {
    // 119.00 gross @ 19% -> 100.00 net; 107.00 @ 7% -> 100.00 net.
    expect(Money::fromMinorUnits(11900)->netFromGross('19.0')->minorUnits)->toBe(10000)
        ->and(Money::fromMinorUnits(10700)->netFromGross('7.0')->minorUnits)->toBe(10000)
        // 100.00 gross @ 19% -> 1000000/119 = 8403.36.. -> 8403 (round once).
        ->and(Money::fromMinorUnits(10000)->netFromGross('19.0')->minorUnits)->toBe(8403);
});

it('returns the amount unchanged when extracting net at a zero rate', function (): void {
    expect(Money::fromMinorUnits(9999)->netFromGross('0.0')->minorUnits)->toBe(9999);
});

it('extracts net from a gross unit price times a fractional quantity, rounding once', function (): void {
    // 1.00 gross * 0.335 kept exact -> 0.335 / 1.19 = 0.2815 -> 0.28.
    expect(Money::fromMinorUnits(100)->netFromGross('19.0', '0.335')->minorUnits)->toBe(28);
});

it('rejects non-numeric multipliers, percentages and rates instead of silently yielding zero', function (): void {
    // assert() is stripped in production (zend.assertions=-1); these must hard-throw.
    expect(fn (): Money => Money::fromMinorUnits(10000)->multipliedBy(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->percentage(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->netFromGross('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->netFromGross('19.0', ''))->toThrow(InvalidArgumentException::class);
});
