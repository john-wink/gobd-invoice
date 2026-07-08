<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

it('converts an Angebot into a Rechnung draft, copying lines and parties', function (): void {
    $angebot = GobdInvoice::draft(DocumentType::Angebot, [
        'seller' => ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789'],
        'buyer' => ['name' => 'Kunde AG', 'address_line' => 'Nebenweg 2', 'postal_code' => '80331', 'city' => 'München'],
    ], [
        ['description' => 'Position A', 'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    $rechnung = GobdInvoice::convert($angebot, DocumentType::Rechnung);

    expect($rechnung->type)->toBe(DocumentType::Rechnung)
        ->and($rechnung->status)->toBe(DocumentStatus::Draft)
        ->and($rechnung->number)->toBeNull()
        ->and($rechnung->source_document_id)->toBe($angebot->id)
        ->and($rechnung->seller)->toMatchArray(['name' => 'Muster GmbH', 'vat_id' => 'DE123456789'])
        ->and($rechnung->buyer)->toMatchArray(['name' => 'Kunde AG'])
        ->and($rechnung->lines)->toHaveCount(1)
        ->and($rechnung->lines->first()?->line_net_minor)->toBe(20000);
});

it('finalizes a converted invoice with the source link intact', function (): void {
    $angebot = GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    $rechnung = GobdInvoice::finalize(GobdInvoice::convert($angebot, DocumentType::Rechnung));

    expect($rechnung->status)->toBe(DocumentStatus::Finalized)
        ->and($rechnung->number)->not->toBeNull()
        ->and($rechnung->gross_total)->toBe(11900)
        ->and($rechnung->source_document_id)->toBe($angebot->id);
});

it('carries the source host link (documentable) forward', function (): void {
    $angebot = GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);
    $angebot->documentable_type = 'order';
    $angebot->documentable_id = 7;
    $angebot->save();

    $rechnung = GobdInvoice::convert($angebot, DocumentType::Rechnung);

    expect($rechnung->documentable_type)->toBe('order')
        ->and($rechnung->documentable_id)->toBe(7);
});

it('rejects a disallowed conversion', function (DocumentType $from, DocumentType $to): void {
    $source = GobdInvoice::draft($from, [], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::convert($source, $to))->toThrow(GobdInvoiceException::class);
})->with([
    'invoice is not a source' => [DocumentType::Rechnung, DocumentType::Angebot],
    'offer to offer' => [DocumentType::Angebot, DocumentType::Angebot],
    'dunning is not a source' => [DocumentType::Mahnung, DocumentType::Rechnung],
]);

it('converts an Angebot into a Schlussrechnung deducting an advance (override)', function (): void {
    $abschlag = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Abschlagsrechnung, [], [
        ['description' => 'Abschlag', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    $angebot = GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);

    $schluss = GobdInvoice::finalize(GobdInvoice::convert($angebot, DocumentType::Schlussrechnung, ['deducts' => [$abschlag->id]]));

    expect($schluss->type)->toBe(DocumentType::Schlussrechnung)
        ->and($schluss->advances_net_total)->toBe(1000)
        ->and($schluss->amount_due)->toBe(2380);
});

it('rejects converting to a Schlussrechnung that deducts a different order\'s advance', function (): void {
    // A finalized advance belonging to order 2.
    $abschlagB = GobdInvoice::draft(DocumentType::Abschlagsrechnung, [], [
        ['description' => 'Abschlag B', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]);
    $abschlagB->documentable_type = 'order';
    $abschlagB->documentable_id = 2;
    $abschlagB->save();
    GobdInvoice::finalize($abschlagB);

    // An Angebot for order 1 — converting it to a Schlussrechnung must not
    // deduct order 2's advance (the documentable is carried to draft() so the
    // cross-order guard fires there, matching the direct draft() path).
    $angebot = GobdInvoice::draft(DocumentType::Angebot, [], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);
    $angebot->documentable_type = 'order';
    $angebot->documentable_id = 1;
    $angebot->save();

    expect(fn (): Document => GobdInvoice::convert($angebot, DocumentType::Schlussrechnung, ['deducts' => [$abschlagB->id]]))
        ->toThrow(GobdInvoiceException::class);
});

it('preserves the source currency and ignores a currency override (no FX)', function (): void {
    $angebot = GobdInvoice::draft(DocumentType::Angebot, ['currency' => 'EUR'], [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    $rechnung = GobdInvoice::convert($angebot, DocumentType::Rechnung, ['currency' => 'USD']);

    expect($rechnung->currency)->toBe('EUR')
        ->and($rechnung->lines->first()?->line_net_minor)->toBe(10000);
});

it('converts a signed Leistungsnachweis into an Abschlagsrechnung', function (): void {
    $nachweis = GobdInvoice::draft(DocumentType::Leistungsnachweis, [], [
        ['description' => 'Stundenlohn', 'quantity' => '8', 'unit_price' => '75.00', 'tax_rate' => '19.0'],
    ]);

    $abschlag = GobdInvoice::convert($nachweis, DocumentType::Abschlagsrechnung);

    expect($abschlag->type)->toBe(DocumentType::Abschlagsrechnung)
        ->and($abschlag->source_document_id)->toBe($nachweis->id)
        ->and($abschlag->lines->first()?->line_net_minor)->toBe(60000); // 75.00 × 8
});
