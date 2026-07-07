<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\ValueObjects\AllowanceCharge;
use JohnWink\GobdInvoice\ValueObjects\LineInput;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use JohnWink\GobdInvoice\ValueObjects\TotalsInput;

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
