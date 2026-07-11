<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DatevExportException;
use JohnWink\GobdInvoice\Export\Datev\DatevExportOptions;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/** Fixed export metadata so the header row is deterministic. */
function datevOptions(): DatevExportOptions
{
    return new DatevExportOptions(
        berater: 1001,
        mandant: 456,
        fiscalYearStart: new DateTimeImmutable('2026-01-01'),
        description: 'Test-Stapel',
        createdAt: new DateTimeImmutable('2026-03-06 10:25:00'),
    );
}

function datevInvoice(string $unitPrice = '100.00', string $taxRate = '19.0', array $extra = []): Document
{
    return GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => $unitPrice, 'tax_rate' => $taxRate],
    ], array_merge(['issue_date' => '2026-02-21'], $extra)));
}

beforeEach(function (): void {
    config()->set('gobd-invoice.datev.debtor_account', 10000);
    config()->set('gobd-invoice.datev.revenue_accounts', [
        'S:19' => 8400,
        'S:7' => 8300,
    ]);
});

/** Decode the Windows-1252 payload to UTF-8 and split into rows. */
function datevRows(string $payload): array
{
    $utf8 = mb_convert_encoding($payload, 'UTF-8', 'Windows-1252');

    return explode("\r\n", rtrim($utf8, "\r\n"));
}

it('writes an EXTF header row with the DATEV format identity and client metadata', function (): void {
    $rows = datevRows(GobdInvoice::exportDatev([datevInvoice()], datevOptions()));
    $header = explode(';', $rows[0]);

    expect($header)->toHaveCount(31)
        ->and($header[0])->toBe('"EXTF"')
        ->and($header[1])->toBe('700')                    // Versionsnummer
        ->and($header[2])->toBe('21')                     // Formatkategorie = Buchungsstapel
        ->and($header[3])->toBe('"Buchungsstapel"')
        ->and($header[4])->toBe('13')                     // Formatversion
        ->and($header[5])->toBe('20260306102500000')      // erzeugt am (17 digits, ms)
        ->and($header[10])->toBe('1001')                  // Berater
        ->and($header[11])->toBe('456')                   // Mandant
        ->and($header[12])->toBe('20260101')              // WJ-Beginn
        ->and($header[20])->toBe('1')                     // Festschreibung (locked)
        ->and($header[21])->toBe('"EUR"');                // WKZ
});

it('emits the verbatim 125-column heading row as row 2', function (): void {
    $rows = datevRows(GobdInvoice::exportDatev([datevInvoice()], datevOptions()));

    expect($rows[1])->toStartWith('Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;')
        ->and($rows[1])->toEndWith(';Abw. Skontokonto')
        ->and(substr_count($rows[1], ';') + 1)->toBe(125);
});

it('books one row per document: gross to the debtor against the mapped revenue account', function (): void {
    $invoice = datevInvoice();
    $rows = datevRows(GobdInvoice::exportDatev([$invoice], datevOptions()));

    expect($rows)->toHaveCount(3); // header + column headings + one booking

    $booking = explode(';', $rows[2]);

    expect($booking)->toHaveCount(125)
        ->and($booking[0])->toBe('119,00')                    // Umsatz = gross, comma decimal, unsigned
        ->and($booking[1])->toBe('"S"')                       // Soll/Haben
        ->and($booking[2])->toBe('"EUR"')                     // WKZ Umsatz
        ->and($booking[6])->toBe('10000')                     // Konto = debtor
        ->and($booking[7])->toBe('8400')                      // Gegenkonto = revenue (19 %)
        ->and($booking[8])->toBe('')                          // no BU key (automatic account)
        ->and($booking[9])->toBe('2102')                      // Belegdatum = DDMM
        ->and($booking[10])->toBe('"'.$invoice->number.'"')   // Belegfeld 1 = invoice number
        ->and($booking[13])->toBe('"Kunde AG"');              // Buchungstext = buyer
});

it('writes one booking row per VAT group', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Standard', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
        ['description' => 'Ermäßigt', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '7.0'],
    ], ['issue_date' => '2026-02-21']));

    $rows = datevRows(GobdInvoice::exportDatev([$invoice], datevOptions()));

    // header + headings + two bookings.
    expect($rows)->toHaveCount(4);
    $gegenkonten = [explode(';', $rows[2])[7], explode(';', $rows[3])[7]];
    expect($gegenkonten)->toEqualCanonicalizing(['8400', '8300']);
});

it('books a Storno on the Haben side', function (): void {
    $storno = GobdInvoice::cancel(datevInvoice(), 'Widerruf');

    $rows = datevRows(GobdInvoice::exportDatev([$storno], datevOptions()));
    $booking = explode(';', $rows[2]);

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($booking[0])->toBe('119,00')  // amount stays unsigned
        ->and($booking[1])->toBe('"H"');    // direction flips to Haben
});

it('books a self-billing Gutschrift on the Soll side (a positive supply, not a reversal)', function (): void {
    $gutschrift = GobdInvoice::finalize(draftWithParties(DocumentType::Gutschrift, [
        ['description' => 'Eigenbeleg', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-02-21']));

    $booking = explode(';', datevRows(GobdInvoice::exportDatev([$gutschrift], datevOptions()))[2]);

    expect($gutschrift->type)->toBe(DocumentType::Gutschrift)
        ->and($booking[0])->toBe('119,00')
        ->and($booking[1])->toBe('"S"'); // positive amounts -> Soll, unlike a Storno
});

it('skips a VAT group whose gross nets to zero (DATEV rejects a zero Umsatz)', function (): void {
    $invoice = GobdInvoice::finalize(draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Standard', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
        ['description' => 'Ermäßigt', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '7.0'],
    ], [
        'issue_date' => '2026-02-21',
        'adjustments' => [
            ['type' => 'allowance', 'amount_minor' => 5000, 'tax_rate' => '7.0', 'tax_category' => 'S', 'reason' => 'Rabatt'],
        ],
    ]));

    $rows = datevRows(GobdInvoice::exportDatev([$invoice], datevOptions()));

    // The 7 % group nets to 0 and is skipped; only the 19 % group is booked.
    expect($rows)->toHaveCount(3)
        ->and(explode(';', $rows[2])[7])->toBe('8400');
});

it('encodes the file as Windows-1252 with CRLF and no BOM', function (): void {
    $payload = GobdInvoice::exportDatev([datevInvoice()], datevOptions());

    expect($payload)->toContain("\r\n")
        ->and(str_starts_with($payload, "\xEF\xBB\xBF"))->toBeFalse()   // no UTF-8 BOM
        ->and($payload)->toContain("\xFC")                             // "ü" as CP1252 single byte (BU-Schlüssel)
        ->and($payload)->not->toContain("\xC3\xBC");                   // never the UTF-8 "ü"
});

it('skips documents that are not tax-relevant', function (): void {
    $angebot = GobdInvoice::finalize(draftWithParties(DocumentType::Angebot, [
        ['description' => 'Angebot', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-02-21']));

    $rows = datevRows(GobdInvoice::exportDatev([$angebot], datevOptions()));

    expect($rows)->toHaveCount(2); // header + headings only, no bookings
});

it('sanitizes the Buchungstext, stripping the delimiter and quote characters', function (): void {
    $invoice = datevInvoice(extra: [
        'buyer' => ['name' => 'A;B"C', 'address_line' => 'X 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'],
    ]);

    $rows = datevRows(GobdInvoice::exportDatev([$invoice], datevOptions()));
    $booking = explode(';', $rows[2]);

    expect($booking[13])->toBe('"ABC"');
});

it('fails loud when a VAT group has no mapped revenue account', function (): void {
    config()->set('gobd-invoice.datev.revenue_accounts', []); // drop the S:19 mapping

    expect(fn (): string => GobdInvoice::exportDatev([datevInvoice()], datevOptions()))
        ->toThrow(DatevExportException::class);
});

it('fails loud when no debtor account is configured', function (): void {
    config()->set('gobd-invoice.datev.debtor_account', null);

    expect(fn (): string => GobdInvoice::exportDatev([datevInvoice()], datevOptions()))
        ->toThrow(DatevExportException::class);
});
