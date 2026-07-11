<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function finalizedAnzahlung(int $orderId): Document
{
    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Anzahlungsrechnung, [
        'documentable_type' => 'order',
        'documentable_id' => $orderId,
    ], [
        ['description' => 'Anzahlung', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));
}

it('classifies an Anzahlungsrechnung as a tax-relevant, deductible advance invoice', function (): void {
    expect(DocumentType::Anzahlungsrechnung->isAdvanceInvoice())->toBeTrue()
        ->and(DocumentType::Anzahlungsrechnung->isTaxRelevant())->toBeTrue()
        ->and(DocumentType::Anzahlungsrechnung->canEmitEInvoice())->toBeTrue()
        ->and(DocumentType::Anzahlungsrechnung->en16931TypeCode())->toBe('386');
});

it('blocks a Schlussrechnung while an Anzahlungsrechnung for the order is undeducted (§14 Abs. 5)', function (): void {
    finalizedAnzahlung(77);

    $schluss = GobdInvoice::draft(DocumentType::Schlussrechnung, [
        'documentable_type' => 'order',
        'documentable_id' => 77,
    ], [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::finalize($schluss))->toThrow(DocumentContentException::class);
});

it('deducts an Anzahlungsrechnung net and VAT in the Schlussrechnung', function (): void {
    $anzahlung = finalizedAnzahlung(78);

    $schluss = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Schlussrechnung, [
        'documentable_type' => 'order',
        'documentable_id' => 78,
        'deducts' => [$anzahlung->id],
    ], [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]));

    expect($schluss->advances_net_total)->toBe(1000)
        ->and($schluss->advances_vat_total)->toBe(190)
        ->and($schluss->amount_due)->toBe(2380)
        ->and(GobdInvoice::verify($schluss))->toBeTrue();
});
