<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Dunning\StatutoryDunningInterestCalculator;
use JohnWink\GobdInvoice\Enums\DebtorType;
use JohnWink\GobdInvoice\Exceptions\DunningException;
use JohnWink\GobdInvoice\ValueObjects\BasiszinssatzPeriod;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\Money;

function dunningCalculator(): StatutoryDunningInterestCalculator
{
    return new StatutoryDunningInterestCalculator(
        [
            new BasiszinssatzPeriod('2024-01-01', '3.62'),
            new BasiszinssatzPeriod('2024-07-01', '3.37'),
            new BasiszinssatzPeriod('2025-01-01', '2.27'),
            new BasiszinssatzPeriod('2025-07-01', '1.27'),
            new BasiszinssatzPeriod('2026-01-01', '1.27'),
            new BasiszinssatzPeriod('2026-07-01', '1.52'),
        ],
        consumerSurchargePoints: '5.0',
        businessSurchargePoints: '9.0',
        latePaymentFeeMinor: 4000,
    );
}

it('charges no interest and no fee for a goodwill reminder (Kulanz)', function (): void {
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
        withInterest: false,
        dunningFeeMinor: 500,
    ));

    expect($assessment->interest->minorUnits)->toBe(0)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(0)   // fee follows withInterest by default
        ->and($assessment->dunningFee->minorUnits)->toBe(500)
        ->and($assessment->total()->minorUnits)->toBe(100500)
        ->and($assessment->interestPeriods)->toBe([]);
});

it('computes B2B interest at base + 9 points plus the €40 flat fee', function (): void {
    // 1.27 % base (H1 2026) + 9 = 10.27 %; 100000 ct × 10.27 % × 30/365 = 844 ct.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
    ));

    expect($assessment->interest->minorUnits)->toBe(844)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(4000)
        ->and($assessment->total()->minorUnits)->toBe(104844)
        ->and($assessment->interestPeriods)->toHaveCount(1)
        ->and($assessment->interestPeriods[0]->days)->toBe(30)
        ->and($assessment->interestPeriods[0]->annualRatePercent)->toBe('10.27');
});

it('computes consumer interest at base + 5 points and never the flat fee', function (): void {
    // 1.27 % + 5 = 6.27 %; 100000 ct × 6.27 % × 30/365 = 515 ct.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Consumer,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
    ));

    expect($assessment->interest->minorUnits)->toBe(515)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(0)
        ->and($assessment->total()->minorUnits)->toBe(100515);
});

it('splits the interest across a half-year Basiszinssatz change with the changeover day on the new rate', function (): void {
    // 1 000 000 ct, Verzug 2025-06-16, valuation 2025-07-16. Interest accrues
    // 2025-06-17..2025-07-16 (30 d); the base rate changes ON 2025-07-01, which
    // must carry the NEW rate (§247 "verändert sich zum 1. Juli"):
    //   14 d @ 11.27 % (06-17..06-30) = 4323 ct; 16 d @ 10.27 % (07-01..07-16) = 4502 ct.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(1000000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2025-06-16'),
        interestTo: new DateTimeImmutable('2025-07-16'),
        withLatePaymentFee: false,
    ));

    expect($assessment->interestPeriods)->toHaveCount(2)
        ->and($assessment->interestPeriods[0]->days)->toBe(14)
        ->and($assessment->interestPeriods[0]->to)->toBe('2025-06-30')
        ->and($assessment->interestPeriods[0]->annualRatePercent)->toBe('11.27')
        ->and($assessment->interestPeriods[0]->amount->minorUnits)->toBe(4323)
        ->and($assessment->interestPeriods[1]->days)->toBe(16)
        ->and($assessment->interestPeriods[1]->from)->toBe('2025-07-01')
        ->and($assessment->interestPeriods[1]->annualRatePercent)->toBe('10.27')
        ->and($assessment->interestPeriods[1]->amount->minorUnits)->toBe(4502)
        ->and($assessment->interest->minorUnits)->toBe(8825);
});

it('charges the single changeover day at the new rate (boundary regression)', function (): void {
    // Verzug 2025-06-30, valuation 2025-07-01 -> the only interest day is
    // 2025-07-01, which is on the NEW rate 1.27 + 9 = 10.27 % (not the old 11.27 %).
    // 1 000 000 ct × 10.27 % × 1/365 = 281 ct.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(1000000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2025-06-30'),
        interestTo: new DateTimeImmutable('2025-07-01'),
        withLatePaymentFee: false,
    ));

    expect($assessment->interestPeriods)->toHaveCount(1)
        ->and($assessment->interestPeriods[0]->days)->toBe(1)
        ->and($assessment->interestPeriods[0]->annualRatePercent)->toBe('10.27')
        ->and($assessment->interest->minorUnits)->toBe(281);
});

it('uses a 366-day denominator inside a leap year', function (): void {
    // 1 000 000 ct over 2024-02-01..2024-03-02 (leap): 30 d @ 12.62 % / 366 = 10344 ct.
    // A 365 denominator would give 10373 ct.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(1000000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2024-02-01'),
        interestTo: new DateTimeImmutable('2024-03-02'),
        withLatePaymentFee: false,
    ));

    expect($assessment->interestPeriods[0]->yearDays)->toBe(366)
        ->and($assessment->interestPeriods[0]->days)->toBe(30)
        ->and($assessment->interest->minorUnits)->toBe(10344);
});

it('yields zero interest when the valuation date is not after Verzugsbeginn', function (): void {
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-03-01'),
        interestTo: new DateTimeImmutable('2026-03-01'),
        withLatePaymentFee: false,
    ));

    expect($assessment->interest->minorUnits)->toBe(0)
        ->and($assessment->interestPeriods)->toBe([]);
});

it('fails loud when interest is requested without a period', function (): void {
    expect(fn () => dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
    )))->toThrow(DunningException::class);
});

it('fails loud when a date has no verified Basiszinssatz', function (): void {
    expect(fn () => dunningCalculator()->assess(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2020-01-01'),
        interestTo: new DateTimeImmutable('2020-02-01'),
    )))->toThrow(DunningException::class);
});

it('does not charge the €40 flat fee when nothing is owed (settled principal)', function (): void {
    // §288 Abs. 5 presupposes an overdue Entgeltforderung: a zero principal (a
    // fully-paid invoice) owes no interest AND no flat fee.
    $assessment = dunningCalculator()->assess(Money::zero(), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
    ));

    expect($assessment->interest->minorUnits)->toBe(0)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(0)
        ->and($assessment->total()->minorUnits)->toBe(0);
});

it('does not attach the fixed €40 EUR fee to a foreign-currency debt', function (): void {
    // The €40 Verzugspauschale is a EUR amount; it must not be mislabelled as
    // "40.00 USD". Interest still accrues in the debt currency.
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(100000, 'USD'), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
    ));

    expect($assessment->interest->minorUnits)->toBe(844)
        ->and($assessment->interest->currency)->toBe('USD')
        ->and($assessment->latePaymentFee->minorUnits)->toBe(0);
});

it('lets a business waive interest but still claim an explicit dunning fee', function (): void {
    $assessment = dunningCalculator()->assess(Money::fromMinorUnits(50000), new DunningOptions(
        debtorType: DebtorType::Business,
        withInterest: false,
        withLatePaymentFee: false,
        dunningFeeMinor: 250,
        level: 2,
    ));

    expect($assessment->interest->minorUnits)->toBe(0)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(0)
        ->and($assessment->dunningFee->minorUnits)->toBe(250)
        ->and($assessment->total()->minorUnits)->toBe(50250)
        ->and($assessment->level)->toBe(2);
});
