<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * Handrechnung (EUR, 19 %):
 *
 *   Abschlagsrechnung        netto 1.000,00   USt 190,00   brutto 1.190,00
 *   Schlussrechnung gesamt   netto 3.000,00   USt 570,00   brutto 3.570,00
 *   abzüglich Abschlag       netto 1.000,00   USt 190,00   brutto 1.190,00
 *   offen aus der Schlussrechnung                          brutto 2.380,00
 *
 *   Zahlung 1.000,00  →  offen 1.380,00, teilweise bezahlt
 *   Zahlung 1.380,00  →  offen     0,00, bezahlt
 */

function schlussrechnungAfterAbschlag(): Document
{
    $abschlag = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Abschlagsrechnung, [], [
        ['description' => 'Abschlag 1', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_rate' => '19.0'],
    ]));

    return GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Schlussrechnung, ['deducts' => [$abschlag->id]], [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '3000.00', 'tax_rate' => '19.0'],
    ]));
}

it('starts the Schlussrechnung with the gross total less the deducted advance as amount due', function (): void {
    $schluss = schlussrechnungAfterAbschlag();

    expect($schluss->gross_total)->toBe(357000)
        ->and($schluss->advances_net_total)->toBe(100000)
        ->and($schluss->advances_vat_total)->toBe(19000)
        ->and($schluss->amount_due)->toBe(238000);
});

it('counts the deducted advance when a partial payment is booked', function (): void {
    $schluss = schlussrechnungAfterAbschlag();

    GobdInvoice::recordPayment($schluss, 100000);

    expect($schluss->refresh()->status)->toBe(DocumentStatus::PartiallyPaid)
        ->and($schluss->paid_total)->toBe(100000)
        ->and($schluss->amount_due)->toBe(138000);
});

it('marks the Schlussrechnung paid once the amount left after the advance is paid', function (): void {
    $schluss = schlussrechnungAfterAbschlag();

    GobdInvoice::recordPayment($schluss, 100000);
    GobdInvoice::recordPayment($schluss, 138000);

    expect($schluss->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and($schluss->paid_total)->toBe(238000)
        ->and($schluss->amount_due)->toBe(0)
        ->and(GobdInvoice::verify($schluss))->toBeTrue();
});

it('marks the Schlussrechnung paid by one payment of exactly the amount due', function (): void {
    $schluss = schlussrechnungAfterAbschlag();

    GobdInvoice::recordPayment($schluss, (int) $schluss->amount_due);

    expect($schluss->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and($schluss->amount_due)->toBe(0);
});

it('keeps an invoice without advances payable at its gross total', function (): void {
    $invoice = GobdInvoice::finalize(GobdInvoice::draft(DocumentType::Rechnung, [], lineSet('100.00')));

    GobdInvoice::recordPayment($invoice, 11899);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::PartiallyPaid)
        ->and($invoice->amount_due)->toBe(1);
});
