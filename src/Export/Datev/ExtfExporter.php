<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Export\Datev;

use DateTimeImmutable;
use DateTimeInterface;
use JohnWink\GobdInvoice\Contracts\DatevAccountResolver;
use JohnWink\GobdInvoice\Contracts\DatevExporter;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Produces a DATEV "EXTF" Buchungsstapel (format 700, layout version 13). One
 * booking row per non-zero VAT group of each tax-relevant document: the gross
 * amount posted to the debtor account ("Konto") against the mapped revenue
 * account ("Gegenkonto"), always unsigned — the direction is carried by the
 * Soll/Haben indicator, taken from the sign of the group (a negative group, i.e.
 * a Storno's negated amounts, books "H"; a positive one books "S").
 *
 * The file is Windows-1252 (no BOM), CRLF-terminated, semicolon-separated with
 * comma decimals — the byte layout DATEV's importer requires. The column-heading
 * row and field count are taken verbatim from DATEV's own reference file, so
 * every row is padded to exactly that width. See docs/research and
 * https://developer.datev.de/de/file-format.
 *
 * Scope: bookings are expressed in the batch currency; the per-row Kurs /
 * Basisumsatz (foreign-currency valuation) is not emitted, so a batch should not
 * mix currencies. See DatevExportOptions::$currency.
 */
final readonly class ExtfExporter implements DatevExporter
{
    private const string DELIMITER = ';';

    private const string RECORD = "\r\n";

    /** DATEV transport format version × 100 (header field 2). */
    private const string VERSIONSNUMMER = '700';

    /** Data category 21 = Buchungsstapel (header field 3). */
    private const string FORMATKATEGORIE = '21';

    private const string FORMATNAME = 'Buchungsstapel';

    /** Buchungsstapel layout version (header field 5) — dictates the column set. */
    private const string FORMATVERSION = '13';

    /** 1 = Finanzbuchführung (header field 19). */
    private const string BUCHUNGSTYP = '1';

    /**
     * The verbatim column-heading row (row 2) from DATEV's reference
     * EXTF_Buchungsstapel.csv for layout version 13. It is the single source of
     * truth for the field count: every data row is padded to match it.
     */
    private const string COLUMN_HEADINGS = 'Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basisumsatz;WKZ Basisumsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext;Postensperre;Diverse Adressnummer;Geschäftspartnerbank;Sachverhalt;Zinssperre;Beleglink;Beleginfo – Art 1;Beleginfo – Inhalt 1;Beleginfo – Art 2;Beleginfo – Inhalt 2;Beleginfo – Art 3;Beleginfo – Inhalt 3;Beleginfo – Art 4;Beleginfo – Inhalt 4;Beleginfo – Art 5;Beleginfo – Inhalt 5;Beleginfo – Art 6;Beleginfo – Inhalt 6;Beleginfo – Art 7;Beleginfo – Inhalt 7;Beleginfo – Art 8;Beleginfo – Inhalt 8;KOST1 – Kostenstelle;KOST2 – Kostenstelle;Kost Menge;EU-Land u. USt-IdNr.;EU-Steuersatz;Abw. Versteuerungsart;Sachverhalt L+L;Funktionsergänzung L+L;BU 49 Hauptfunktionstyp;BU 49 Hauptfunktionsnummer;BU 49 Funktionsergänzung;Zusatzinformation – Art 1;Zusatzinformation – Inhalt 1;Zusatzinformation – Art 2;Zusatzinformation – Inhalt 2;Zusatzinformation – Art 3;Zusatzinformation – Inhalt 3;Zusatzinformation – Art 4;Zusatzinformation – Inhalt 4;Zusatzinformation – Art 5;Zusatzinformation – Inhalt 5;Zusatzinformation – Art 6;Zusatzinformation – Inhalt 6;Zusatzinformation – Art 7;Zusatzinformation – Inhalt 7;Zusatzinformation – Art 8;Zusatzinformation – Inhalt 8;Zusatzinformation – Art 9;Zusatzinformation – Inhalt 9;Zusatzinformation – Art 10;Zusatzinformation – Inhalt 10;Zusatzinformation – Art 11;Zusatzinformation – Inhalt 11;Zusatzinformation – Art 12;Zusatzinformation – Inhalt 12;Zusatzinformation – Art 13;Zusatzinformation – Inhalt 13;Zusatzinformation – Art 14;Zusatzinformation – Inhalt 14;Zusatzinformation – Art 15;Zusatzinformation – Inhalt 15;Zusatzinformation – Art 16;Zusatzinformation – Inhalt 16;Zusatzinformation – Art 17;Zusatzinformation – Inhalt 17;Zusatzinformation – Art 18;Zusatzinformation – Inhalt 18;Zusatzinformation – Art 19;Zusatzinformation – Inhalt 19;Zusatzinformation – Art 20;Zusatzinformation – Inhalt 20;Stück;Gewicht;Zahlweise;Forderungsart;Veranlagungsjahr;Zugeordnete Fälligkeit;Skontotyp;Auftragsnummer;Buchungstyp;USt-Schlüssel (Anzahlungen);EU-Mitgliedstaat (Anzahlungen);Sachverhalt L+L (Anzahlungen);EU-Steuersatz (Anzahlungen);Erlöskonto (Anzahlungen);Herkunft-Kz;Leerfeld;KOST-Datum;SEPA-Mandatsreferenz;Skontosperre;Gesellschaftername;Beteiligtennummer;Identifikationsnummer;Zeichnernummer;Postensperre bis;Bezeichnung;Kennzeichen;Festschreibung;Leistungsdatum;Datum Zuord.;Fälligkeit;Generalumkehr;Steuersatz;Land;Abrechnungsreferent;BVV-Position;EU-Mitgliedstaat u. UStID (Ursprung);EU-Steuersatz (Ursprung);Abw. Skontokonto';

    public function __construct(
        private DatevAccountResolver $datevAccountResolver,
    ) {}

    public function export(iterable $documents, DatevExportOptions $datevExportOptions): string
    {
        $columnCount = substr_count(self::COLUMN_HEADINGS, self::DELIMITER) + 1;

        $rows = [];
        $dates = [];

        foreach ($documents as $document) {
            if (! $document->type->isTaxRelevant()) {
                continue;
            }
            if ($document->issue_date === null) {
                continue;
            }
            /** @var array<int, array<string, mixed>> $groups */
            $groups = $document->tax_breakdown ?? [];
            $booked = false;
            foreach ($groups as $group) {
                $row = $this->bookingRow($document, $group, $columnCount);
                if ($row !== null) {
                    $rows[] = $row;
                    $booked = true;
                }
            }

            // Only widen the batch period with a document that actually booked.
            if ($booked) {
                $dates[] = $document->issue_date;
            }
        }

        [$dateFrom, $dateTo] = $this->dateRange($datevExportOptions, $dates);

        $header = $this->headerRow($datevExportOptions, $dateFrom, $dateTo);

        $content = implode(self::RECORD, [$header, self::COLUMN_HEADINGS, ...$rows]).self::RECORD;

        return $this->toWindows1252($content);
    }

    /**
     * Build one booking row for a VAT group, or null when there is nothing to
     * book (gross = 0): DATEV rejects a zero Umsatz, so such a group is skipped.
     *
     * @param  array<string, mixed>  $group
     */
    private function bookingRow(Document $document, array $group, int $columnCount): ?string
    {
        $category = is_string($group['category'] ?? null) ? $group['category'] : '';
        $rate = is_string($group['rate'] ?? null) ? $group['rate'] : '0';
        $grossMinor = is_int($group['gross'] ?? null) ? $group['gross'] : 0;

        if ($grossMinor === 0) {
            return null;
        }

        $datevAccount = $this->datevAccountResolver->revenueAccount($document, $category, $rate);
        $debtor = $this->datevAccountResolver->debtorAccount($document);

        $fields = array_fill(0, $columnCount, '');
        $fields[0] = $this->amount($grossMinor);                                   // Umsatz (unsigned)
        // The amount is unsigned; the sign lives in the Soll/Haben indicator. A
        // negative group (a Storno's negated amounts) credits the debtor ("H"),
        // a positive one debits it ("S"). Deriving this from the sign — not the
        // document type — keeps a self-billing Gutschrift (a genuine, positive
        // supply, EN 16931 type 389) on the "S" side where it belongs.
        $fields[1] = $this->quote($grossMinor < 0 ? 'H' : 'S');                    // Soll/Haben
        $fields[2] = $this->quote((string) $document->currency);                   // WKZ Umsatz
        $fields[6] = (string) $debtor;                                             // Konto
        $fields[7] = (string) $datevAccount->account;                                   // Gegenkonto
        $fields[8] = $datevAccount->buSchluessel !== null ? $this->quote($datevAccount->buSchluessel) : '';
        $fields[9] = $document->issue_date instanceof DateTimeInterface ? $document->issue_date->format('dm') : '';
        $fields[10] = $this->quote($this->belegfeld((string) $document->number, 36)); // Belegfeld 1
        $fields[13] = $this->quote($this->text($this->buchungstext($document), 60)); // Buchungstext

        return implode(self::DELIMITER, $fields);
    }

    private function headerRow(DatevExportOptions $datevExportOptions, DateTimeInterface $dateFrom, DateTimeInterface $dateTo): string
    {
        $createdAt = $datevExportOptions->createdAt ?? new DateTimeImmutable;

        $fields = array_fill(0, 31, '');
        $fields[0] = $this->quote('EXTF');
        $fields[1] = self::VERSIONSNUMMER;
        $fields[2] = self::FORMATKATEGORIE;
        $fields[3] = $this->quote(self::FORMATNAME);
        $fields[4] = self::FORMATVERSION;
        $fields[5] = $createdAt->format('YmdHis').'000';                           // erzeugt am (ms)
        $fields[7] = $this->optionalQuoted($this->text($datevExportOptions->origin, 2));      // Herkunft
        $fields[8] = $this->optionalQuoted($this->text($datevExportOptions->exportedBy, 25)); // Exportiert von
        $fields[10] = (string) $datevExportOptions->berater;
        $fields[11] = (string) $datevExportOptions->mandant;
        $fields[12] = $datevExportOptions->fiscalYearStart->format('Ymd');                    // WJ-Beginn
        $fields[13] = (string) $datevExportOptions->accountLength;                            // Sachkontenlänge
        $fields[14] = $dateFrom->format('Ymd');                                    // Datum von
        $fields[15] = $dateTo->format('Ymd');                                      // Datum bis
        $fields[16] = $this->optionalQuoted($this->text($datevExportOptions->description, 30)); // Bezeichnung
        $fields[18] = self::BUCHUNGSTYP;
        $fields[20] = $datevExportOptions->locked ? '1' : '0';                                // Festschreibung
        $fields[21] = $this->quote($datevExportOptions->currency);                            // WKZ
        $fields[26] = $this->optionalQuoted($this->text($datevExportOptions->chartOfAccounts, 4)); // SKR

        return implode(self::DELIMITER, $fields);
    }

    /**
     * @param  list<DateTimeInterface>  $dates
     * @return array{DateTimeInterface, DateTimeInterface}
     */
    private function dateRange(DatevExportOptions $datevExportOptions, array $dates): array
    {
        if ($datevExportOptions->dateFrom instanceof DateTimeInterface && $datevExportOptions->dateTo instanceof DateTimeInterface) {
            return [$datevExportOptions->dateFrom, $datevExportOptions->dateTo];
        }

        $sorted = $dates;
        usort($sorted, static fn (DateTimeInterface $a, DateTimeInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        $min = $sorted[0] ?? $datevExportOptions->fiscalYearStart;
        $max = $sorted === [] ? $datevExportOptions->fiscalYearStart : $sorted[count($sorted) - 1];

        return [$datevExportOptions->dateFrom ?? $min, $datevExportOptions->dateTo ?? $max];
    }

    private function buchungstext(Document $document): string
    {
        $buyer = $document->buyer ?? [];
        $name = is_array($buyer) && is_string($buyer['name'] ?? null) ? $buyer['name'] : '';

        return $name !== '' ? $name : (string) $document->number;
    }

    private function amount(int $minor): string
    {
        return str_replace('.', ',', bcdiv((string) abs($minor), '100', 2));
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function optionalQuoted(string $value): string
    {
        return $value === '' ? '' : $this->quote($value);
    }

    /**
     * Belegfeld 1/2 accept only [A-Za-z0-9 $ & % * + - /]; strip the rest and cap.
     */
    private function belegfeld(string $value, int $max): string
    {
        $clean = preg_replace('/[^A-Za-z0-9$&%*+\-\/]/', '', $value) ?? '';

        return mb_substr($clean, 0, $max);
    }

    /**
     * Free text: drop the delimiter, quote char and line breaks, then cap.
     */
    private function text(string $value, int $max): string
    {
        $clean = str_replace([';', '"', "\r", "\n", "\t"], ['', '', ' ', ' ', ' '], $value);

        return mb_substr($clean, 0, $max);
    }

    private function toWindows1252(string $content): string
    {
        return mb_convert_encoding($content, 'Windows-1252', 'UTF-8');
    }
}
