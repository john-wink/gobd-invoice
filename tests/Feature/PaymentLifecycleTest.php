<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentIsImmutableException;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function finalizedFor(string $unitPrice = '100.00', array $attributes = []): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, $attributes, [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => $unitPrice],
    ]));
}

it('records a partial then a full payment and advances the status', function (): void {
    $invoice = finalizedFor();
    $gross = (int) $invoice->gross_total;
    $half = intdiv($gross, 2);

    GobdInvoice::recordPayment($invoice, $half);
    $invoice->refresh();
    expect($invoice->status)->toBe(DocumentStatus::PartiallyPaid)
        ->and((int) $invoice->paid_total)->toBe($half)
        ->and((int) $invoice->amount_due)->toBe($gross - $half);

    GobdInvoice::recordPayment($invoice, $gross - $half);
    $invoice->refresh();
    expect($invoice->status)->toBe(DocumentStatus::Paid)
        ->and((int) $invoice->amount_due)->toBe(0);
});

it('marks a finalized document as sent', function (): void {
    $invoice = finalizedFor();

    GobdInvoice::markSent($invoice);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::Sent);
});

it('still blocks changes to §14 tax fields after finalization', function (): void {
    $invoice = finalizedFor();
    $invoice->gross_total = 999999;

    expect(fn () => $invoice->save())->toThrow(DocumentIsImmutableException::class);
});

it('exposes a due date computed from the payment terms', function (): void {
    $invoice = finalizedFor('10.00', ['issue_date' => '2026-01-10', 'payment_terms' => ['net_days' => 14]]);

    expect($invoice->due_date->toDateString())->toBe('2026-01-24');
});

it('rejects a payment on a non-finalized draft', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00'],
    ]);

    expect(fn () => GobdInvoice::recordPayment($draft, 500))->toThrow(GobdInvoiceException::class);
});
