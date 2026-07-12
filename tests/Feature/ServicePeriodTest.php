<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

it('finalizes with a Leistungszeitraum instead of a single service date', function (): void {
    config()->set('gobd-invoice.content_validation', true);

    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Wartung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], [
        'issue_date' => '2026-07-05',
        'service_period_start' => '2026-06-01',
        'service_period_end' => '2026-06-30',
    ]));

    expect($invoice->documentStatus()->value)->toBe('finalized')
        ->and($invoice->service_date)->toBeNull()
        ->and($invoice->service_period_start?->toDateString())->toBe('2026-06-01')
        ->and($invoice->service_period_end?->toDateString())->toBe('2026-06-30')
        ->and(GobdInvoice::verify($invoice))->toBeTrue();
});

it('maps the Leistungszeitraum to the EN 16931 invoicing period (BT-73/74)', function (): void {
    config()->set('gobd-invoice.content_validation', true);

    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Wartung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], [
        'issue_date' => '2026-07-05',
        'service_period_start' => '2026-06-01',
        'service_period_end' => '2026-06-30',
    ]));

    $xml = GobdInvoice::eInvoiceXml($invoice);

    expect($xml)->toContain('BillingSpecifiedPeriod')
        ->and($xml)->toContain('20260601')
        ->and($xml)->toContain('20260630');
});

it('still rejects finalization when neither a date nor a period is given', function (): void {
    config()->set('gobd-invoice.content_validation', true);

    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Wartung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-07-05']);

    expect(fn () => GobdInvoice::finalize($draft))
        ->toThrow(DocumentContentException::class, 'service_date');
});
