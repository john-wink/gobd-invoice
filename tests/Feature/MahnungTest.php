<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DebtorType;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\Money;

function overdueInvoice(): Document
{
    // Net 100.00 @ 19 % -> gross / amount due 119.00 (11900 ct).
    return GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-01-05']));
}

it('assesses statutory interest and fee on a bare principal via the facade', function (): void {
    // 100000 ct, business, 2026-02-01..2026-03-03: 1.27 % + 9 = 10.27 %, 30/365.
    $assessment = GobdInvoice::assessDunning(Money::fromMinorUnits(100000), new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
    ));

    expect($assessment->interest->minorUnits)->toBe(844)
        ->and($assessment->latePaymentFee->minorUnits)->toBe(4000)
        ->and($assessment->total()->minorUnits)->toBe(104844);
});

it('creates a Mahnung linked to the invoice, with the §288 assessment in metadata', function (): void {
    $invoice = overdueInvoice();
    expect($invoice->amount_due)->toBe(11900);

    $mahnung = GobdInvoice::dun($invoice, new DunningOptions(
        debtorType: DebtorType::Business,
        interestFrom: new DateTimeImmutable('2026-02-01'),
        interestTo: new DateTimeImmutable('2026-03-03'),
        level: 1,
    ));

    // 11900 ct × 10.27 % × 30/365 = 100 ct interest; + €40 fee -> total 16000 ct.
    $dunning = $mahnung->meta['dunning'];

    expect($mahnung->type)->toBe(DocumentType::Mahnung)
        ->and($mahnung->source_document_id)->toBe($invoice->id)
        ->and($mahnung->meta['dunned_document'])->toBe($invoice->number)
        ->and($dunning['principal_minor'])->toBe(11900)
        ->and($dunning['interest_minor'])->toBe(100)
        ->and($dunning['late_payment_fee_minor'])->toBe(4000)
        ->and($dunning['total_minor'])->toBe(16000);
});

it('creates a goodwill Mahnung with no interest and no fee (Kulanz)', function (): void {
    $invoice = overdueInvoice();

    $mahnung = GobdInvoice::dun($invoice, new DunningOptions(
        debtorType: DebtorType::Business,
        withInterest: false,
        level: 1,
    ));

    $dunning = $mahnung->meta['dunning'];

    expect($dunning['interest_minor'])->toBe(0)
        ->and($dunning['late_payment_fee_minor'])->toBe(0)
        ->and($dunning['total_minor'])->toBe(11900) // principal only
        ->and($dunning['interest_periods'])->toBe([]);
});

it('refuses to dun a document that is not finalized', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-01-05']);

    expect(fn (): Document => GobdInvoice::dun($draft, new DunningOptions(debtorType: DebtorType::Business, withInterest: false)))
        ->toThrow(JohnWink\GobdInvoice\Exceptions\DunningException::class);
});

it('leaves the Mahnung a mutable, non-tax business document', function (): void {
    $invoice = overdueInvoice();

    $mahnung = GobdInvoice::dun($invoice, new DunningOptions(debtorType: DebtorType::Consumer, withInterest: false));

    expect($mahnung->type->isTaxRelevant())->toBeFalse()
        ->and($mahnung->status)->toBe(DocumentStatus::Draft);

    // A non-tax draft is not festgeschrieben, so it can still be edited.
    $mahnung->meta = array_merge($mahnung->meta ?? [], ['note' => 'letzte Erinnerung']);
    $mahnung->save();

    expect($mahnung->fresh()->meta['note'])->toBe('letzte Erinnerung');
});
