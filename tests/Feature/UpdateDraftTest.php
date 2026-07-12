<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

it('replaces the lines and buyer of an editable draft', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Alt', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]);

    $updated = GobdInvoice::updateDraft($draft, [
        'buyer' => ['name' => 'Neuer Kunde', 'address_line' => 'Weg 9', 'postal_code' => '50667', 'city' => 'Köln', 'country' => 'DE'],
    ], [
        ['description' => 'Neu A', 'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
        ['description' => 'Neu B', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '7.0'],
    ]);

    expect($updated->is($draft))->toBeTrue()                       // same record
        ->and($updated->lines()->count())->toBe(2)                 // lines replaced (was 1)
        ->and($updated->lines->pluck('description')->all())->toBe(['Neu A', 'Neu B'])
        ->and($updated->buyer['name'])->toBe('Neuer Kunde')
        ->and($updated->documentStatus()->value)->toBe('draft');
});

it('refuses to edit a finalized document', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-02-01', 'service_date' => '2026-02-01']));

    expect(fn (): Document => GobdInvoice::updateDraft($invoice, [], [
        ['description' => 'y', 'quantity' => '1', 'unit_price' => '5.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});
