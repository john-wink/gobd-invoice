<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\EInvoiceSerializer;
use JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

/**
 * @return DOMXPath a namespace-registered XPath over the UBL payload (also
 *                  asserts the XML is well-formed)
 */
function ublXpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('creditnote', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

    return $xpath;
}

function ublValue(DOMXPath $xpath, string $query): ?string
{
    return $xpath->query($query)?->item(0)?->nodeValue;
}

function ublSerializer(): XRechnungUblSerializer
{
    return new XRechnungUblSerializer(new ZugferdCiiSerializer('xrechnung'));
}

it('exports a finalized Rechnung as an XRechnung UBL Invoice', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $xml = ublSerializer()->serialize($invoice);
    $xpath = ublXpath($xml);

    // A UBL Invoice document (different syntax than CII), with the data carried across.
    expect($xpath->query('/ubl:Invoice')?->length)->toBe(1)
        ->and(ublValue($xpath, '/ubl:Invoice/cbc:ID'))->toBe($invoice->number)              // BT-1
        ->and(ublValue($xpath, '/ubl:Invoice/cbc:InvoiceTypeCode'))->toBe('380')            // BT-3
        ->and(ublValue($xpath, '/ubl:Invoice/cbc:DocumentCurrencyCode'))->toBe('EUR')       // BT-5
        ->and(ublValue($xpath, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'))->toBe('238.00') // BT-112
        ->and(ublValue($xpath, '//cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('238.00')       // BT-115
        ->and(ublValue($xpath, '//cac:TaxTotal/cbc:TaxAmount'))->toBe('38.00')              // BT-110
        ->and($xml)->toContain('Muster GmbH')                                               // BG-4 seller
        ->and($xml)->toContain('Kunde AG');                                                 // BG-7 buyer
});

it('exports a Storno as an XRechnung UBL CreditNote with positive amounts', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    $xml = ublSerializer()->serialize($storno);
    $xpath = ublXpath($xml);

    // The bridge picks the UBL root document from the type code (381 → CreditNote).
    expect($xpath->query('/creditnote:CreditNote')?->length)->toBe(1)
        ->and(ublValue($xpath, '/creditnote:CreditNote/cbc:CreditNoteTypeCode'))->toBe('381')
        ->and(ublValue($xpath, '//cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('119.00'); // positive (BR-27)
});

it('binds the UBL serializer when the format is xrechnung-ubl', function (): void {
    config()->set('gobd-invoice.einvoice.default_format', 'xrechnung-ubl');

    expect(app(EInvoiceSerializer::class))->toBeInstanceOf(XRechnungUblSerializer::class);
});

it('binds the CII serializer for the other formats', function (string $format): void {
    config()->set('gobd-invoice.einvoice.default_format', $format);

    expect(app(EInvoiceSerializer::class))->toBeInstanceOf(ZugferdCiiSerializer::class);
})->with(['zugferd', 'facturx', 'xrechnung']);
