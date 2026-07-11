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

it('converts to another currency at a rate, rounding once', function (): void {
    // 100.00 USD * 0.92 = 92.00 EUR
    expect(Money::fromMinorUnits(10000, 'USD')->convertedTo('EUR', '0.92')->minorUnits)->toBe(9200)
        ->and(Money::fromMinorUnits(10000, 'USD')->convertedTo('EUR', '0.92')->currency)->toBe('EUR')
        // 123.45 * 0.9 = 111.105 -> 111.11 (half away from zero)
        ->and(Money::fromMinorUnits(12345, 'USD')->convertedTo('EUR', '0.9')->minorUnits)->toBe(11111);
});

it('rejects a non-numeric or scientific-notation exchange rate with a clear exception', function (): void {
    expect(fn (): Money => Money::fromMinorUnits(10000, 'USD')->convertedTo('EUR', 'x'))
        ->toThrow(InvalidArgumentException::class)
        // is_numeric() would accept "1e3"; the strict guard must reject it here
        // rather than leaking a raw bcmath ValueError.
        ->and(fn (): Money => Money::fromMinorUnits(10000, 'USD')->convertedTo('EUR', '1e3'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-numeric multipliers, percentages and rates instead of silently yielding zero', function (): void {
    // assert() is stripped in production (zend.assertions=-1); these must hard-throw.
    expect(fn (): Money => Money::fromMinorUnits(10000)->multipliedBy(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->percentage(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->netFromGross('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Money => Money::fromMinorUnits(10000)->netFromGross('19.0', ''))->toThrow(InvalidArgumentException::class);
});

it('rejects a currency code that is not exactly three upper-case letters', function (string $currency): void {
    // Both halves of the guard matter: a wrong length AND a lower-case (but
    // 3-char) code such as "eur" must be rejected.
    expect(fn (): Money => Money::fromMinorUnits(100, $currency))->toThrow(InvalidArgumentException::class);
})->with(['eur', 'Eur', 'EU', 'EURO', '']);

it('accepts a valid three-letter upper-case currency', function (): void {
    expect(Money::fromMinorUnits(100, 'USD')->currency)->toBe('USD');
});

it('enforces the same currency when subtracting', function (): void {
    expect(fn (): Money => Money::fromMinorUnits(100, 'EUR')->minus(Money::fromMinorUnits(50, 'USD')))
        ->toThrow(InvalidArgumentException::class);
});

it('multiplies by an integer factor as well as a decimal string', function (): void {
    expect(Money::fromMinorUnits(1200)->multipliedBy(2)->minorUnits)->toBe(2400);
});

it('classifies sign strictly at the zero boundary', function (): void {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::zero()->isNegative())->toBeFalse()
        ->and(Money::zero()->isPositive())->toBeFalse()
        ->and(Money::fromMinorUnits(1)->isPositive())->toBeTrue()
        ->and(Money::fromMinorUnits(1)->isNegative())->toBeFalse()
        ->and(Money::fromMinorUnits(1)->isZero())->toBeFalse()
        ->and(Money::fromMinorUnits(-1)->isNegative())->toBeTrue()
        ->and(Money::fromMinorUnits(-1)->isPositive())->toBeFalse()
        ->and(Money::fromMinorUnits(-1)->isZero())->toBeFalse();
});

it('treats amounts of different currencies as unequal even at the same magnitude', function (): void {
    expect(Money::fromMinorUnits(100, 'EUR')->equals(Money::fromMinorUnits(100, 'USD')))->toBeFalse()
        ->and(Money::fromMinorUnits(100, 'EUR')->equals(Money::fromMinorUnits(100, 'EUR')))->toBeTrue();
});

it('refuses to compare amounts across currencies', function (): void {
    expect(fn (): int => Money::fromMinorUnits(100, 'EUR')->compareTo(Money::fromMinorUnits(100, 'USD')))
        ->toThrow(InvalidArgumentException::class)
        ->and(Money::fromMinorUnits(100)->compareTo(Money::fromMinorUnits(50)))->toBe(1);
});

it('formats zero without a sign and a single negative cent with one', function (): void {
    expect(Money::zero()->toDecimal())->toBe('0.00')
        ->and(Money::fromMinorUnits(-1)->toDecimal())->toBe('-0.01');
});
