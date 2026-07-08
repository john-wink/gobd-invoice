<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\Models\NumberSequence;

return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent models
    |--------------------------------------------------------------------------
    |
    | The package binds its contracts to these classes. Host apps may extend
    | or replace any model (the spatie/laravel-permission pattern). See
    | docs/research/08-package-architecture.md (B3).
    |
    */
    'models' => [
        'document' => Document::class,
        'document_line' => DocumentLine::class,
        'sequence' => NumberSequence::class,
        'audit_entry' => AuditLogEntry::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'documents' => 'gobd_documents',
        'lines' => 'gobd_document_lines',
        'sequences' => 'gobd_number_sequences',
        'audit_log' => 'gobd_audit_log',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | ISO-4217 code. Money is always stored as integer minor units (e.g. cents),
    | never as a float. See docs/research/06-money-tax-and-rounding.md.
    |
    */
    'currency' => 'EUR',

    /*
    |--------------------------------------------------------------------------
    | VAT (Umsatzsteuer)
    |--------------------------------------------------------------------------
    |
    | Rates are exact decimal strings. VAT is computed per (category, rate)
    | group, rounded once per group (EN 16931 has zero rounding tolerance).
    | The rate table MUST be keyed by service category + effective date in a
    | later milestone (e.g. Gastronomie moved 19% -> 7% on 2026-01-01).
    | See docs/research/02 and docs/research/06.
    |
    */
    'tax' => [
        'standard_rate' => '19.0',
        'reduced_rate' => '7.0',
        'kleinunternehmer' => false,
        // §19 UStG Kleinunternehmer turnover limits (net Gesamtumsatz), decimal
        // strings. Defaults are the reform values in force since 2025-01-01:
        // prior calendar year not over €25,000 AND current year not over
        // €100,000; exceeding the current-year limit ends the exemption at once.
        'kleinunternehmer_limits' => [
            'prior_year' => '25000.00',
            'current_year' => '100000.00',
        ],
        // Rounding method: 'vertical' (sum rounded line amounts) is the default.
        'rounding' => 'vertical',

        // Effective-date §12 UStG rate table (Leistungszeitpunkt-driven). Each
        // entry sets the standard/reduced rate in force from its ISO `from` date
        // until the next entry; the flat rates above are the fallback when no
        // period covers a date. Ship ONLY rates verified against a primary
        // source. Example of a historical period (do NOT enable without citing
        // the law): the temporary reduction 2020-07-01…2020-12-31 was
        // ['from' => '2020-07-01', 'standard' => '16.0', 'reduced' => '5.0']
        // (Zweites Corona-Steuerhilfegesetz), reverting to 19/7 on 2021-01-01.
        'rate_periods' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document numbering
    |--------------------------------------------------------------------------
    |
    | §14 Abs. 4 Nr. 4 UStG requires a UNIQUE, systematic invoice number; strict
    | gaplessness is advisable but not legally mandated. The number is assigned
    | only at finalization, so a discarded draft never consumes one.
    |
    | 'strategy':
    |   'gapless' (default) — allocated inside the finalize transaction, so a
    |                         failed finalize rolls the number back. Serializes
    |                         finalizations of the same (type, series, year).
    |   'fast'              — gap-tolerant, high throughput: the number is
    |                         committed up front in a short lock, so a failed
    |                         finalize leaves an explicable gap. Scale further by
    |                         using one series per tenant (e.g. "rechnung-{tenant}").
    |
    | The counter always resets per calendar year (year is part of its key).
    | See docs/research/02 and docs/research/08 (B8).
    |
    */
    'numbering' => [
        'strategy' => 'gapless', // gapless | fast
        'format' => '{type}-{year}-{seq:5}',
    ],

    /*
    |--------------------------------------------------------------------------
    | E-invoicing (E-Rechnung)
    |--------------------------------------------------------------------------
    |
    | Mandatory B2B e-invoicing phases in: receive since 2025-01-01, issue for
    | >800k EUR turnover from 2027-01-01, all B2B from 2028-01-01. ZUGFeRD
    | MINIMUM and BASIC-WL are NOT valid e-invoices. Default to the EN 16931
    | profile. The concrete schema version is configurable.
    | See docs/research/03-e-invoicing.md.
    |
    */
    'einvoice' => [
        'default_format' => 'zugferd', // zugferd | facturx | xrechnung
        'zugferd_profile' => 'en16931',
        // Current as of 2026-06: ZUGFeRD 2.4 / Factur-X 1.08 (in force 2026-01-15).
        'zugferd_version' => '2.4',
        'xrechnung_version' => '3.0.2',
        'validate_on_finalize' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF rendering
    |--------------------------------------------------------------------------
    */
    'pdf' => [
        'renderer' => 'dompdf', // dompdf | gotenberg | typst
        'pdf_a_level' => '3b',
        'theme' => 'default',
        'embedded_xml_filename' => 'factur-x.xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (Aufbewahrung) — §147 AO / §257 HGB / §14b UStG
    |--------------------------------------------------------------------------
    |
    | Buchungsbelege/invoices: 8 years (BEG IV, from 2025-01-01); books,
    | journals, customs, Verfahrensdokumentation: 10 years; correspondence:
    | 6 years. The clock starts at the END of the calendar year (§147 Abs. 4
    | AO). Financial-sector tenants (BaFin-supervised) keep 10 years
    | PERMANENTLY (SchwarzArbMoDiG reversal) — set `financial_sector` true.
    | Never auto-delete; a deletion is blocked while an Ablaufhemmung is active
    | (§147 Abs. 3 S. 5 AO). See docs/research/04-retention-and-audit-access.md.
    |
    */
    'retention' => [
        'voucher_years' => 8,
        'documentation_years' => 10,
        'correspondence_years' => 6,
        'financial_sector' => false,
        'auto_delete' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | The audit log is append-only (insert-only). Hash-chaining each entry to
    | the previous one yields a tamper-evident chain (a strong recommended GoBD
    | control). See docs/research/01-gobd-compliance.md.
    |
    */
    'audit' => [
        'enabled' => true,
        'hash_chain' => true,
        'hash_algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content validation (§14 UStG Pflichtangaben)
    |--------------------------------------------------------------------------
    |
    | When true, finalize() is fail-closed: a tax-relevant document missing a
    | §14 Abs. 4 mandatory field (parties, supplier tax id, line description,
    | time of supply) throws instead of being festgeschrieben. Disable only for
    | a controlled migration of legacy data. See docs/research/02.
    |
    */
    'content_validation' => true,

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'de',
];
