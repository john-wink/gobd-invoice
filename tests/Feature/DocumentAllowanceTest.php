<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * A Belegnachlass on a document with two rates (19 % and 7 %) is split into
 * one allowance per rate, each with its own category and rate, so the XML
 * reconciles BR-CO-11 (allowance total) and BR-S-08 (taxable amount per rate).
 *
 * Handrechnung, Nachlass 150,00 auf netto 1.000,00 (19 %) + 500,00 (7 %):
 *   19 %: 150,00 × 1.000 / 1.500 = 100,00  →  Basis 900,00, USt 171,00
 *    7 %: 150,00 ×   500 / 1.500 =  50,00  →  Basis 450,00, USt  31,50
 *   netto 1.350,00, USt 202,50, brutto 1.552,50
 */

/**
 * @return array<int, array<string, string>>
 */
function twoRateLines(string $standard = '1000.00', string $reduced = '500.00'): array
{
    return [
        ['description' => 'Montage', 'quantity' => '1', 'unit_price' => $standard, 'tax_rate' => '19.0'],
        ['description' => 'Fachbuch', 'quantity' => '1', 'unit_price' => $reduced, 'tax_rate' => '7.0'],
    ];
}

/**
 * @param  array<string, mixed>  $adjustment
 */
function invoiceWithBelegnachlass(array $adjustment, ?array $lines = null): Document
{
    return GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, $lines ?? twoRateLines(), [
        'service_date' => '2026-02-27',
        'adjustments' => [$adjustment],
    ]));
}

it('splits a Belegnachlass onto the rates of the lines', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'allowance', 'amount_minor' => 15000, 'reason' => 'Treuerabatt', 'split_by_rate' => true]);

    expect($invoice->document_adjustments)->toBe([
        ['type' => 'allowance', 'amount_minor' => 10000, 'percentage' => null, 'base_minor' => null, 'tax_rate' => '19.0', 'tax_category' => 'S', 'reason' => 'Treuerabatt'],
        ['type' => 'allowance', 'amount_minor' => 5000, 'percentage' => null, 'base_minor' => null, 'tax_rate' => '7.0', 'tax_category' => 'S', 'reason' => 'Treuerabatt'],
    ])
        ->and($invoice->allowance_total)->toBe(15000)
        ->and($invoice->net_total)->toBe(135000)
        ->and($invoice->vat_total)->toBe(20250)
        ->and($invoice->gross_total)->toBe(155250);
});

it('emits the split Belegnachlass as one allowance per rate and validates (BR-CO-11, BR-S-08)', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'allowance', 'amount_minor' => 15000, 'reason' => 'Treuerabatt', 'split_by_rate' => true]);

    $xml = GobdInvoice::eInvoiceXml($invoice);
    $xpath = ciiXpath($xml);
    $allowances = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge';
    $result = GobdInvoice::validateEInvoice($xml);

    expect(ciiValues($xpath, "{$allowances}/ram:ActualAmount"))->toBe(['100.00', '50.00'])
        ->and(ciiValues($xpath, "{$allowances}/ram:CategoryTradeTax/ram:CategoryCode"))->toBe(['S', 'S'])
        ->and(ciiValues($xpath, "{$allowances}/ram:CategoryTradeTax/ram:RateApplicablePercent"))->toBe(['19.00', '7.00'])
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount'))->toBe('150.00')
        ->and($result->hasViolation('BR-CO-11'))->toBeFalse()
        ->and($result->hasViolation('BR-S-08'))->toBeFalse()
        ->and($result->fatals())->toBe([]);
});

it('emits a Belegnachlass with its own rate on a single-rate invoice and validates', function (): void {
    $invoice = invoiceWithBelegnachlass(
        ['type' => 'allowance', 'amount_minor' => 1000, 'tax_rate' => '19.0', 'tax_category' => 'S'],
        [['description' => 'Montage', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0']],
    );

    $xml = GobdInvoice::eInvoiceXml($invoice);
    $xpath = ciiXpath($xml);

    expect(ciiValue($xpath, '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge/ram:Reason'))->toBe('Allowance')
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('gives the cents left over by the split to the largest remainders', function (): void {
    $invoice = invoiceWithBelegnachlass(
        ['type' => 'allowance', 'amount_minor' => 100, 'split_by_rate' => true],
        twoRateLines('200.00', '100.00'),
    );

    expect(array_column($invoice->document_adjustments ?? [], 'amount_minor'))->toBe([67, 33])
        ->and($invoice->allowance_total)->toBe(100);
});

it('applies a split percentage to the net of every rate', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'allowance', 'percentage' => '10', 'split_by_rate' => true]);

    $xml = GobdInvoice::eInvoiceXml($invoice);
    $xpath = ciiXpath($xml);
    $allowances = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge';

    expect(array_column($invoice->document_adjustments ?? [], 'amount_minor'))->toBe([10000, 5000])
        ->and(array_column($invoice->document_adjustments ?? [], 'base_minor'))->toBe([100000, 50000])
        ->and(ciiValues($xpath, "{$allowances}/ram:CalculationPercent"))->toBe(['10.00', '10.00'])
        ->and(ciiValues($xpath, "{$allowances}/ram:BasisAmount"))->toBe(['1000.00', '500.00'])
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('splits a Belegzuschlag the same way', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'charge', 'amount_minor' => 3000, 'reason' => 'Anfahrt', 'split_by_rate' => true]);

    $xml = GobdInvoice::eInvoiceXml($invoice);

    expect(array_column($invoice->document_adjustments ?? [], 'amount_minor'))->toBe([2000, 1000])
        ->and($invoice->charge_total)->toBe(3000)
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('emits the Storno of an invoice with a split Belegnachlass as a valid credit note', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'allowance', 'amount_minor' => 15000, 'split_by_rate' => true]);

    $storno = GobdInvoice::cancel($invoice, 'Auftrag storniert');
    $xml = GobdInvoice::eInvoiceXml($storno);
    $xpath = ciiXpath($xml);
    $allowances = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge';

    expect(ciiValues($xpath, "{$allowances}/ram:ChargeIndicator/*"))->toBe(['false', 'false'])
        ->and(ciiValues($xpath, "{$allowances}/ram:ActualAmount"))->toBe(['100.00', '50.00'])
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount'))->toBe('150.00')
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount'))->toBe('1552.50')
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('emits the split Belegnachlass as valid XRechnung UBL', function (): void {
    $invoice = invoiceWithBelegnachlass(['type' => 'allowance', 'amount_minor' => 15000, 'split_by_rate' => true]);

    $xml = (new XRechnungUblSerializer(new ZugferdCiiSerializer('xrechnung')))->serialize($invoice);

    expect(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('refuses a split that names its own rate', function (): void {
    expect(fn (): Document => invoiceWithBelegnachlass(['type' => 'allowance', 'amount_minor' => 100, 'tax_rate' => '19.0', 'split_by_rate' => true]))
        ->toThrow(InvalidArgumentException::class, 'must not name its own');
});

it('refuses a split without lines to split onto', function (): void {
    expect(fn (): Document => GobdInvoice::draft(DocumentType::Rechnung, [
        'adjustments' => [['type' => 'allowance', 'amount_minor' => 100, 'split_by_rate' => true]],
    ]))->toThrow(InvalidArgumentException::class, 'positive net amount');
});

it('splits again when a draft is updated with new lines', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, twoRateLines());

    GobdInvoice::updateDraft($draft, [
        'adjustments' => [['type' => 'allowance', 'amount_minor' => 900, 'split_by_rate' => true]],
    ], twoRateLines('200.00', '100.00'));

    expect(array_column($draft->refresh()->document_adjustments ?? [], 'amount_minor'))->toBe([600, 300]);
});
