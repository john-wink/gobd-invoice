<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\EInvoice\XRechnungUblSerializer;
use JohnWink\GobdInvoice\EInvoice\ZugferdCiiSerializer;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Tests\TestCase;

/*
 * A kaufmännische Gutschrift (price reduction, refund) is its own document
 * type: a Rechnungskorrektur, emitted as a 381 credit note that names the
 * invoice it credits (BT-25). The Storno stays a 381 credit note with the
 * same reference, and the Gutschrift stays the 389 self-billed invoice.
 */

function creditedInvoice(): Document
{
    return GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Fliesenarbeiten', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-03-02', 'service_date' => '2026-02-27']));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function rechnungskorrekturFor(Document $invoice, string $unitPrice = '-100.00', array $attributes = []): Document
{
    return draftWithParties(DocumentType::Rechnungskorrektur, [
        ['description' => 'Preisminderung wegen Mängeln', 'quantity' => '1', 'unit_price' => $unitPrice, 'tax_rate' => '19.0'],
    ], ['source_document_id' => $invoice->id, 'service_date' => '2026-02-27', ...$attributes]);
}

it('maps Storno and Rechnungskorrektur to 381 and the self-billing Gutschrift to 389', function (): void {
    expect(DocumentType::Storno->en16931TypeCode())->toBe('381')
        ->and(DocumentType::Rechnungskorrektur->en16931TypeCode())->toBe('381')
        ->and(DocumentType::Gutschrift->en16931TypeCode())->toBe('389')
        ->and(DocumentType::Rechnungskorrektur->isTaxRelevant())->toBeTrue()
        ->and(DocumentType::Rechnungskorrektur->isImmutableOnFinalize())->toBeTrue()
        ->and(DocumentType::Rechnungskorrektur->canEmitEInvoice())->toBeTrue()
        ->and(DocumentType::Rechnungskorrektur->reservesGutschriftLabel())->toBeFalse()
        ->and(DocumentType::Gutschrift->reservesGutschriftLabel())->toBeTrue();
});

it('titles the Rechnungskorrektur without the word Gutschrift', function (): void {
    app()->setLocale('de');

    expect(trans('gobd-invoice::gobd-invoice.document_types.rechnungskorrektur'))->toBe('Rechnungskorrektur')
        ->and(trans('gobd-invoice::gobd-invoice.document_types.storno'))->toBe('Stornorechnung')
        ->and(trans('gobd-invoice::gobd-invoice.document_types.gutschrift'))->toBe('Gutschrift');
});

it('numbers the Rechnungskorrektur in its own series, apart from Storno and Gutschrift', function (): void {
    $invoice = creditedInvoice();

    $korrektur = GobdInvoice::finalize(rechnungskorrekturFor($invoice));
    $storno = GobdInvoice::cancel(creditedInvoice(), 'doppelt');
    $gutschrift = GobdInvoice::finalize(draftWithParties(DocumentType::Gutschrift, [
        ['description' => 'Abrechnung Provision', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '19.0'],
    ], ['service_date' => '2026-02-27']));

    expect($korrektur->series)->toBe('rechnungskorrektur')
        ->and($korrektur->sequence)->toBe(1)
        ->and($storno->series)->toBe('storno')
        ->and($storno->sequence)->toBe(1)
        ->and($gutschrift->series)->toBe('gutschrift')
        ->and($gutschrift->sequence)->toBe(1);
});

it('credits part of an invoice without cancelling it', function (): void {
    $invoice = creditedInvoice();

    $korrektur = GobdInvoice::finalize(rechnungskorrekturFor($invoice));

    expect($korrektur->type)->toBe(DocumentType::Rechnungskorrektur)
        ->and($korrektur->status)->toBe(DocumentStatus::Finalized)
        ->and($korrektur->net_total)->toBe(-10000)
        ->and($korrektur->vat_total)->toBe(-1900)
        ->and($korrektur->gross_total)->toBe(-11900)
        ->and($korrektur->source_document_id)->toBe($invoice->id)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Finalized)
        ->and(GobdInvoice::verify($korrektur))->toBeTrue();
});

it('emits the Rechnungskorrektur as a valid 381 credit note that references the invoice (BT-25)', function (): void {
    $invoice = creditedInvoice();
    $korrektur = GobdInvoice::finalize(rechnungskorrekturFor($invoice));

    $xml = GobdInvoice::eInvoiceXml($korrektur);
    $xpath = ciiXpath($xml);
    $summation = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    expect(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('381')
        ->and(ciiValue($xpath, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'))->toBe($invoice->number)
        ->and(ciiValue($xpath, '//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/*'))->toBe('20260302')
        ->and(ciiValue($xpath, '//ram:IncludedSupplyChainTradeLineItem//ram:LineTotalAmount'))->toBe('100.00')
        ->and(ciiValue($xpath, "{$summation}/ram:GrandTotalAmount"))->toBe('119.00')
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('emits the Rechnungskorrektur as a valid UBL CreditNote with a billing reference', function (): void {
    $invoice = creditedInvoice();
    $korrektur = GobdInvoice::finalize(rechnungskorrekturFor($invoice));

    $xml = (new XRechnungUblSerializer(new ZugferdCiiSerializer('xrechnung')))->serialize($korrektur);
    $xpath = ublXpath($xml);

    expect(ublValue($xpath, '/creditnote:CreditNote/cbc:CreditNoteTypeCode'))->toBe('381')
        ->and(ublValue($xpath, '/creditnote:CreditNote/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'))->toBe($invoice->number)
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('emits the Storno as a valid 381 credit note that references the cancelled invoice (BT-25)', function (): void {
    $invoice = creditedInvoice();
    $storno = GobdInvoice::cancel($invoice, 'Auftrag storniert');

    $xml = GobdInvoice::eInvoiceXml($storno);
    $xpath = ciiXpath($xml);

    expect(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('381')
        ->and(ciiValue($xpath, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'))->toBe($invoice->number)
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('keeps the Gutschrift a valid 389 self-billed invoice without a reference', function (): void {
    $gutschrift = GobdInvoice::finalize(draftWithParties(DocumentType::Gutschrift, [
        ['description' => 'Abrechnung Provision', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '19.0'],
    ], ['service_date' => '2026-02-27']));

    $xml = GobdInvoice::eInvoiceXml($gutschrift);
    $xpath = ciiXpath($xml);

    expect(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe('389')
        ->and(ciiValue($xpath, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'))->toBeNull()
        ->and(GobdInvoice::validateEInvoice($xml)->fatals())->toBe([]);
});

it('refuses a Rechnungskorrektur that credits no invoice', function (): void {
    $korrektur = draftWithParties(DocumentType::Rechnungskorrektur, [
        ['description' => 'Preisminderung', 'quantity' => '1', 'unit_price' => '-100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): Document => GobdInvoice::finalize($korrektur))->toThrow(DocumentContentException::class, 'credited_invoice')
        ->and($korrektur->fresh()?->finalized_at)->toBeNull();
});

it('refuses a Rechnungskorrektur that credits a document other than a festgeschriebene invoice', function (Closure $credited): void {
    $korrektur = rechnungskorrekturFor($credited());

    expect(fn (): Document => GobdInvoice::finalize($korrektur))->toThrow(DocumentContentException::class, 'credited_invoice')
        ->and($korrektur->fresh()?->finalized_at)->toBeNull();
})->with([
    'a draft invoice' => [fn (): Document => draftWithParties(DocumentType::Rechnung, lineSet('100.00'))],
    'an Angebot' => [fn (): Document => GobdInvoice::finalize(draftWithParties(DocumentType::Angebot, lineSet('100.00')))],
    'a Storno' => [fn (): Document => GobdInvoice::cancel(creditedInvoice(), 'doppelt')],
    'a cancelled invoice' => [function (): Document {
        $invoice = creditedInvoice();
        GobdInvoice::cancel($invoice, 'doppelt');

        return $invoice->refresh();
    }],
]);

it('guards a festgeschriebene Rechnungskorrektur and its lines in the database', function (): void {
    $korrektur = GobdInvoice::finalize(rechnungskorrekturFor(creditedInvoice()));

    expect(fn () => DB::table('gobd_documents')->where('id', $korrektur->id)->update(['gross_total' => -1]))->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::table('gobd_documents')->where('id', $korrektur->id)->delete())->toThrow(QueryException::class, 'finalized')
        ->and(fn () => DB::table('gobd_document_lines')->where('document_id', $korrektur->id)->update(['line_net_minor' => -1]))->toThrow(QueryException::class, 'finalized');
})->skip(! TestCase::usesPostgres(), 'The guard triggers exist on PostgreSQL only.');

it('refuses a Rechnungskorrektur that does not lower the amount', function (): void {
    $korrektur = rechnungskorrekturFor(creditedInvoice(), '100.00');

    expect(fn (): Document => GobdInvoice::finalize($korrektur))->toThrow(DocumentContentException::class, 'credit_amount')
        ->and($korrektur->fresh()?->finalized_at)->toBeNull();
});
