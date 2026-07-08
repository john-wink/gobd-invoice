<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

beforeEach(function (): void {
    config()->set('gobd-invoice.content_validation', true);
});

/**
 * @return array<string, string>
 */
function completeSeller(): array
{
    return ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789'];
}

/**
 * @return array<string, string>
 */
function completeBuyer(): array
{
    return ['name' => 'Kunde AG', 'address_line' => 'Nebenweg 2', 'postal_code' => '80331', 'city' => 'München'];
}

it('finalizes a §14-complete invoice when content validation is enabled', function (): void {
    $document = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [
        'seller' => completeSeller(),
        'buyer' => completeBuyer(),
        'service_date' => '2026-05-31',
    ], [
        ['description' => 'Beratungsleistung', 'quantity' => '3', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    expect($document->status)->toBe(DocumentStatus::Finalized)
        ->and($document->seller)->toMatchArray(['name' => 'Muster GmbH', 'vat_id' => 'DE123456789'])
        ->and(GobdInvoice::verify($document))->toBeTrue();
});

it('fails closed when §14 mandatory fields are missing', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::finalize($draft))
        ->toThrow(DocumentContentException::class, 'seller_name_address');
});

it('reports each missing field as a violation', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '19.0'],
    ]);

    try {
        GobdInvoice::finalize($draft);
        $this->fail('Expected DocumentContentException.');
    } catch (DocumentContentException $documentContentException) {
        expect($documentContentException->violations)
            ->toContain('seller_name_address', 'seller_tax_id', 'buyer_name_address', 'service_date');
    }
});

it('does not finalize (assign a number) when content validation fails', function (): void {
    $draft = GobdInvoice::draft(DocumentType::Rechnung, ['service_date' => '2026-05-31'], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '19.0'],
    ]);

    try {
        GobdInvoice::finalize($draft);
    } catch (DocumentContentException) {
        // expected
    }

    expect($draft->fresh()?->number)->toBeNull()
        ->and($draft->fresh()?->status)->toBe(DocumentStatus::Draft);
});

it('relaxes recipient and supplier tax id for a Kleinbetragsrechnung (§33 UStDV)', function (): void {
    // Seller name + address only, no buyer, no tax id; gross ≤ €250.
    $document = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [
        'seller' => ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin'],
        'service_date' => '2026-05-31',
    ], [
        ['description' => 'Kleinteil', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    expect($document->status)->toBe(DocumentStatus::Finalized)
        ->and($document->gross_total)->toBe(1190);
});

it('requires the full §14 set once the Kleinbetrag ceiling is exceeded', function (): void {
    // Same reduced data but gross > €250 → recipient + supplier tax id required.
    $draft = GobdInvoice::draft(DocumentType::Rechnung, [
        'seller' => ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin'],
        'service_date' => '2026-05-31',
    ], [
        ['description' => 'Ware', 'quantity' => '1', 'unit_price' => '300.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::finalize($draft))
        ->toThrow(DocumentContentException::class, 'buyer_name_address');
});

it('skips §14 validation for a non-invoice document type', function (): void {
    // An Angebot is not a §14 invoice, so it finalizes without parties.
    $document = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'Angebotsposition', 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '19.0'],
    ]));

    expect($document->status)->toBe(DocumentStatus::Finalized);
});

it('carries the parties into the Storno so it too passes §14 validation', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [
        'seller' => completeSeller(),
        'buyer' => completeBuyer(),
        'service_date' => '2026-05-31',
    ], [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $storno = GobdInvoice::cancel($invoice, 'Storno');

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($storno->seller)->toMatchArray(['name' => 'Muster GmbH'])
        ->and($storno->gross_total)->toBe(-11900);
});
