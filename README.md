# gobd-invoice

<!-- BADGES (enable once the repo is public on Packagist) -->
<!--
[![Latest Version on Packagist](https://img.shields.io/packagist/v/john-wink/gobd-invoice.svg?style=flat-square)](https://packagist.org/packages/john-wink/gobd-invoice)
[![run-tests](https://img.shields.io/github/actions/workflow/status/john-wink/gobd-invoice/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/john-wink/gobd-invoice/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/john-wink/gobd-invoice/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/john-wink/gobd-invoice/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/john-wink/gobd-invoice.svg?style=flat-square)](https://packagist.org/packages/john-wink/gobd-invoice)
-->

**A GoBD-compliant German business-document engine for Laravel.** Generate and
manage invoices (Rechnung), quotes (Angebot), cost estimates (Kostenvoranschlag),
progress/final invoices (Abschlags-/Schlussrechnung), cancellations (Storno),
credit notes (Gutschrift), proof-of-performance (Leistungsnachweis), partial
payments (Teilzahlung) and dunning (Mahnung) — with immutable finalization,
a tamper-evident audit trail, race-safe sequential numbering and (on the
roadmap) EN 16931 e-invoicing (XRechnung / ZUGFeRD / Factur-X).

This is a **framework-only** backend package — no Filament, no UI. It is the
compliance and document engine your application builds a UI on top of.

> [!IMPORTANT]
> **This package is a tool, not legal or tax advice, and it is not "GoBD-certified".**
> GoBD conformance is always a property of the *deployed system and its
> Verfahrensdokumentation*, audited at the operator (e.g. via an IDW PS 880
> Testat) — not of a library in isolation. gobd-invoice is built to be
> **GoBD-ready / Testat-fähig**: it implements the technical controls (immutability,
> audit trail, numbering, retention) that such an audit checks for. Verify your
> concrete setup with your tax advisor. Every legal claim in this repository is
> sourced in [`docs/research/`](docs/research) and pinned to a date.

## Requirements

- PHP **8.4** or **8.5**
- Laravel **13**
- `ext-bcmath` (exact monetary math)

## Installation

```bash
composer require john-wink/gobd-invoice
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="gobd-invoice-migrations"
php artisan migrate
```

Optionally publish the config and translations:

```bash
php artisan vendor:publish --tag="gobd-invoice-config"
php artisan vendor:publish --tag="gobd-invoice-translations"
```

## Usage

```php
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;

// 1. Draft (editable, no number consumed yet)
$invoice = GobdInvoice::draft(DocumentType::Rechnung, [
    'currency'     => 'EUR',
    'issue_date'   => '2026-06-25',
    'service_date' => '2026-06-20',
], [
    ['description' => 'Beratung',  'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ['description' => 'Material',  'quantity' => '1', 'unit_price' => '50.00',  'tax_rate' => '7.0'],
]);

// 2. Finalize (Festschreibung): assigns the number, computes the VAT breakdown,
//    snapshots + hashes the content, sets the retention window, writes the audit
//    entry. After this the tax-relevant content is immutable.
$invoice = GobdInvoice::finalize($invoice);

$invoice->number;        // e.g. "rechnung-2026-00001"
$invoice->gross_total;   // 26450  (integer minor units = 264.50 EUR)

// 3. Verify integrity at any time (re-hash the snapshot)
GobdInvoice::verify($invoice);   // true

// 4. Cancel correctly — never delete. Issues a linked Storno with negated
//    amounts and moves the original to the Cancelled status.
$storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

// 5. Export the finalized invoice as an EN 16931 e-invoice (ZUGFeRD/Factur-X or
//    XRechnung CII XML, per the configured einvoice.default_format / profile).
$xml = GobdInvoice::eInvoiceXml($invoice);
```

Mutating a finalized document throws — immutability is enforced at the model level:

```php
$invoice->gross_total = 1;
$invoice->save(); // throws DocumentIsImmutableException (GoBD Unveränderbarkeit)
```

## What's implemented vs. on the roadmap

| Capability | Status |
|---|---|
| Document taxonomy & lifecycle state machine | ✅ M1 |
| Money value object (integer minor units, commercial rounding) | ✅ M1 |
| VAT breakdown rounded per (category, rate) group | ✅ M2 (core) |
| Net/gross price modes, allowances & charges, full BT-106→BT-115 chain | ✅ M2 (engine) |
| Effective-date §12 rate table (`TaxRateResolver`, Leistungszeitpunkt) | ✅ M2 (engine) |
| §19 Kleinunternehmer rule (€25k/€100k limits, mid-year Fallbeil) | ✅ M2 (engine) |
| Multi-currency VAT in EUR accounting currency (BT-111) | ✅ M2 (engine) |
| Race-safe gapless sequential numbering | ✅ M1 |
| Finalization: full EN 16931 chain persisted, snapshot + SHA-256 content hash | ✅ M3 |
| §14 mandatory-content validation (fail-closed; §33 Kleinbetrag relaxation) | ✅ M3 |
| Schlussrechnung double-VAT gate — advance net + VAT deduction (§14 Abs. 5) | ✅ M3 |
| Immutability guard + insert-only, hash-chained audit log | ✅ M3 (core) |
| Storno / cancellation (Storno statt Löschen) | ✅ M3 (core) |
| Document conversion (Angebot/Leistungsnachweis → invoice, source-linked) | ✅ M3 |
| Retention window (`retention_until`, 8y/10y) | ✅ M1 |
| Swappable models, config, events, driver managers | ✅ M0/M1 |
| PDF/A-3 rendering (dompdf / Gotenberg / Typst) | 🚧 M4 |
| E-invoicing: EN 16931 CII export (ZUGFeRD / Factur-X / XRechnung) | ✅ M5 (CII export) |
| E-invoicing: KoSIT validation, XRechnung UBL, PDF/A-3 embed, receive/parse | 🚧 M5 |
| Z1/Z2/Z3 (GDPdU) export, DATEV export, IKS hooks | 🚧 M6 |

See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the full 8-month plan to a
Laracon-ready 1.0.

## Quality gates

```bash
composer test            # Pest
composer test:parallel   # Pest in parallel
composer analyse         # PHPStan (level max) + Larastan
composer rector:check    # Rector dry-run
composer lint            # Pint --test
composer test:types      # 100% type coverage
composer qa              # all of the above
```

## Documentation & research

All compliance and design decisions are backed by verified, sourced research
notes in [`docs/research/`](docs/research) — GoBD, UStG invoice content,
mandatory e-invoicing, retention & audit access, the document taxonomy,
money/VAT/rounding, a competitor analysis, the package architecture and the
tooling. Start at [`AGENTS.md`](AGENTS.md) if you are contributing.

## Testing

```bash
composer install
composer test:parallel
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Any change touching a legal rule must
cite an authoritative primary source.

## Security

See [SECURITY.md](.github/SECURITY.md). Please report vulnerabilities privately.

## Credits

- [John Wink](https://github.com/john-wink)
- Inspired by the ambition of projects like [Rechno](https://oliver.software/projects/rechno).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
