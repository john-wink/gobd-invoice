<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\EInvoiceReader;
use JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiReader;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

it('round-trips a finalized Rechnung through CII export and parse', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $parsed = GobdInvoice::parseEInvoice(GobdInvoice::eInvoiceXml($invoice));

    expect($parsed->number)->toBe($invoice->number)
        ->and($parsed->typeCode)->toBe('380')
        ->and($parsed->currency)->toBe('EUR')
        ->and($parsed->seller->name)->toBe('Muster GmbH')
        ->and($parsed->seller->vatId)->toBe('DE123456789')
        ->and($parsed->buyer->name)->toBe('Kunde AG')
        ->and($parsed->grandTotal->minorUnits)->toBe($invoice->gross_total)      // 23800
        ->and($parsed->payableAmount->minorUnits)->toBe($invoice->amount_due)    // 23800
        ->and($parsed->taxBasisTotal->minorUnits)->toBe(20000)
        ->and($parsed->taxTotal->minorUnits)->toBe(3800);
});

it('parses the lines and the tax breakdown from CII', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $parsed = GobdInvoice::parseEInvoice(GobdInvoice::eInvoiceXml($invoice));

    expect($parsed->lines)->toHaveCount(1)
        ->and($parsed->lines[0]->name)->toBe('Beratung')
        ->and($parsed->lines[0]->quantity)->toBe('2')
        ->and($parsed->lines[0]->unitCode)->toBe('HUR')
        ->and($parsed->lines[0]->lineNet->minorUnits)->toBe(20000)
        ->and($parsed->lines[0]->taxCategory)->toBe('S')
        ->and($parsed->lines[0]->taxRate)->toBe('19')
        ->and($parsed->taxBreakdown)->toHaveCount(1)
        ->and($parsed->taxBreakdown[0]->category)->toBe('S')
        ->and($parsed->taxBreakdown[0]->basis->minorUnits)->toBe(20000)
        ->and($parsed->taxBreakdown[0]->tax->minorUnits)->toBe(3800);
});

it('round-trips through UBL syntax (converts UBL back to CII to parse)', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $ubl = (new XRechnungUblSerializer(new ZugferdCiiSerializer('xrechnung')))->serialize($invoice);
    $parsed = GobdInvoice::parseEInvoice($ubl);

    expect($parsed->number)->toBe($invoice->number)
        ->and($parsed->typeCode)->toBe('380')
        ->and($parsed->currency)->toBe('EUR')
        ->and($parsed->seller->name)->toBe('Muster GmbH')
        ->and($parsed->grandTotal->minorUnits)->toBe(11900);
});

it('parses a Storno as a credit note with positive amounts', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));
    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    $parsed = GobdInvoice::parseEInvoice(GobdInvoice::eInvoiceXml($storno));

    expect($parsed->typeCode)->toBe('381')
        ->and($parsed->grandTotal->minorUnits)->toBe(11900);
});

it('throws on a payload that is not a readable e-invoice', function (string $payload): void {
    expect(fn () => GobdInvoice::parseEInvoice($payload))->toThrow(GobdInvoiceException::class);
})->with([
    'not well-formed' => ['<invoice><broken>'],
    'well-formed but not an invoice' => ['<?xml version="1.0"?><foo>bar</foo>'],
]);

it('refuses a non-two-decimal currency rather than silently mis-scaling it', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    // Re-label the invoice currency (BT-5) as JPY (a 0-decimal currency).
    $xml = str_replace('<ram:InvoiceCurrencyCode>EUR', '<ram:InvoiceCurrencyCode>JPY', GobdInvoice::eInvoiceXml($invoice));

    expect(fn () => GobdInvoice::parseEInvoice($xml))->toThrow(GobdInvoiceException::class);
});

it('parses money independent of the LC_NUMERIC locale', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '1234.56', 'tax_rate' => '19.0'],
    ]));
    $xml = GobdInvoice::eInvoiceXml($invoice);

    $previous = setlocale(LC_NUMERIC, '0');
    $applied = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE@euro', 'German', 'de-DE');

    try {
        if ($applied === false || localeconv()['decimal_point'] === '.') {
            $this->markTestSkipped('No comma-decimal locale available on this system.');
        }

        // Under a comma locale, a locale-sensitive sprintf('%.2f') would emit
        // "1469,13" and break Money::fromDecimal — number_format must not.
        $parsed = GobdInvoice::parseEInvoice($xml);

        expect($parsed->grandTotal->minorUnits)->toBe($invoice->gross_total);
    } finally {
        setlocale(LC_NUMERIC, $previous !== false ? $previous : 'C');
    }
});

it('binds the CII reader for incoming e-invoices', function (): void {
    expect(app(EInvoiceReader::class))->toBeInstanceOf(ZugferdCiiReader::class);
});
