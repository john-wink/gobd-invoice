# Start here — gobd-invoice contributor & agent guide

This file orients any engineer (human or AI agent) picking up `john-wink/gobd-invoice`.
Read it fully before changing code. It is the map; [`docs/research/`](docs/research)
is the territory.

## What this package is

A **framework-only** (no Filament, no UI) Laravel 13 / PHP 8.4+ engine for
**GoBD-compliant German business documents** and **EN 16931 e-invoicing**. The
goal is a flawless, Paragon-quality open-source 1.0 to present at **Laracon
(~8 months out)**. Token/effort cost is not the constraint — correctness,
legal accuracy, and code quality are.

## Golden rules

1. **A wrong legal fact is worse than a missing one.** Every legal rule (a field,
   deadline, rate, threshold, retention period, numbering or rounding rule) must
   cite an authoritative primary source (gesetze-im-internet.de, bundesfinanz-
   ministerium.de, KoSIT) and be reflected in `docs/research/`. German tax law
   changed materially in 2024–2026 — do not trust memory; re-verify dates.
2. **Never claim "GoBD-certified".** Position as **GoBD-ready / Testat-fähig**.
   Compliance is a property of the deployed system + Verfahrensdokumentation,
   audited at the operator. This package provides the technical controls.
3. **Money is never a float.** Integer minor units + BCMath; round VAT once per
   (category, rate) group (EN 16931 has zero rounding tolerance).
4. **Finalized = immutable.** Correct via a linked Storno + new document, never
   by editing or deleting. The audit log is insert-only.
5. **Quality gates are not optional.** `composer qa` must be green: PHPStan level
   `max` (baseline-free), Rector dry-run, Pint, Pest, 100% type coverage. Run
   tests with `--parallel`.

## The research (read before coding in a domain)

Verified, adversarially fact-checked notes live in [`docs/research/`](docs/research):

| File | Domain |
|---|---|
| `00-SYNTHESIS.md` | Prioritized compliance checklist, conflicts, gaps, architecture & roadmap |
| `01-gobd-compliance.md` | GoBD principles → technical requirements checklist |
| `02-legal-invoice-content.md` | §14 UStG mandatory fields, Kleinunternehmer, numbering |
| `03-e-invoicing.md` | E-Rechnungspflicht timeline, XRechnung, ZUGFeRD/Factur-X |
| `04-retention-and-audit-access.md` | Aufbewahrung, Z1/Z2/Z3, deletion locks |
| `05-document-types-and-lifecycle.md` | Document taxonomy + state machine |
| `06-money-tax-and-rounding.md` | Money/VAT/rounding algorithm |
| `07-reference-and-competitor-analysis.md` | Rechno + competitor lessons, positioning |
| `08-package-architecture.md` | The architecture this code follows |
| `09-tooling-and-quality-gates.md` | Exact tooling versions & configs |

## Verified facts that are easy to get wrong (do not regress these)

- **E-invoicing dates:** *receive* obligation since **2025-01-01**; *issue* for
  prior-year turnover **> €800,000** from **2027-01-01**; **all** B2B from
  **2028-01-01** (§27 Abs. 38 / §14 UStG, Wachstumschancengesetz).
- **ZUGFeRD/Factur-X version (mid-2026):** current is **2.4 / Factur-X 1.08**
  (in force 2026-01-15); 2.3.x is superseded. **MINIMUM and BASIC-WL are NOT
  valid e-invoices.** XRechnung current bundle **3.0.2**. Keep versions configurable.
- **Retention:** Buchungsbelege/invoices **8 years** (BEG IV); clock starts at
  **year-end** (§147 Abs. 4 AO). **Financial-sector (BaFin-supervised) entities
  keep 10 years PERMANENTLY** (SchwarzArbMoDiG reversal) — *not* "8 years from
  2026". Block deletion while an **Ablaufhemmung** (§147 Abs. 3 S. 5 AO) is active.
- **Tax categories (EN 16931 BT-118 / UNCL5305):** **both 19% and 7% are category
  `S`** — the rate lives in BT-119, not in a separate category. There is **no**
  `AA` reduced code. §19 Kleinunternehmer is category **`E`**. (A duplicate
  backed-enum value is a fatal error — see `Enums\TaxCategory`.)
- **§14 Abs. 4 UStG has Nr. 1–10 only.** The reverse-charge note lives in **§14a
  Abs. 5**, not in the §14 Abs. 4 list.
- **"Gutschrift"** is reserved for self-billing (§14). A correction of your own
  invoice is a **Storno/Rechnungskorrektur**, never a "Gutschrift" (§14c risk).
- **Numbering:** §14 requires a **unique** number; strict gaplessness is a
  defensive default we ship (assign at finalization), not a hard statutory rule.

## Current state of the code (what exists today)

Milestones **M0–M3 (core slices)** are implemented and tested:

```
src/
  GobdInvoiceServiceProvider.php   spatie PackageServiceProvider; driver bindings
  GobdInvoiceManager.php           draft → finalize → verify → cancel
  Facades/GobdInvoice.php
  Contracts/                       InvoiceDocument, NumberSequenceGenerator,
                                   TotalsCalculator, TaxableLine, AuditLogger, PdfRenderer
  Enums/                           DocumentType, DocumentStatus, TaxCategory
  ValueObjects/                    Money, TaxRate, DocumentNumber, TaxBreakdown(+Line)
  Tax/GroupedTotalsCalculator.php  per-(category,rate) rounding
  Numbering/LockingSequenceGenerator.php  race-safe (lockForUpdate)
  Audit/                           ContentHasher, AppendOnlyAuditLogger (hash chain)
  Models/                          Document (+immutability guard), DocumentLine,
                                   NumberSequence, AuditLogEntry (insert-only)
  Events/                          DocumentDrafted, DocumentFinalized, DocumentCancelled
  Exceptions/
config/gobd-invoice.php
database/migrations/*.php.stub     4 anonymous-class migrations
database/factories/DocumentFactory.php
lang/{de,en}/gobd-invoice.php
tests/                             Pest + orchestra/testbench (Arch/Unit/Feature)
```

**Not yet built** (their contracts/config keys exist; see roadmap): the full tax
engine (discounts/Skonto, Kleinunternehmer flip, effective-date rate table),
spatie/laravel-data DTOs, document-type drivers, the e-invoice format drivers
(`EInvoice/`), PDF rendering (`Pdf/`), the finalization pipeline, and the
Z3/GDPdU + DATEV exporters.

## Conventions

- PHP 8.4+, `declare(strict_types=1)` everywhere; full type hints; value objects
  are `final readonly`. Enums are backed and carry behavior.
- Models are swappable via `config('gobd-invoice.models.*')` and contracts (the
  spatie/laravel-permission pattern). Table names come from config.
- Follow existing file structure; check sibling files before adding new ones.
- Conventional Commits; suggested scopes: `gobd`, `rechnung`, `xrechnung`,
  `zugferd`, `storno`, `numbering`, `tax`, `retention`, `pdf`.

## Running the gates

```bash
composer install
composer qa            # lint + rector:check + analyse + test:parallel
composer test:parallel
```

> The numbering concurrency guarantee (`lockForUpdate`) is a no-op on SQLite and
> must be proven against **MySQL/Postgres** in CI before relying on it.

## How to pick up the next milestone

1. Read `docs/ROADMAP.md` and the relevant `docs/research/*` file end-to-end.
2. Resolve any **Open Questions** listed at the bottom of that research file
   (re-verify against primary sources; update the doc with citations).
3. Write tests first (a tax/e-invoice engine demands high mutation coverage).
4. Keep `composer qa` green; update `CHANGELOG.md` and the README status table.

## Open questions to resolve before 1.0 (from the research)

- §19 Kleinunternehmer mid-year "Fallbeil" invoicing mechanics (BMF 18.03.2025).
- Host-app linkage: confirm `morphTo` (`documentable`) vs a configurable FK
  *before* the schema stabilizes.
- PDF/A-3 conformance (3b vs 3a) and whether dompdf suffices without Gotenberg/
  Ghostscript (validate with veraPDF/KoSIT).
- `horstoeko/zugferd` is **CII-only** — UBL-syntax XRechnung needs a separate
  path (CII→UBL bridge or native UBL).
- KoSIT validator packaging (bundled Java validator vs pure-PHP Schematron).
- DATEV (EXTF) export field set; ViDA reporting hooks.

See each research file's **Open Questions** section for the full list with sources.
