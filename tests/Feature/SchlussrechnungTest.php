<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

function finalizedAbschlag(int $orderId = 0): Document
{
    $abschlag = GobdInvoice::draft(DocumentType::Abschlagsrechnung, [], [
        ['description' => 'Abschlag 1', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]);

    if ($orderId !== 0) {
        $abschlag->documentable_type = 'order';
        $abschlag->documentable_id = $orderId;
        $abschlag->save();
    }

    return GobdInvoice::finalize($abschlag);
}

/**
 * @return array<string, mixed>
 */
function schlussParties(): array
{
    return [
        'seller' => ['name' => 'Muster GmbH', 'address_line' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789'],
        'buyer' => ['name' => 'Kunde AG', 'address_line' => 'Nebenweg 2', 'postal_code' => '80331', 'city' => 'München'],
        'service_date' => '2026-05-31',
    ];
}

it('deducts a prior advance net and VAT in the Schlussrechnung (§14 Abs. 5)', function (): void {
    $abschlag = finalizedAbschlag(); // net 1000, VAT 190, gross 1190

    $schluss = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Schlussrechnung, [
        'deducts' => [$abschlag->id],
    ], [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]));

    // Full contract shown in full; the advance is deducted from the amount due.
    expect($schluss->net_total)->toBe(3000)
        ->and($schluss->vat_total)->toBe(570)
        ->and($schluss->gross_total)->toBe(3570)
        ->and($schluss->advances_net_total)->toBe(1000)
        ->and($schluss->advances_vat_total)->toBe(190)
        ->and($schluss->amount_due)->toBe(2380) // 3570 − (1000 + 190)
        ->and($schluss->advance_deductions)->toHaveCount(1)
        ->and(GobdInvoice::verify($schluss))->toBeTrue();
});

it('fails loud when a deducted advance does not exist', function (): void {
    expect(fn (): Document => GobdInvoice::draft(DocumentType::Schlussrechnung, ['deducts' => [999999]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});

it('refuses to deduct a document that is not an Abschlagsrechnung', function (): void {
    $rechnung = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [
        ['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    expect(fn (): Document => GobdInvoice::draft(DocumentType::Schlussrechnung, ['deducts' => [$rechnung->id]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});

it('blocks a Schlussrechnung that leaves an advance for the same order un-deducted (double-VAT gate)', function (): void {
    config()->set('gobd-invoice.content_validation', false);
    finalizedAbschlag(42);
    config()->set('gobd-invoice.content_validation', true);

    $schluss = GobdInvoice::draft(DocumentType::Schlussrechnung, schlussParties(), [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]); // deducts nothing
    $schluss->documentable_type = 'order';
    $schluss->documentable_id = 42;
    $schluss->save();

    expect(fn (): Document => GobdInvoice::finalize($schluss))
        ->toThrow(DocumentContentException::class, 'undeducted_advances');
});

it('refuses to deduct the same advance twice', function (): void {
    $abschlag = finalizedAbschlag();

    expect(fn (): Document => GobdInvoice::draft(DocumentType::Schlussrechnung, ['deducts' => [$abschlag->id, $abschlag->id]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});

it('refuses to deduct a cancelled advance', function (): void {
    $abschlag = finalizedAbschlag();
    GobdInvoice::cancel($abschlag, 'storniert');

    expect(fn (): Document => GobdInvoice::draft(DocumentType::Schlussrechnung, ['deducts' => [$abschlag->id]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});

it('does not demand a cancelled advance be deducted (VAT already reversed)', function (): void {
    $abschlag = finalizedAbschlag(44);
    GobdInvoice::cancel($abschlag, 'storniert');

    $schluss = GobdInvoice::draft(DocumentType::Schlussrechnung, [], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);
    $schluss->documentable_type = 'order';
    $schluss->documentable_id = 44;
    $schluss->save();

    expect(GobdInvoice::finalize($schluss)->status)->toBe(DocumentStatus::Finalized);
});

it('refuses to deduct an advance from a different order', function (): void {
    $orderA = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [['description' => 'o', 'quantity' => '1', 'unit_price' => '1.00', 'tax_rate' => '19.0']]));
    $orderB = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], [['description' => 'o', 'quantity' => '1', 'unit_price' => '1.00', 'tax_rate' => '19.0']]));

    $abschlag = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Abschlagsrechnung, ['documentable' => $orderA], [
        ['description' => 'Abschlag', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    expect(fn (): Document => GobdInvoice::draft(DocumentType::Schlussrechnung, ['documentable' => $orderB, 'deducts' => [$abschlag->id]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]))->toThrow(GobdInvoiceException::class);
});

it('enforces the double-VAT gate even when field validation is disabled', function (): void {
    config()->set('gobd-invoice.content_validation', false);
    finalizedAbschlag(60);

    $schluss = GobdInvoice::draft(DocumentType::Schlussrechnung, [], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);
    $schluss->documentable_type = 'order';
    $schluss->documentable_id = 60;
    $schluss->save();

    expect(fn (): Document => GobdInvoice::finalize($schluss))
        ->toThrow(DocumentContentException::class, 'undeducted_advances');
});

it('finalizes the Schlussrechnung once the order advance is deducted', function (): void {
    config()->set('gobd-invoice.content_validation', false);
    $abschlag = finalizedAbschlag(43);
    config()->set('gobd-invoice.content_validation', true);

    $schluss = GobdInvoice::draft(DocumentType::Schlussrechnung, [...schlussParties(), 'deducts' => [$abschlag->id]], [
        ['description' => 'Gesamt', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ]);
    $schluss->documentable_type = 'order';
    $schluss->documentable_id = 43;
    $schluss->save();

    $finalized = GobdInvoice::finalize($schluss);

    expect($finalized->status)->toBe(DocumentStatus::Finalized)
        ->and($finalized->advances_net_total)->toBe(1000)
        ->and($finalized->amount_due)->toBe(2380);
});
