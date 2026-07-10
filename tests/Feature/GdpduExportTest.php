<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\GobdDataExporter;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Export\GdpduExporter;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

it('exports finalized documents as a GDPdU data set (CSV tables + index.xml)', function (): void {
    $one = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));
    $two = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Material', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '19.0'],
    ]));

    $files = GobdInvoice::exportGdpdu([$one, $two]);

    expect($files)->toHaveKeys(['rechnungen.csv', 'positionen.csv', 'index.xml']);

    // The document table carries both invoices with their (semicolon-delimited,
    // quoted, decimal-point) totals.
    expect($files['rechnungen.csv'])->toContain('"'.$one->number.'"')
        ->and($files['rechnungen.csv'])->toContain('"'.$two->number.'"')
        ->and($files['rechnungen.csv'])->toContain('"238.00"')   // one: 2 × 100 + 19%
        ->and($files['rechnungen.csv'])->toContain('"59.50"');   // two: 50 + 19%

    // The line table carries the positions.
    expect($files['positionen.csv'])->toContain('"Beratung"')
        ->and($files['positionen.csv'])->toContain('"Material"');
});

it('produces a well-formed GDPdU descriptor referencing both tables', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $index = GobdInvoice::exportGdpdu([$invoice])['index.xml'];

    $dom = new DOMDocument;
    expect($dom->loadXML($index))->toBeTrue()
        ->and($index)->toContain('<File>rechnungen.csv</File>')
        ->and($index)->toContain('<File>positionen.csv</File>')
        ->and($index)->toContain('<Name>Rechnungsnummer</Name>')
        ->and($index)->toContain('<Numeric><Accuracy>2</Accuracy></Numeric>');
});

it('binds the GDPdU exporter', function (): void {
    expect(app(GobdDataExporter::class))->toBeInstanceOf(GdpduExporter::class);
});
