<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * The calendar day of a document is the day in the package time zone
 * (gobd-invoice.timezone, by default the app time zone), not the UTC day of
 * the server clock. New Year's Eve 23:30 UTC is already 1 January in Berlin.
 */

afterEach(function (): void {
    Date::setTestNow();
});

function invoiceFinalizedBeforeNewYear(): Document
{
    Date::setTestNow('2026-12-10T10:00:00Z');

    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, ['service_date' => '2026-12-01'], lineSet('100.00')));
}

function newYearsEveInUtc(): void
{
    Date::setTestNow('2026-12-31T23:30:00Z');
    config()->set('gobd-invoice.numbering.format', 'ST{year}-{seq:4}');
}

it('dates and numbers a Storno by the day in the package time zone', function (): void {
    config()->set('gobd-invoice.timezone', 'Europe/Berlin');
    $invoice = invoiceFinalizedBeforeNewYear();
    newYearsEveInUtc();

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect($storno->issue_date?->toDateString())->toBe('2027-01-01')
        ->and($storno->number)->toBe('ST2027-0001')
        ->and($storno->refresh()->issue_date?->toDateString())->toBe('2027-01-01')
        ->and(GobdInvoice::verify($storno))->toBeTrue();
});

it('dates a Storno by the issue date the host hands to cancel()', function (): void {
    config()->set('gobd-invoice.timezone', 'Europe/Berlin');
    $invoice = invoiceFinalizedBeforeNewYear();
    newYearsEveInUtc();

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert', '2026-12-30');

    expect($storno->issue_date?->toDateString())->toBe('2026-12-30')
        ->and($storno->number)->toBe('ST2026-0001')
        ->and(GobdInvoice::verify($storno->refresh()))->toBeTrue();
});

it('falls back to the app time zone without a package time zone', function (): void {
    $invoice = invoiceFinalizedBeforeNewYear();
    newYearsEveInUtc();

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect(config('app.timezone'))->toBe('UTC')
        ->and($storno->issue_date?->toDateString())->toBe('2026-12-31')
        ->and($storno->number)->toBe('ST2026-0001');
});

it('dates a finalization without issue date by the day in the package time zone', function (): void {
    config()->set('gobd-invoice.timezone', 'Europe/Berlin');
    newYearsEveInUtc();

    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, ['service_date' => '2026-12-01'], lineSet('100.00')));

    expect($invoice->issue_date?->toDateString())->toBe('2027-01-01')
        ->and($invoice->year)->toBe(2027);
});

it('records a payment without date on the day in the package time zone', function (): void {
    config()->set('gobd-invoice.timezone', 'Europe/Berlin');
    $invoice = invoiceFinalizedBeforeNewYear();
    newYearsEveInUtc();

    GobdInvoice::recordPayment($invoice, 5000);

    expect($invoice->auditEntries()->where('event', 'payment_recorded')->sole()->context['paid_at'] ?? null)->toBe('2027-01-01');
});
