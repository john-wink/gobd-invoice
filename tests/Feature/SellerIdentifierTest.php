<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * BR-CO-26: the buyer must be able to identify the seller by a Seller
 * identifier (BT-29), a legal registration (BT-30) or a VAT identifier
 * (BT-31). A seller with only a Steuernummer (BT-32, scheme FC) carries it in
 * BT-29 as well, so the e-invoice is valid without a USt-IdNr.
 */

/**
 * @param  array<string, string>  $seller
 */
function invoiceFromSeller(array $seller): Document
{
    return GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, lineSet('100.00'), [
        'seller' => ['name' => 'Tischlerei Vogt', 'address_line' => 'Werkstraße 3', 'postal_code' => '21147', 'city' => 'Hamburg', 'country' => 'DE', ...$seller],
        'service_date' => '2026-02-27',
    ]));
}

it('makes an e-invoice valid with only the Steuernummer of the seller (BR-CO-26)', function (): void {
    $xml = GobdInvoice::eInvoiceXml(invoiceFromSeller(['tax_number' => '49/123/45678']));
    $xpath = ciiXpath($xml);
    $result = GobdInvoice::validateEInvoice($xml);

    expect(ciiValue($xpath, "//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']"))->toBe('49/123/45678')
        ->and(ciiValue($xpath, '//ram:SellerTradeParty/ram:ID'))->toBe('49/123/45678')
        ->and(ciiValue($xpath, "//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']"))->toBeNull()
        ->and($result->hasViolation('BR-CO-26'))->toBeFalse()
        ->and($result->fatals())->toBe([]);
});

it('makes the XRechnung UBL valid with only the Steuernummer of the seller', function (): void {
    $xml = (new XRechnungUblSerializer(new ZugferdCiiSerializer('xrechnung')))->serialize(invoiceFromSeller(['tax_number' => '49/123/45678']));
    $result = GobdInvoice::validateEInvoice($xml);

    expect($result->hasViolation('BR-CO-26'))->toBeFalse()
        ->and($result->fatals())->toBe([]);
});

it('leaves BT-29 empty when the seller has a USt-IdNr', function (): void {
    $xpath = ciiXpath(GobdInvoice::eInvoiceXml(invoiceFromSeller(['tax_number' => '49/123/45678', 'vat_id' => 'DE123456789'])));

    expect(ciiValue($xpath, '//ram:SellerTradeParty/ram:ID'))->toBeNull()
        ->and(ciiValues($xpath, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID'))->toBe(['DE123456789', '49/123/45678']);
});

it('emits a seller identifier and a legal registration the host supplies (BT-29, BT-30)', function (): void {
    $xml = GobdInvoice::eInvoiceXml(invoiceFromSeller([
        'tax_number' => '49/123/45678',
        'identifier' => 'LIEF-4711',
        'legal_registration_id' => 'HRB 12345',
    ]));
    $xpath = ciiXpath($xml);

    expect(ciiValue($xpath, '//ram:SellerTradeParty/ram:ID'))->toBe('LIEF-4711')
        ->and(ciiValue($xpath, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'))->toBe('HRB 12345')
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('does not borrow the Steuernummer when a legal registration identifies the seller', function (): void {
    $xml = GobdInvoice::eInvoiceXml(invoiceFromSeller(['tax_number' => '49/123/45678', 'legal_registration_id' => 'HRB 12345']));
    $xpath = ciiXpath($xml);

    expect(ciiValue($xpath, '//ram:SellerTradeParty/ram:ID'))->toBeNull()
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('still flags a seller without any identifier (BR-CO-26)', function (): void {
    $invoice = invoiceFromSeller([]);

    $result = GobdInvoice::validateEInvoice(GobdInvoice::eInvoiceXml($invoice));

    expect($result->hasViolation('BR-CO-26'))->toBeTrue();
});
