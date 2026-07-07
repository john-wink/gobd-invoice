<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\ExchangeRate;
use JohnWink\GobdInvoice\ValueObjects\LineInput;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

function usdLine(): LineInput
{
    return new LineInput(Money::fromMinorUnits(10000, 'USD'), '1', TaxRate::standard('19.0'));
}

it('accepts real lines and document-level allowances/charges in their own slots', function (): void {
    $input = new TotalsInput(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        [AllowanceCharge::allowance(Money::fromMinorUnits(1000), TaxRate::standard('19.0'))],
    );

    expect($input->lines)->toHaveCount(1)
        ->and($input->documentAllowancesCharges)->toHaveCount(1);
});

it('rejects a document allowance/charge passed as a line (BT-106 vs BT-107 mix-up)', function (): void {
    expect(fn (): TotalsInput => new TotalsInput([
        new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0')),
        AllowanceCharge::allowance(Money::fromMinorUnits(1000), TaxRate::standard('19.0')),
    ]))->toThrow(InvalidArgumentException::class);
});

it('accepts a non-EUR invoice paired with a matching accounting rate', function (): void {
    $input = new TotalsInput([usdLine()], currency: 'USD', accountingRate: new ExchangeRate('USD', 'EUR', '0.92'));

    expect($input->currency)->toBe('USD')
        ->and($input->accountingRate?->quoteCurrency)->toBe('EUR');
});

it('couples the invoice currency to the accounting rate (BT-111)', function (callable $factory): void {
    expect($factory)->toThrow(InvalidArgumentException::class);
})->with([
    // Non-EUR invoice without a rate would silently omit the mandatory BT-111.
    'non-EUR without rate' => [fn (): TotalsInput => new TotalsInput([usdLine()], currency: 'USD')],
    // EUR invoice with a stray rate would emit a spurious BT-111.
    'EUR with a stray rate' => [fn (): TotalsInput => new TotalsInput(
        [new LineInput(Money::fromMinorUnits(10000), '1', TaxRate::standard('19.0'))],
        accountingRate: new ExchangeRate('EUR', 'EUR', '1'),
    )],
    // Rate base must match the invoice currency.
    'rate base mismatch' => [fn (): TotalsInput => new TotalsInput([usdLine()], currency: 'USD', accountingRate: new ExchangeRate('GBP', 'EUR', '1.1'))],
    // BT-111 must be in the EUR accounting currency.
    'rate quote not EUR' => [fn (): TotalsInput => new TotalsInput([usdLine()], currency: 'USD', accountingRate: new ExchangeRate('USD', 'GBP', '0.8'))],
]);
