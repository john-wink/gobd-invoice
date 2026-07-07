<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\ExchangeRate;
use JohnWink\GobdInvoice\ValueObjects\Money;

it('converts a base-currency amount to the quote currency', function (): void {
    $rate = new ExchangeRate('USD', 'EUR', '0.92', 'BMF Durchschnittskurs 2026-06');

    $converted = $rate->convert(Money::fromMinorUnits(19000, 'USD'));

    expect($converted->minorUnits)->toBe(17480) // 190.00 * 0.92 = 174.80
        ->and($converted->currency)->toBe('EUR')
        ->and($rate->reference)->toBe('BMF Durchschnittskurs 2026-06');
});

it('refuses to convert an amount whose currency is not the base currency', function (): void {
    $rate = new ExchangeRate('USD', 'EUR', '0.92');

    expect(fn (): Money => $rate->convert(Money::fromMinorUnits(100, 'EUR')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects malformed currencies or rates', function (callable $factory): void {
    expect($factory)->toThrow(InvalidArgumentException::class);
})->with([
    'bad base currency' => [fn (): ExchangeRate => new ExchangeRate('US', 'EUR', '0.92')],
    'bad quote currency' => [fn (): ExchangeRate => new ExchangeRate('USD', 'eur', '0.92')],
    'negative rate' => [fn (): ExchangeRate => new ExchangeRate('USD', 'EUR', '-0.92')],
    'non-numeric rate' => [fn (): ExchangeRate => new ExchangeRate('USD', 'EUR', 'par')],
    'zero rate' => [fn (): ExchangeRate => new ExchangeRate('USD', 'EUR', '0')],
    'zero rate with decimals' => [fn (): ExchangeRate => new ExchangeRate('USD', 'EUR', '0.00')],
]);
