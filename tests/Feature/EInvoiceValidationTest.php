<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\EInvoiceValidator;
use JohnWink\GobdInvoice\EInvoice\NativeEInvoiceValidator;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

function validInvoiceXml(): string
{
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    return GobdInvoice::eInvoiceXml($invoice);
}

it('validates a produced ZUGFeRD invoice as EN 16931 conformant', function (): void {
    $result = GobdInvoice::validateEInvoice(validInvoiceXml());

    expect($result->isValid())->toBeTrue()
        ->and($result->violations)->toBe([]);
});

it('validates a produced UBL invoice (bridged to CII)', function (): void {
    $ubl = (new JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer(
        new JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer('xrechnung'),
    ))->serialize(GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ])));

    expect(GobdInvoice::validateEInvoice($ubl)->isValid())->toBeTrue();
});

it('flags a tampered grand total (BR-CO-15)', function (): void {
    $xml = str_replace(
        '<ram:GrandTotalAmount>238.00</ram:GrandTotalAmount>',
        '<ram:GrandTotalAmount>999.00</ram:GrandTotalAmount>',
        validInvoiceXml(),
    );

    $result = GobdInvoice::validateEInvoice($xml);

    expect($result->isValid())->toBeFalse()
        ->and($result->hasViolation('BR-CO-15'))->toBeTrue();
});

it('lets a valid invoice through the validate-on-export gate', function (): void {
    config()->set('gobd-invoice.einvoice.validate_on_export', true);

    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    expect(GobdInvoice::eInvoiceXml($invoice))->toContain('CrossIndustryInvoice');
});

it('binds the native validator', function (): void {
    expect(app(EInvoiceValidator::class))->toBeInstanceOf(NativeEInvoiceValidator::class);
});
