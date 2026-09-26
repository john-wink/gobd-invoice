<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

beforeEach(function (): void {
    Date::use(CarbonImmutable::class);
});

afterEach(function (): void {
    Date::useDefault();
});

function immutableHostInvoice(array $attributes = []): Document
{
    return GobdInvoice::draft(DocumentType::Rechnung, [
        'service_date' => '2026-09-01',
        ...$attributes,
    ], [
        ['description' => 'Dachrinne erneuern', 'quantity' => '1', 'unit_price' => '100.00'],
    ]);
}

it('drafts with dates when the host hands out immutable dates', function (): void {
    $draft = immutableHostInvoice([
        'issue_date' => CarbonImmutable::parse('2026-09-10'),
        'service_period_start' => '2026-08-01',
        'service_period_end' => '2026-08-31',
    ]);

    expect($draft->service_date?->toDateString())->toBe('2026-09-01')
        ->and($draft->issue_date?->toDateString())->toBe('2026-09-10')
        ->and($draft->service_period_start?->toDateString())->toBe('2026-08-01')
        ->and($draft->service_period_end?->toDateString())->toBe('2026-08-31');
});

it('finalizes and sets the retention window when the host hands out immutable dates', function (): void {
    Date::setTestNow('2026-09-26 08:15:00');

    $invoice = GobdInvoice::finalize(immutableHostInvoice());

    expect($invoice->documentStatus())->toBe(DocumentStatus::Finalized)
        ->and($invoice->issue_date?->toDateString())->toBe('2026-09-26')
        ->and($invoice->retention_until?->toDateString())->toBe('2034-12-31')
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
});

it('cancels through a Storno when the host hands out immutable dates', function (): void {
    $invoice = GobdInvoice::finalize(immutableHostInvoice());

    $storno = GobdInvoice::cancel($invoice, 'Doppelt berechnet');

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($storno->service_date?->toDateString())->toBe('2026-09-01')
        ->and($invoice->refresh()->documentStatus())->toBe(DocumentStatus::Cancelled);
});

it('records a payment with an immutable payment date', function (): void {
    $invoice = GobdInvoice::finalize(immutableHostInvoice());

    GobdInvoice::recordPayment($invoice, (int) $invoice->gross_total, CarbonImmutable::parse('2026-10-01'));

    expect($invoice->refresh()->documentStatus())->toBe(DocumentStatus::Paid)
        ->and($invoice->auditEntries()->where('event', 'payment_recorded')->sole()->context['paid_at'] ?? null)->toBe('2026-10-01');
});

it('computes the due date from an immutable issue date', function (): void {
    $invoice = GobdInvoice::finalize(immutableHostInvoice([
        'issue_date' => '2026-01-10',
        'payment_terms' => ['net_days' => 14],
    ]));

    expect($invoice->due_date?->toDateString())->toBe('2026-01-24');
});
