<?php

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use JohnWink\GobdInvoice\Contracts\EInvoicePdfBuilder;
use JohnWink\GobdInvoice\EInvoice\ZugferdPdfBuilder;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

function basePdf(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/base-invoice.pdf');
}

it('embeds the CII XML into a base PDF as a hybrid ZUGFeRD PDF/A-3', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]));

    $pdf = GobdInvoice::eInvoicePdf($invoice, basePdf());

    // A PDF came out, and the embedded XML round-trips through the PDF reader.
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue();

    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($pdf);
    $number = null;
    $reader->getDocumentInformation($number, $typeCode, $issueDate, $currency, $taxCurrency, $name, $language, $period);

    expect($number)->toBe($invoice->number);
});

it('refuses to build a PDF for a draft (delegates the finalized guard)', function (): void {
    $draft = draftWithParties(DocumentType::Rechnung, [
        ['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ]);

    expect(fn (): string => GobdInvoice::eInvoicePdf($draft, basePdf()))
        ->toThrow(JohnWink\GobdInvoice\Exceptions\GobdInvoiceException::class);
});

it('binds the ZUGFeRD PDF builder', function (): void {
    expect(app(EInvoicePdfBuilder::class))->toBeInstanceOf(ZugferdPdfBuilder::class);
});
