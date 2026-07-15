<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

/**
 * @return DOMXPath a namespace-registered XPath over the CII payload (also
 *                  asserts the XML is well-formed)
 */
function ciiXpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

    return $xpath;
}

function ciiValue(DOMXPath $xpath, string $query): ?string
{
    return $xpath->query($query)?->item(0)?->nodeValue;
}

/**
 * @return list<string>
 */
function ciiValues(DOMXPath $xpath, string $query): array
{
    $values = [];
    $nodes = $xpath->query($query);
    foreach ($nodes ?: [] as $node) {
        $values[] = (string) $node->nodeValue;
    }

    return $values;
}

it('exports a finalized Rechnung as EN 16931 CII XML', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['service_date' => '2026-06-20']));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($invoice));

    expect(ciiValue($xpath, '//rsm:ExchangedDocument/ram:ID'))->toBe($invoice->number)          // BT-1
        ->and(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('380')            // BT-3
        ->and(ciiValue($xpath, '//ram:InvoiceCurrencyCode'))->toBe('EUR')                       // BT-5
        ->and(ciiValue($xpath, '//ram:SellerTradeParty/ram:Name'))->toBe('Muster GmbH')         // BG-4
        ->and(ciiValue($xpath, '//ram:BuyerTradeParty/ram:Name'))->toBe('Kunde AG')             // BG-7
        ->and(ciiValues($xpath, '//ram:ApplicableTradeTax/ram:RateApplicablePercent'))->toContain('19.00') // BT-119
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount'))->toBe('200.00')  // BT-106
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount'))->toBe('200.00') // BT-109
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount'))->toBe('238.00')   // BT-112
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount'))->toBe('238.00');  // BT-115
});

it('emits a due date so a term-less invoice satisfies EN 16931 BR-CO-25', function (): void {
    // No payment terms set — the serializer must still emit BT-9 (due date =
    // issue date) so the validation has no fatal violations.
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['service_date' => '2026-06-20']));

    $xml = GobdInvoice::eInvoiceXml($invoice);

    expect(ciiValue(ciiXpath($xml), '//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'))->not->toBeNull()
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBeEmpty();
});

it('maps the seller VAT id and the line detail', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($invoice));

    expect(ciiValues($xpath, '//ram:SellerTradeParty//ram:SpecifiedTaxRegistration/ram:ID'))->toContain('DE123456789')
        ->and(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:Name'))->toBe('Beratung')
        ->and(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:BilledQuantity'))->toBe('2.00')
        ->and(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:LineTotalAmount'))->toBe('200.00'); // BT-131
});

it('refuses to export a draft', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): string => GobdInvoice::eInvoiceXml($draft))->toThrow(GobdInvoiceException::class);
});

it('refuses to export a non-invoice document type', function (): void {
    $angebot = draftWithParties(DocumentType::Angebot, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): string => (new ZugferdCiiSerializer('en16931'))->serialize($angebot))
        ->toThrow(GobdInvoiceException::class);
});

it('refuses the MINIMUM and BASIC WL profiles (not a valid invoice under §14)', function (string $profile): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    expect(fn (): string => (new ZugferdCiiSerializer($profile))->serialize($invoice))
        ->toThrow(GobdInvoiceException::class);
})->with(['minimum', 'basicwl', 'basic-wl']);

it('emits a Storno as a 381 credit note with positive amounts (BR-27)', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($storno));
    $summation = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    // A credit note conveys the reversal via the type code, not a negative sign:
    // every amount must be positive (BR-27 forbids a negative item net price).
    expect(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('381')
        ->and(ciiValue($xpath, '//ram:NetPriceProductTradePrice/ram:ChargeAmount'))->toBe('100.00') // BT-146
        ->and(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:LineTotalAmount'))->toBe('100.00') // BT-131
        ->and(ciiValue($xpath, "{$summation}/ram:GrandTotalAmount"))->toBe('119.00') // BT-112
        ->and(ciiValue($xpath, "{$summation}/ram:DuePayableAmount"))->toBe('119.00'); // BT-115
});

it('emits the accounting currency (BT-6) and EUR VAT total (BT-111) for a non-EUR invoice', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Export', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['currency' => 'USD', 'accounting_rate' => ['base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.90']]));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($invoice));

    expect(ciiValue($xpath, '//ram:InvoiceCurrencyCode'))->toBe('USD')  // BT-5
        ->and(ciiValue($xpath, '//ram:TaxCurrencyCode'))->toBe('EUR')   // BT-6
        ->and(ciiValues($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount/@currencyID'))->toContain('EUR'); // BT-111
});

it('maps an uppercase unit abbreviation to its UN/ECE code (BT-130)', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Material', 'quantity' => '2', 'unit' => 'KG', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($invoice));

    expect(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:BilledQuantity/@unitCode'))->toBe('KGM');
});

it('reports the deducted advance as prepaid so the amount due reconciles (BR-CO-16)', function (): void {
    $abschlag = GobdInvoice::finalize(draftWithParties(DocumentType::Abschlagsrechnung, [
        ['description' => 'Abschlag', 'quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '19.0'],
    ]));

    $schluss = GobdInvoice::finalize(draftWithParties(DocumentType::Schlussrechnung, [
        ['description' => 'Gesamtleistung', 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '19.0'],
    ], ['deducts' => [$abschlag->id]]));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($schluss));

    $summation = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    // gross 35.70, prepaid = advance gross 11.90, due 23.80.
    expect(ciiValue($xpath, "{$summation}/ram:GrandTotalAmount"))->toBe('35.70')
        ->and(ciiValue($xpath, "{$summation}/ram:TotalPrepaidAmount"))->toBe('11.90')
        ->and(ciiValue($xpath, "{$summation}/ram:DuePayableAmount"))->toBe('23.80');
});

it('adds a VAT exemption reason for a reverse-charge group', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Bauleistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0.0', 'tax_category' => 'AE'],
    ], ['buyer' => ['name' => 'Kunde AG', 'address_line' => 'Nebenweg 2', 'postal_code' => '80331', 'city' => 'München', 'country' => 'DE', 'vat_id' => 'DE987654321']]));

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml($invoice));

    expect(ciiValues($xpath, '//ram:ApplicableTradeTax/ram:CategoryCode'))->toContain('AE')
        ->and(ciiValue($xpath, '//ram:ApplicableTradeTax/ram:ExemptionReason'))->not->toBeNull();
});

it('refuses a reverse-charge invoice without a buyer VAT id (BR-AE-02, BT-48)', function (): void {
    // draftWithParties() gives the buyer no vat_id.
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Bauleistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0.0', 'tax_category' => 'AE'],
    ]));

    expect(fn (): string => GobdInvoice::eInvoiceXml($invoice))->toThrow(GobdInvoiceException::class);
});

it('uses a host-supplied exemption note (meta.exemption_note) for BT-120', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Kleinunternehmer-Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0.0', 'tax_category' => 'E'],
    ]);
    $draft->meta = ['exemption_note' => 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.'];
    $draft->save();

    $xpath = ciiXpath(GobdInvoice::eInvoiceXml(GobdInvoice::finalize($draft)));

    expect(ciiValue($xpath, '//ram:ApplicableTradeTax/ram:ExemptionReason'))
        ->toBe('Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.');
});
