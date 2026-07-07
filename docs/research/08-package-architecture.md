# Laravel 13 Package Architecture

> **Fact-check status (verified 2026-06-25):** Load-bearing legal, version, and API claims below were independently verified against primary sources (gesetze-im-internet.de, BMF, KoSIT/xeinkauf.de, FeRD/FNFE-MPE, the official Laravel 13 docs, and the installed `vendor/` source of `spatie/laravel-package-tools` 1.93.1 and `spatie/laravel-permission` 7.4.2). Corrections from the original draft are called out inline as **[CORRECTED 2026-06-25]** and summarized in *Open Questions*. The most material fix: the ZUGFeRD/Factur-X version moved twice since the draft (2.3.2 → 2.3.3 → **2.4 / Factur-X 1.08, in force 15 Jan 2026**), and the `TaxCategory` enum contained two correctness bugs.

## Purpose and scope

These are the authoritative starting notes for `john-wink/gobd-invoice`: a **framework-only** (no Filament, no Livewire) backend engine that generates and manages German business documents (Rechnung, Angebot, Kostenvoranschlag, Beleg, Leistungsnachweis, Teilzahlung/Abschlags-/Schlussrechnung, Storno, Gutschrift, Mahnung) in a **GoBD-compliant (Grundsätze zur ordnungsmäßigen Führung … von Büchern … in elektronischer Form, "principles for proper electronic bookkeeping")** and **e-invoice-capable** way.

Two design pressures shape every decision below: (1) the package must be a good Laravel citizen — publishable, swappable, testable; and (2) it encodes German tax law that changed materially in 2024–2026, so legal facts are pinned to a date/version.

---

## Part A — Legal facts the engine must encode (mid-2026 state)

> A wrong legal fact is worse than a missing one. Every fact below carries its applicability date. Cross-checked against primary sources where possible.

### E-invoice mandate timeline (Wachstumschancengesetz, passed by the Bundesrat 22 Mar 2024)

> **[CORRECTED 2026-06-25]** The draft dated the law "23 Mar 2024". The Wachstumschancengesetz was *approved by the Bundesrat on 22 March 2024* and promulgated thereafter. The e-invoice provisions amend §14 UStG. The phased timeline below is confirmed [3][6].

| Date | Obligation | Software requirement |
|---|---|---|
| **1 Jan 2025** | Every domestic business must be able to **receive** an EN 16931 e-invoice; a plain email inbox suffices, and the sender no longer needs the recipient's explicit consent to send a structured e-invoice [3][6] | Engine must **parse/ingest** XRechnung (UBL+CII) and ZUGFeRD; "receive" is a must-have from day one |
| **1 Jan 2025 – 31 Dec 2026** | Transition: paper / PDF / EDI still allowed **with recipient consent** [3][6] | Issuing structured e-invoices is optional but should be supported |
| **1 Jan 2027** | Businesses with prior-year turnover **> €800,000** must **issue** only structured EN 16931 e-invoices for domestic B2B [3][6] | Issuing must be production-ready; engine should track issuer turnover to flag the obligation |
| **1 Jan 2028** | Issuing obligation extends to **all** businesses regardless of size [3][6] | — |

Scope is **domestic B2B only**; B2C and cross-border EU are out of the current mandate [3]. The `elektronische Rechnung` (e-invoice) is legally defined in **§14 UStG** as a Rechnung "die in einem strukturierten elektronischen Format ausgestellt, übermittelt und empfangen wird und eine elektronische Verarbeitung ermöglicht" — i.e. issued, transmitted and received in a structured electronic format that enables electronic processing — where the format either **conforms to the European standard EN 16931 per Directive 2014/55/EU** or is mutually agreed while remaining extractable into an EN-16931-interoperable format; everything else (paper, PDF-by-email) is a `sonstige Rechnung` ("a Rechnung transmitted in another electronic format or on paper") [4].

**Kleinbetragsrechnung exemption:** invoices with a gross total **≤ €250** (§33 UStDV) and Fahrausweise (transit tickets) are **exempt** from the e-invoice obligation and may stay paper/PDF [9]. The engine must let a document opt out of structured-format generation when it qualifies.

### Mandatory invoice content — §14 Abs. 4 UStG [4]

Full invoices require: supplier + recipient name/address; supplier's **Steuernummer or USt-IdNr.** (VAT ID); issue date; a **sequential, unique invoice number**; quantity/description of goods/services; supply/performance date (or payment timing for advance payments); consideration broken down per tax rate + any exemptions; the applicable **tax rate and tax amount** (or an exemption note); a `Aufbewahrungspflicht` retention note where §14b applies; plus the `Gutschrift` designation (where the recipient issues the invoice) and reverse-charge / recipient-retention notes where applicable [4]. Kleinbetragsrechnungen (≤ €250) carry a **reduced** set of exactly five elements (§33 UStDV): full name + address of the issuer; issue date; quantity/type of goods or scope of service; total consideration **with the tax amount in a single sum**; and the applicable tax rate (or exemption note) [9].

### Kleinunternehmer reform (Jahressteuergesetz 2024, in force 1 Jan 2025) — §19 UStG [confirmed against gesetze-im-internet and the BMF letter of 18 Mar 2025]

- Thresholds raised to **€25,000** (prior calendar year) and **€100,000** (current calendar year) [2][5].
- **Structural change:** Kleinunternehmer turnover is now **steuerfrei (tax-exempt)**, not merely "tax not levied / nicht erhoben" as before 2025 [2][5]. Invoices must show **no Umsatzsteuer** and carry a Kleinunternehmer note (referencing §19 UStG).
- **Fallbeil ("guillotine") effect:** the moment turnover **exceeds the €100,000 upper limit in the current year, the very transaction that crosses the line is already fully subject to Regelbesteuerung (standard taxation)** — the switch is **immediate and mid-year**, not from the following year [2][5]. Turnover *up to* the crossing stays exempt; the crossing transaction and everything after it is taxed. Additionally, if the **prior-year** turnover exceeded €25,000, the current year is taxed from its first euro even if the current year stays under €25,000 [2][5]. The engine's tax strategy must be able to flip per-document based on a running YTD total. **[ANNOTATION 2026-06-25]** The operational detail (how to invoice the crossing transaction and whether a partial split is required) is set out in the **BMF letter of 18 Mar 2025 ("Sonderregelung für Kleinunternehmer", Umsatzsteuer-Anwendungserlass)** — see *Open Questions*.
- Kleinunternehmer remain on the e-invoice **receiving** obligation; they may issue `sonstige Rechnungen` during transition.

### Corrections, cancellation, dunning

- **Storno / Rechnungskorrektur:** never label a correction of *your own* invoice "Gutschrift" — that risks an `unberechtigter Steuerausweis` (unauthorized tax statement) under **§14c UStG** [10]. A Storno fully reverses (negative amounts); it must reference the original invoice number + date and itself meet §14 Abs. 4 [10]. "Gutschrift" is reserved for the self-billing sense (recipient issues the invoice) or a genuine credit note.
- **Mahnung (dunning):** dunning is a business process, not a tax document; default/Verzug rules live in §286/§288 BGB. The engine should model dunning levels (Zahlungserinnerung, 1./2. Mahnung) and Verzugszinsen but not embed them in the immutable tax record.

### GoBD (BMF letter of 11 Mar 2024, in force since 1 Apr 2024) [7][8]

Core principles the engine must satisfy: **Nachvollziehbarkeit/Nachprüfbarkeit** (traceability), **Vollständigkeit** (completeness), **Richtigkeit** (correctness), **zeitgerechte Buchung** (timely recording), **Ordnung**, and **Unveränderbarkeit (immutability)** — once booked, an entry must not be altered undetectably; changes must be logged transparently [7][8]. Records must be **maschinell auswertbar** (machine-evaluable) and producible for Z1/Z2/**Z3 Datenüberlassung** — the 2024 update **renamed "Datenträgerüberlassung" → "Datenüberlassung"**, de-coupling Z3 access from a physical data medium so data may be supplied via a data-exchange platform [7]. Confirmed against BDO's analysis of the BMF letter [7].

### Retention periods (BEG IV — Viertes Bürokratieentlastungsgesetz)

- **Buchungsbelege (accounting vouchers, incl. invoices): 8 years** (reduced from 10 by BEG IV, which the Bundesrat approved 18 Oct 2024), counted from the **end of the calendar year** the invoice was issued [1]. The shorter period applies to records whose former 10-year period had **not yet expired on 1 Jan 2025**. Example: invoice 27 Sep 2024 → period runs to 31 Dec 2032 [1]. (Note: credit/finance institutions get the change one year later.)
- **Verfahrensdokumentation** (procedural documentation) and most other books/records (Handelsbücher, Inventare, Bilanzen): **10 years** [1]. The engine should expose a per-document retention class and a computed `retain_until` date.

---

## Part B — Package architecture

### B1. Service provider: spatie/laravel-package-tools

Confirmed from the installed source (`vendor/spatie/laravel-package-tools` v1.93.1): extend `PackageServiceProvider`, implement `configurePackage(Package $package)`. The fluent `Package` builder offers `->name()`, `->hasConfigFile()`, `->hasMigrations()`, `->hasMigration()`, `->hasTranslations()`, `->hasViews(?string $namespace = null)`, `->hasCommands()`, `->hasInstallCommand()`, and the `InstallCommand` exposes `->publishConfigFile()`, `->publishMigrations()`, `->askToStarRepoOnGitHub()`, `->startWith()`, `->endWith()`, `->copyAndRegisterServiceProviderInApp()` — **all verified present in the installed source.** `register()` runs `configurePackage()` then `registerPackageConfigs()` (config merged early) and finally calls the `packageRegistered()` hook; `boot()` runs the publish/load chain (`bootPackageMigrations`, `bootPackageViews`, `bootPackageTranslations`, …) then `packageBooted()`. Migrations support `runsMigrations()` (auto-load) vs publish-only, and `discoversMigrations(bool, string $path = '/database/migrations')` to auto-discover a directory.

```php
namespace JohnWink\GobdInvoice;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GobdInvoiceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('gobd-invoice')
            ->hasConfigFile()                 // config/gobd-invoice.php
            ->hasTranslations()               // lang/{de,en}
            ->hasViews('gobd-invoice')         // PDF Blade themes (namespace arg is supported)
            ->hasMigrations([
                'create_gobd_documents_table',
                'create_gobd_document_lines_table',
                'create_gobd_number_sequences_table',
                'create_gobd_audit_log_table',
            ])
            ->hasCommands([Commands\InstallCommand::class])
            ->hasInstallCommand(fn ($c) => $c->publishConfigFile()->publishMigrations()->askToStarRepoOnGitHub('john-wink/gobd-invoice'));
    }

    public function packageRegistered(): void
    {
        // driver managers as singletons (see B5)
        $this->app->singleton(DocumentTypeManager::class);
        $this->app->singleton(EInvoiceFormatManager::class);
        $this->app->singleton(PdfRendererManager::class);
        $this->app->singleton(Contracts\NumberSequenceGenerator::class, NumberSequence\LockingSequenceGenerator::class);

        // model binding so host apps can swap models (the laravel-permission pattern, verified)
        $this->app->bind(Contracts\InvoiceDocument::class, fn ($app) => $app->make(config('gobd-invoice.models.document')));
    }
}
```

### B2. Directory / namespace layout

```
src/
  GobdInvoiceServiceProvider.php
  Facades/GobdInvoice.php
  Contracts/            InvoiceDocument, DocumentTypeDriver, EInvoiceFormatDriver,
                        PdfRenderer, NumberSequenceGenerator, TaxStrategy, AuditLogger
  Enums/                DocumentType, DocumentStatus, TaxCategory, EInvoiceFormat,
                        UnitOfMeasure, DunningLevel
  Data/                 (spatie/laravel-data DTOs) InvoiceData, LineItemData,
                        PartyData, TaxBreakdownData, PaymentTermsData
  ValueObjects/         Money, TaxRate, DocumentNumber, VatId, Leitweg
  Models/               Document, DocumentLine, NumberSequence, AuditLogEntry
  Documents/            Drivers/{RechnungDriver, AngebotDriver, StornoDriver, ...}
  EInvoice/             Formats/{XRechnungFormat, ZugferdFormat, FacturXFormat},
                        Mapping/En16931Mapper, Validation/KositValidator
  Pdf/                  Renderers/{DompdfRenderer, GotenbergRenderer, TypstRenderer},
                        PdfA3Merger
  Numbering/            LockingSequenceGenerator
  Audit/                ContentHasher, AppendOnlyAuditLogger
  Pipelines/            FinalizationPipeline, FinalizeStages/...
  Events/               DocumentDrafted, DocumentFinalized, DocumentCancelled,
                        EInvoiceGenerated, DocumentSent, PaymentRecorded
  Exceptions/
config/gobd-invoice.php
database/migrations/   *.php.stub  (anonymous-class migrations)
database/factories/    DocumentFactory.php ...
resources/views/themes/{default,...}/  (Blade PDF templates)
lang/{de,en}/gobd-invoice.php
tests/  (Pest + orchestra/testbench)
```

### B3. Config-driven, swappable models (the laravel-permission pattern)

Confirmed from `vendor/spatie/laravel-permission` v7.4.2: config holds `models.*` and `table_names.*`; the provider binds **contract → configured class** (`$this->app->bind(PermissionContract::class, fn ($app) => $app->make($app->config['permission.models.permission']))`); models read their table name from config in the constructor (`$this->table = Config::permissionsTable() ?: parent::getTable()`). Replicate this exactly so host apps can extend or replace any model:

```php
// config/gobd-invoice.php
return [
    'models' => [
        'document'      => JohnWink\GobdInvoice\Models\Document::class,
        'document_line' => JohnWink\GobdInvoice\Models\DocumentLine::class,
        'sequence'      => JohnWink\GobdInvoice\Models\NumberSequence::class,
        'audit_entry'   => JohnWink\GobdInvoice\Models\AuditLogEntry::class,
    ],
    'table_names' => [
        'documents' => 'gobd_documents',
        'lines'     => 'gobd_document_lines',
        'sequences' => 'gobd_number_sequences',
        'audit_log' => 'gobd_audit_log',
    ],
    'tax' => ['default_rate' => '19.0', 'reduced_rate' => '7.0', 'kleinunternehmer' => false],
    'einvoice' => ['default_format' => 'zugferd', 'zugferd_profile' => 'en16931', 'validate_on_finalize' => true],
    'pdf' => ['renderer' => 'dompdf', 'pdf_a_level' => '3b', 'theme' => 'default'],
    'numbering' => [
        'strategy' => 'gapless',  // gapless | yearly_reset
        'format'   => '{type}-{year}-{seq:5}',
    ],
    'retention' => ['voucher_years' => 8, 'documentation_years' => 10],
];
```

Models implement a contract and let the host app supply a factory. Use the modern resolution: ship a default `database/factories/DocumentFactory.php`, and on the model declare the factory explicitly so a host that re-namespaces still works. Either `#[UseFactory(DocumentFactory::class)]` (the modern, IDE-friendly attribute) or a `newFactory()` override is acceptable — **both are documented in the Laravel 13 docs** [11]; the attribute is the cleaner default in Laravel 13. The attribute lives at `Illuminate\Database\Eloquent\Attributes\UseFactory` (verified against the Laravel 13 factories docs). When a factory is in a non-conventional namespace, the docs also recommend pairing it with `#[UseModel(...)]` (`Illuminate\Database\Eloquent\Factories\Attributes\UseModel`) on the factory.

```php
#[\Illuminate\Database\Eloquent\Attributes\UseFactory(DocumentFactory::class)]
class Document extends Model implements Contracts\InvoiceDocument
{
    use HasFactory;
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('gobd-invoice.table_names.documents', 'gobd_documents');
    }
}
```

### B4. Migrations — ship anonymous-class `.php.stub`

Follow the spatie convention: store migrations as `database/migrations/create_*.php.stub`, register them via `->hasMigrations([...])`, and ship them as **anonymous-class** migrations (`return new class extends Migration {…};`) — the standard Laravel idiom that avoids class-name collisions across publish runs. spatie's `ProcessMigrations` re-stamps each published file with a fresh `Y_m_d_His` timestamp and supports `.stub`. Default to **publish-only** (`runsMigrations` left false) so host apps own their schema and can edit columns/indexes before migrating; the immutability columns (`finalized_at`, `content_hash`, `sequence_id`) must be present.

### B5. Extensibility via Manager/driver pattern

Use Laravel's `Illuminate\Support\Manager` for three independent driver families. Each `createXxxDriver()` resolves a class implementing the matching contract; `extend()` lets host apps register custom drivers.

```php
final class EInvoiceFormatManager extends \Illuminate\Support\Manager
{
    public function getDefaultDriver(): string { return $this->config->get('gobd-invoice.einvoice.default_format'); }
    public function createXrechnungDriver(): Contracts\EInvoiceFormatDriver { return new EInvoice\Formats\XRechnungFormat(/* deps */); }
    public function createZugferdDriver(): Contracts\EInvoiceFormatDriver  { return new EInvoice\Formats\ZugferdFormat(/* deps */); }
    public function createFacturxDriver(): Contracts\EInvoiceFormatDriver  { return new EInvoice\Formats\FacturXFormat(/* deps */); }
}
```

- **Document-type drivers** (`DocumentTypeDriver`): one per `DocumentType` enum case (Rechnung, Angebot, Kostenvoranschlag, Beleg, Leistungsnachweis, Teilzahlung, Abschlagsrechnung, Schlussrechnung, Storno, Gutschrift, Mahnung). Each defines its number series, allowed status transitions, whether it is tax-relevant/immutable, and whether it can be exported as a structured e-invoice (a Mahnung/Angebot cannot).
- **E-invoice format drivers**: XRechnung (pure UBL or CII XML), ZUGFeRD, Factur-X. Recommend wrapping **`horstoeko/zugferd`** — the de-facto PHP standard, actively maintained (v1.0.123 published June 2026) — which reads/writes Minimum, Basic, Basic WL, EN 16931 (Comfort), Extended and XRechnung profiles and ships a **`ZugferdDocumentPdfMerger`** to embed XML into a PDF/A-3 [12]. **[CAVEAT 2026-06-25]** `horstoeko/zugferd` supports **CII syntax only, not UBL** (per its README). ZUGFeRD/Factur-X are always CII, so this is fine for the hybrid path; but a **pure UBL XRechnung** output path needs a separate library or hand-built UBL serialization — do not assume `horstoeko/zugferd` covers UBL. Optionally `horstoeko/zugferdvisualizer` for HTML/PDF rendering. Keep `horstoeko/*` a `suggest`, not a hard `require`, so the core stays slim.
- **PDF-renderer drivers** (`PdfRenderer`): `dompdf` (zero-infra default), `gotenberg` (Chromium/HTTP, best fidelity + native PDF/A), `typst` (fast, typographic). Renderer produces the visual PDF/A-3; the e-invoice driver then merges XML.

Lifecycle **events** fire at every transition (`DocumentDrafted`, `DocumentFinalized`, `DocumentCancelled`, `EInvoiceGenerated`, `DocumentSent`, `PaymentRecorded`, `DunningIssued`). Finalization runs through a **pipeline** (`Illuminate\Pipeline`) with overridable stages: `AssignNumber → ComputeTax → SnapshotPayload → HashContent → PersistAudit → (optional) GenerateEInvoice`. Host apps inject stages via config.

### B6. DTOs and enums

Use **spatie/laravel-data v4** for the transport layer (`InvoiceData`, `LineItemData`, `PartyData`, `TaxBreakdownData`). Current version is **4.19.0** (released 2026-01-19), Laravel 13 compatible [13]. Benefits: validation, `from()`/`toArray()`, and optional **TypeScript transformer** output for frontends [13][14]. DTOs are the public input boundary; they are mapped to the EN 16931 semantic model (Business Terms BT-xx) by `En16931Mapper`. **[CORRECTED 2026-06-25]** The draft said to "use the lighter `Dto` base where validation is all that's needed" — there is **no separate `Dto` base class** in laravel-data v4; all data objects extend `Spatie\LaravelData\Data`. Use `Data` and rely on validation attributes/lazy properties to keep objects lean [13].

PHP 8.1+ **backed enums** drive type safety:

```php
enum DocumentType: string {
    case Rechnung = 'rechnung'; case Angebot = 'angebot'; case Kostenvoranschlag = 'kostenvoranschlag';
    case Beleg = 'beleg'; case Leistungsnachweis = 'leistungsnachweis'; case Teilzahlung = 'teilzahlung';
    case Abschlagsrechnung = 'abschlagsrechnung'; case Schlussrechnung = 'schlussrechnung';
    case Storno = 'storno'; case Gutschrift = 'gutschrift'; case Mahnung = 'mahnung';
    public function isTaxRelevant(): bool { /* Rechnung/Storno/… true; Angebot/Mahnung false */ }
    public function isImmutableOnFinalize(): bool { return $this->isTaxRelevant(); }
}
enum DocumentStatus: string { case Draft='draft'; case Finalized='finalized'; case Sent='sent'; case Paid='paid'; case Cancelled='cancelled'; }

// [CORRECTED 2026-06-25] TaxCategory now carries VALID, UNIQUE UNCL5305 (UNTDID 5305)
// codes used by EN 16931 BT-118. The draft had two bugs:
//   1. Reduced => 'AA' is NOT a valid BT-118/UNCL5305 VAT category code. In Germany BOTH
//      the 19% standard and the 7% reduced rate use category code 'S'; the actual percentage
//      is carried separately in BT-119 (VAT category rate). There is no distinct "reduced" code.
//   2. Exempt and Kleinunternehmer both backed by 'E' => duplicate backed-enum values are a
//      PHP fatal error. They are merged: a Kleinunternehmer invoice IS category 'E' (Exempt).
enum TaxCategory: string {
    case Standard     = 'S';   // standard rate (19%) AND reduced rate (7%); rate goes in BT-119
    case ZeroRated    = 'Z';   // zero rated goods
    case Exempt       = 'E';   // exempt from tax — also used for §19 Kleinunternehmer
    case ReverseCharge= 'AE';  // VAT reverse charge
    case IntraCommunity = 'K'; // VAT exempt for EEA intra-community supply of goods/services
    case Export       = 'G';   // free export item, tax not charged
    case OutOfScope   = 'O';   // services outside scope of tax
} // codes are the UNCL5305 subset used by EN 16931 BT-118
```

> The `TaxCategory` for a 7% line is still `Standard` ('S'); distinguish standard vs reduced by the `TaxRate` value object (B7), **not** by a separate category code. A `isKleinunternehmer()` helper on `TaxStrategy` should select `Exempt` ('E') and attach the §19 note.

### B7. Value objects

- **`Money`** — integer minor units + ISO-4217 currency; never float. Arithmetic returns new instances; rounding mode pinned (`ROUND_HALF_UP`, kaufmännische Rundung). All amounts on lines and totals are `Money`.
- **`TaxRate`** — exact decimal (e.g. `19.0`, `7.0`, `0.0`) plus a `TaxCategory`; knows its UNCL5305 category code for EN 16931 BT-118 and supplies the percentage to BT-119. Apply via `Money::taxOf(TaxRate)`.
- **`DocumentNumber`** — immutable, parsed from the configured format (`{type}-{year}-{seq}`); carries series, year, sequence; `__toString()` is the canonical printed number.
- **`VatId` / `Leitweg`** — validated identifiers (USt-IdNr. checksum; Leitweg-ID format for B2G routing, mandatory **BT-10 Buyer Reference** for public-administration recipients [15]).

### B8. Race-safe numbering service

German law requires a **lückenlos fortlaufende, eindeutige** invoice number (§14 Abs. 4) [4]. Implement a dedicated `gobd_number_sequences` table keyed by `(document_type, series, year)` with a `current_value` column. The generator runs inside `DB::transaction`, does a `lockForUpdate()` SELECT on the sequence row (pessimistic exclusive lock), increments, persists, and returns — making concurrent duplicate numbers impossible by design [16]. This is the documented, portable approach.

```php
interface NumberSequenceGenerator {
    public function next(DocumentType $type, string $series, int $year): DocumentNumber;
}

final class LockingSequenceGenerator implements NumberSequenceGenerator {
    public function next(DocumentType $type, string $series, int $year): DocumentNumber {
        return DB::transaction(function () use ($type, $series, $year) {
            $seq = NumberSequence::query()
                ->where(['document_type' => $type->value, 'series' => $series, 'year' => $year])
                ->lockForUpdate()
                ->firstOrCreate([...], ['current_value' => 0]);
            $seq->increment('current_value');
            return DocumentNumber::fromParts($type, $series, $year, $seq->current_value);
        });
    }
}
```

> **[CAVEAT 2026-06-25] Driver behaviour of `lockForUpdate()`:** On **MySQL/MariaDB and PostgreSQL** this emits `SELECT … FOR UPDATE` and gives a true row lock — the intended behaviour. On **SQLite** `lockForUpdate()` is effectively a **no-op clause**; SQLite has no row-level `FOR UPDATE`, but its whole-database write serialization (writes are exclusive) means concurrent duplicate numbers still cannot occur — so the design is safe on SQLite, just by a different mechanism. The concurrency *test* (B12) must therefore exercise MySQL/Postgres to prove the lock, not only the in-memory SQLite suite. The draft's "works on MySQL/Postgres/SQLite" is true for correctness but glosses over this difference.

**Gap policy:** offer two strategies in config — `gapless` (number is assigned **only at finalization**, inside the same transaction that snapshots/hashes, so a discarded draft never consumes a number — the GoBD-safest default) and `yearly_reset` (sequence resets each calendar year). A finalized number is **never** reused; a cancelled document keeps its number and gets a linked Storno with its own number [10]. Document any unavoidable gaps in the Verfahrensdokumentation.

### B9. Immutability / audit implementation

This is the heart of GoBD compliance [7][8]. Pattern: **finalized snapshot + content hash + append-only log**.

1. **Snapshot:** on finalize, serialize the full document + lines into an immutable `payload` JSON column (`finalized_payload`). The Eloquent record's mutable working fields are no longer the source of truth.
2. **Content hash:** compute `sha256` (or sha-512) over a canonical serialization of the snapshot, store as `content_hash` + `finalized_at`. Re-hashing on read detects tampering. A model `saving` guard throws if any tax-relevant column is mutated after `finalized_at` is set (enforce `Unveränderbarkeit`).
3. **Append-only audit log:** `gobd_audit_log` is insert-only (no update/delete) — every transition (draft→finalized, sent, payment, cancellation, correction) appends a row with actor, timestamp, event, before/after diff, and the resulting `content_hash`. Chain each entry's hash to the previous (`previous_hash`) to form a tamper-evident chain. Corrections are **new linked documents** (Storno + new Rechnung), never edits [10].
4. **Retention:** stamp each document with a `retention_class` and computed `retain_until` (issue-year-end + 8 years for vouchers, +10 for Verfahrensdokumentation) [1]. Provide a Z3-style export command producing machine-evaluable data (e.g. CSV/JSON + the structured XML) [7].

### B10. PDF generation + ZUGFeRD merge

`PdfRenderer` drivers produce a **PDF/A-3** visual rendering from a Blade theme. Then the e-invoice driver embeds the EN 16931 XML as an attachment. For ZUGFeRD/Factur-X the embedded file is named **`factur-x.xml`** (the cross-border ZUGFeRD/Factur-X convention) and the PDF must be **PDF/A-3** with XMP metadata declaring the profile [12]. Use `horstoeko/zugferd`'s **`ZugferdDocumentPdfMerger`** to attach XML to an existing PDF and emit a compliant hybrid file [12].

> **[CORRECTED 2026-06-25] Current ZUGFeRD/Factur-X version.** The draft pinned "ZUGFeRD 2.3.2 / Factur-X 1.07.2 (published 2024)" as current — this is **outdated by two releases** as of mid-2026:
> - **ZUGFeRD 2.3.3 / Factur-X 1.07.3** was published May 2025 (legally effective 15 May 2025).
> - **ZUGFeRD 2.4 / Factur-X 1.08** is the current version, **in force since 15 January 2026** (FeRD/FNFE-MPE press release, 4 Dec 2025).
>
> The profile *set* is unchanged across these releases: six profiles — **Minimum, Basic WL, Basic, EN 16931 (Comfort), Extended, and the XRechnung reference profile**. The EN 16931 profile's extracted XML is itself a valid **XRechnung 3.0.2** invoice [12][15]. Default the package to the **EN 16931** profile, but treat the concrete schema/Schematron version as configurable (`einvoice.zugferd_version`) and track `horstoeko/zugferd`'s bundled version (v1.0.123, June 2026, supports the 2.x line and XRechnung 1.x/2.x/3.x).

Pure **XRechnung** (no PDF) is the B2G/structured-only path: UBL or CII syntax, current version **3.0.2** — confirmed as the current published standard, latest bundle **"XRechnung 3.0.2 Winter 2025/26 Bugfix"**, maintained by **KoSIT** as Germany's CIUS of EN 16931 [15]. (One normative release per year is planned going forward, so expect a successor; 3.0.2 was still current at 2026-06.) Validate finalized e-invoices against the **KoSIT validator** rules before marking `EInvoiceGenerated` (config `validate_on_finalize`) [15]. **Note:** for UBL-syntax XRechnung the CII-only `horstoeko/zugferd` is insufficient (see B5 caveat).

### B11. Templating / theming and localization

Ship Blade PDF themes under `resources/views/themes/{name}` registered via `->hasViews('gobd-invoice')`; host apps publish and override. Theme selection is per-document or config default. Localize all strings via `lang/de` and `lang/en` (`->hasTranslations()`), German default. Document-type and status labels resolve through translation keys, not hard-coded German, so an English UI is possible while the legal terms stay correct on the German PDF.

### B12. Testing strategy

Use **orchestra/testbench** to boot a minimal Laravel app around the package, with **Pest 4**. A base `TestCase` registers `GobdInvoiceServiceProvider`, loads the package migrations into an in-memory SQLite DB, and exposes the model factories. Cover: numbering concurrency (parallel transactions must not collide — **simulate on MySQL/Postgres, since `lockForUpdate` is a no-op on SQLite**, see B8), immutability guard (mutating a finalized document throws), hash-chain integrity, tax math edge cases (Kleinunternehmer flip mid-year, reduced rate, reverse charge, Kleinbetrag reduced fields), EN 16931 XML round-trips validated by the KoSIT ruleset, and PDF/A-3 attachment presence. Per the project convention, run the suite in **parallel mode** (`php artisan test --parallel`).

---

## Part C — Public API surface (proposed)

```php
// Facade — JohnWink\GobdInvoice\Facades\GobdInvoice
GobdInvoice::draft(DocumentType::Rechnung, InvoiceData $data): Document;     // create draft
GobdInvoice::finalize(Document $doc): Document;                              // assign number, snapshot, hash, audit
GobdInvoice::cancel(Document $doc, string $reason): Document;                // create linked Storno
GobdInvoice::correct(Document $doc, InvoiceData $changes): Document;         // Storno + new Rechnung
GobdInvoice::eInvoice(Document $doc, ?EInvoiceFormat $fmt = null): EInvoiceResult; // XML/hybrid PDF
GobdInvoice::pdf(Document $doc, ?string $theme = null): string;             // PDF/A-3 bytes
GobdInvoice::verify(Document $doc): bool;                                    // re-hash + chain check
GobdInvoice::format(EInvoiceFormat $f): EInvoiceFormatDriver;               // pick a driver
GobdInvoice::renderer(?string $name = null): PdfRenderer;

// Core contracts
interface DocumentTypeDriver {
    public function type(): DocumentType;
    public function series(): string;
    public function isImmutable(): bool;
    public function canEmitEInvoice(): bool;
    public function allowedTransitions(): array; // DocumentStatus[]
    public function buildInvoiceData(Document $doc): InvoiceData;
}
interface EInvoiceFormatDriver {
    public function format(): EInvoiceFormat;
    public function generate(InvoiceData $data): string;        // XML
    public function embedInPdf(string $xml, string $pdfBytes): string; // PDF/A-3 hybrid
    public function validate(string $xml): ValidationResult;    // KoSIT rules
}
interface PdfRenderer { public function render(Document $doc, string $theme): string; }
interface TaxStrategy { public function categorize(Document $doc): TaxBreakdownData; } // Regelbesteuerung vs Kleinunternehmer flip
interface AuditLogger { public function append(Document $doc, string $event, array $diff): AuditLogEntry; }
```

### Opinionated defaults summary

- E-invoice default format: **ZUGFeRD EN 16931 profile** (hybrid, human + machine), embedded file `factur-x.xml`, PDF/A-3; pin the concrete ZUGFeRD schema version in config (current: **2.4 / Factur-X 1.08**, in force 15 Jan 2026) [12].
- Numbering: **gapless, assigned at finalization** inside the hashing transaction; row lock via `lockForUpdate` (no-op on SQLite, real on MySQL/Postgres) [16].
- Models: **swappable via config + contracts**, `#[UseFactory]` attribute (`Illuminate\Database\Eloquent\Attributes\UseFactory`), table names from config [11].
- Tax categories: use valid UNCL5305 codes; 7% reduced is category **'S'** (rate in BT-119), Kleinunternehmer is **'E'** [15].
- Hard deps: Laravel framework, `spatie/laravel-package-tools` (v1.93.x verified), `spatie/laravel-data` (v4.19.x verified). **Suggest** (not require): `horstoeko/zugferd` (CII only — v1.0.123, June 2026), `barryvdh/laravel-dompdf`, a Gotenberg client — resolved lazily by the relevant driver so the core stays framework-only and lean.

---

## Sources

[1] Bürokratieentlastungsgesetz: Aufbewahrungspflichten verkürzt (8 statt 10 Jahre, BEG IV, Bundesrat 18.10.2024) — Haufe - https://www.haufe.de/finance/buchfuehrung-kontierung/buerokratieentlastungsgesetz-aufbewahrungspflichten-verkuerzt_186_634670.html
[2] Neuregelungen für Kleinunternehmer ab 2025 (NWB) - https://www.nwb.de/rechnungswesen/neuregelungen-fuer-kleinunternehmer-ab-2025
[3] eInvoicing in Germany — European Commission (Digital Building Blocks) - https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108886/eInvoicing+in+Germany
[4] §14 UStG — gesetze-im-internet.de (e-invoice definition, sonstige Rechnung, §14 Abs. 4 Pflichtangaben) - https://www.gesetze-im-internet.de/ustg_1980/__14.html
[5] §19 UStG — gesetze-im-internet.de (Kleinunternehmer thresholds €25,000/€100,000, steuerfrei) - https://www.gesetze-im-internet.de/ustg_1980/__19.html
[6] E-Invoicing Mandate Germany 2025, 2027, 2028: Deadlines (€800k threshold) — ADVISORI / EDICOM - https://www.advisori.de/en/blog/e-invoicing-mandate-germany
[7] Die neuen GoBD gelten seit dem 1. April 2024 — BDO (Z3 Datenüberlassung, Umbenennung) - https://www.bdo.de/de-de/insights/aktuelles/assurance/die-neuen-gobd-gelten-seit-dem-1-april-2024-datenuberlassung-und-neuer-anhang-im-fokus
[8] BMF-Schreiben 11.03.2024 — Änderung der GoBD (PDF) - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/AO-Anwendungserlass/2024-03-11-aenderung-gobd.pdf
[9] §33 UStDV — Kleinbetragsrechnung (≤ €250, e-invoice exemption, five required fields) - https://www.gesetze-im-internet.de/ustdv_1980/__33.html
[10] Rechnungskorrektur / Stornorechnung und §14c UStG (unberechtigter Steuerausweis) — Rechnungswesen-Portal - https://www.rechnungswesen-portal.de/Fachinfo/Grundlagen/rechnungskorrektur-wann-eine-stornorechnung-noetig-ist.html
[11] Eloquent: Factories — Laravel 13.x (HasFactory, UseFactory attribute at Illuminate\Database\Eloquent\Attributes\UseFactory, UseModel, newFactory) - https://laravel.com/docs/13.x/eloquent-factories
[12] horstoeko/zugferd — ZUGFeRD/XRechnung/Factur-X PHP library (CII-only; profiles; ZugferdDocumentPdfMerger; PDF/A-3) - https://github.com/horstoeko/zugferd
[13] spatie/laravel-data v4 — Introduction / base class is `Data` (current v4.19.0, 2026-01-19) - https://spatie.be/docs/laravel-data/v4/introduction
[14] spatie/laravel-data v4 — Transforming to TypeScript - https://spatie.be/docs/laravel-data/v4/advanced-usage/typescript
[15] Standard XRechnung 3.0.2 (Winter 2025/26 bundle) — KoSIT / xeinkauf.de (CIUS, Leitweg-ID, BT-10 Buyer Reference, UBL/CII) - https://xeinkauf.de/xrechnung/
[16] Preventing race conditions with lockForUpdate (gapless sequential numbering) - https://qadrlabs.com/post/preventing-race-conditions-in-laravel-with-lockforupdate
[17] ZUGFeRD 2.3.3 / Factur-X 1.07.3 (effective 15 May 2025) — VATupdate / zeit.io - https://www.vatupdate.com/2025/05/13/updated-factur-x-1-07-3-and-zugferd-2-3-3-e-invoicing-standards-released-for-eu-compliance/
[18] Factur-X 1.08 / ZUGFeRD 2.4 press release (in force 15 Jan 2026) — FNFE-MPE / FeRD - https://fnfe-mpe.org/wp-content/uploads/2025/12/2025-12-04_Factur-X_1.08_ZUGFeRD_2.4_Press_Release_EN.pdf
[19] BT-118 VAT Category Code (UNCL5305 subset; DE 19%/7% both use 'S', rate in BT-119) — Peppol BIS / Invoice-Converter - https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL5305/
[20] BMF-Schreiben 18.03.2025 — Sonderregelung für Kleinunternehmer (Umsatzsteuer-Anwendungserlass) - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-03-18-sonderregelung-kleinunternehmer.pdf

---

## Open Questions

1. **§19 Kleinunternehmer mid-year "Fallbeil" invoicing mechanics.** The €100,000 crossing transaction is itself fully taxed and the switch is immediate (confirmed [2][5]). The operational detail — whether the crossing transaction may/must be split, and exactly how to word/structure the first taxed invoice — is governed by the **BMF letter of 18 Mar 2025 (Sonderregelung Kleinunternehmer)** [20]; the official PDF could not be machine-parsed during this review. Confirm against the rendered HTML of the Umsatzsteuer-Anwendungserlass before encoding the per-transaction tax flip in `TaxStrategy`.
2. **Hard-require vs suggest `horstoeko/zugferd`.** It is CII-only (verified) and actively maintained. If the package must also emit **UBL-syntax XRechnung**, a second library (or hand-built UBL) is required — which may argue for a thin core plus a separate `gobd-invoice-zugferd` bridge package, rather than a single suggested dependency.
3. **Default PDF/A conformance level (PDF/A-3b vs PDF/A-3a)** and whether `dompdf` can reliably produce valid PDF/A-3 without Gotenberg/Ghostscript post-processing — needs empirical validation against the veraPDF/KoSIT validators. (PDF/A-3b, the visual-only conformance level, is the realistic default; PDF/A-3a requires tagged structure that dompdf does not produce.)
4. **Track the ZUGFeRD/Factur-X and XRechnung version cadence.** As of 2026-06: current is **ZUGFeRD 2.4 / Factur-X 1.08** (in force 15 Jan 2026) [18] and **XRechnung 3.0.2** (Winter 2025/26 bundle) [15]. KoSIT plans one normative XRechnung release per year, so a 3.0.x/3.1 successor is likely before the 2027 issuing deadline — confirm the exact bundle version mandated for B2G at 1 Jan 2027 and re-pin `einvoice.zugferd_version` accordingly. Also confirm which ZUGFeRD/XRechnung schema version `horstoeko/zugferd` bundles at the time of release.
5. **Host-app linkage from gobd documents to customer/order models.** Polymorphic `morphTo` vs a configurable FK — affects the `gobd_documents` table schema and should be decided before shipping migrations.
6. **`lockForUpdate` test coverage.** Because the clause is a no-op on SQLite, the gapless-numbering concurrency guarantee must be proven on MySQL/Postgres in CI, not only on the default in-memory SQLite test DB.
