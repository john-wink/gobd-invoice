# Changelog

All notable changes to `john-wink/gobd-invoice` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Pre-1.0: the public API may still change between minor versions.

## [Unreleased]

### Fixed (0.2.0-rc.4)

- **One Storno per document, also under concurrency.** `cancel()` locks the
  original (`SELECT … FOR UPDATE`) and re-checks its status under the lock, so
  of N cancellations at the same moment exactly one issues a Storno and N−1 are
  refused (`already cancelled`) without burning a Storno number. A partial
  unique index allows at most one Storno per (tenant,) `source_document_id`,
  and on PostgreSQL a cancelled document can no longer be set to `cancelled`
  again. Proven with 12 forked processes, 5 runs.
- **Status transitions follow the positive list below the model.**
  `DocumentStatus::allowedTransitions()` is enforced by the model and by a
  PostgreSQL trigger. A festgeschriebenes tax-relevant document becomes
  `cancelled` only together with a festgeschriebenen Storno that references it;
  a Storno without that reference (or against another tenant's document) can
  no longer be festgeschrieben; and once a Storno is festgeschrieben its
  original is `cancelled` — checked at commit by a deferred constraint trigger,
  tested as table owner and as a DML-only application role.
- **The audit chain is deterministic.** Every entry carries its position
  (`sequence`, 1…n per document); `append()` locks the document row, a unique
  (`document_id`, `sequence`) index backs it up, and `verify()` reads the chain
  in that order. 12 parallel appends leave exactly one chain end.
- **`recordPayment()` is atomic.** It locks and re-reads the document before
  adding the payment, so parallel or stale-instance payments add up.
- **`verify()` stays true after a payment.** `paid_total` and `amount_due` are
  no longer part of the hashed snapshot; a snapshot finalized by an earlier
  release still verifies (its payment fields are ignored).
- **Retention and host link are frozen at Festschreibung.** `retention_until`,
  `retention_class`, `is_financial_sector`, `documentable_type` and
  `documentable_id` join the immutable columns (model and trigger). `meta`
  stays writable: it is host bookkeeping, not §14 content, and not hashed.

### Changed (0.2.0-rc.4)

- The audit hash also covers the entry's `sequence`, `actor` and tenant, so
  rewriting who acted or reordering entries is detected. `created_at` stays
  out: its round trip depends on the connection's session time zone.
- `finalize()` on a Storno drafted against a document cancels that document in
  the same transaction (audit entry `cancelled`, event `DocumentCancelled`) and
  consults `SegregationPolicy::assertCanCancel()` for it.
- A `paid` document can no longer be cancelled: the positive list has no
  `paid → cancelled` transition, and `cancel()` now refuses it with an
  `InvalidStatusTransitionException` instead of writing it.
- New named constructors `InvalidStatusTransitionException::cancelledWithoutStorno()`
  and `::stornoWithoutSource()`.

### Upgrading from 0.2.0-rc.3

The package migrations create new tables with all of this. A host whose tables
were created by rc.1–rc.3 adds in its own migration:

1. Audit log: column `sequence` (unsigned integer, NOT NULL, filled 1…n per
   `document_id` in chain order) and a unique index on
   (`document_id`, `sequence`).
2. Documents: the partial unique index
   `CREATE UNIQUE INDEX gobd_documents_one_storno_per_document ON gobd_documents ([team_id, ]source_document_id) WHERE type = 'storno'`
   (PostgreSQL, SQLite, SQL Server; MySQL/MariaDB have no partial indexes and
   rely on the row lock of `cancel()`).
3. PostgreSQL: call `PostgresGuards::protectDocuments('gobd_documents')` again.
   It replaces `gobd_documents_guard()` and adds the triggers
   `gobd_documents_status_guard` and `gobd_documents_storno_guard`.

Audit entries written by rc.1–rc.3 were hashed without position, actor and
tenant and do not verify under rc.4; pre-release data has to be recreated.

### Added

- **PostgreSQL is a proven target (0.2.0-rc.1).** The whole suite runs against
  PostgreSQL 18 in a second CI job. Parallel-process concurrency tests fork 24 OS
  processes that festschreiben at the same instant — one counter, and two
  tenants side by side, each repeated 10 times — and assert a gapless,
  duplicate-free sequence. The gapless generator's row lock is proven: removing
  `lockForUpdate()` makes the test fail on every run.
- **Multi-tenancy (`gobd-invoice.tenancy.column`).** Set to a column such as
  `team_id`, every package table carries it (NOT NULL), each tenant runs its own
  counters and a number is unique per tenant — **`UNIQUE(team_id, number)`** in
  the database instead of a mandatory tenant prefix in the format. `draft()`
  needs the tenant in its attributes; Storno, conversion and Mahnung inherit it,
  lines and audit entries are stamped with it, a row never changes its tenant,
  and an advance of another tenant can never be deducted.
- **UUID keys (`gobd-invoice.database.key_type = uuid`).** All package tables,
  the document references and the `documentable` morph can be keyed by UUIDv7.
- **PostgreSQL guard triggers.** On PostgreSQL the migrations install triggers
  that enforce Festschreibung below the model layer: a finalized tax-relevant
  document keeps its §14 content, cannot be deleted or returned to draft; its
  lines cannot be added, changed or removed; the audit log is append-only; a
  number counter never runs backwards and is never deleted; TRUNCATE is refused;
  and with tenancy no row changes its tenant and a line always belongs to its
  document's tenant.
- **Guarded tenant columns (0.2.0-rc.2).** The package never mass-assigns the
  tenant column; it sets it on lines, counters and audit entries itself. A host
  subclass can therefore keep `team_id` out of mass assignment
  (`$guarded = ['team_id']`), even under `preventSilentlyDiscardingAttributes`.
- **Host line attributes.** `DocumentLine::passthroughAttributes()` names
  host-owned line columns (e.g. a catalogue reference or `price_snapshot_at`)
  that `draft()` stores and `convert()`/`cancel()` carry forward.
- **`GobdInvoice::updateDraft($document, $attributes, $lines)`** — edit an
  unfinalized draft in place: re-applies the draft attributes and replaces its
  line items. Throws for a finalized document (Unveränderbarkeit). Lets a host
  UI offer an edit mode for drafts before Festschreibung.

### Changed (breaking for 0.1 installations)

- `NumberSequenceGenerator::next()` takes the tenant as a fourth parameter, and
  the overridable `sequenceKeys()` / `formatFor()` receive it too.
- The migrations use `jsonb` and `timestamptz` (`timestampsTz`) and key the
  unique number index by tenant when tenancy is enabled. Existing 0.1 tables are
  not migrated by the package; a 0.1 host upgrades its own schema.
- The models are `#[Unguarded]` instead of `$guarded = []` (same behaviour, also
  for host subclasses).

### Fixed

- **Document is now safely subclassable.** The `lines()` and `auditEntries()`
  relations pin the `document_id` foreign key explicitly (Eloquent otherwise
  infers `<subclass>_id` from the parent class name), and `source()` links to
  `static::class` — so a host subclass (e.g. a multi-tenant `Document`) keeps the
  package's schema. This is required for the advertised swappable-models pattern.
- **Hosts with immutable dates (0.2.0-rc.3).** A host that sets
  `Date::use(CarbonImmutable::class)` could neither draft a document with a date
  nor finalize one: `parseDate()` and the retention window were typed to the
  mutable `Illuminate\Support\Carbon` and threw a `TypeError`, and the virtual
  `due_date` silently returned `null`. Dates are now typed as `CarbonInterface`
  throughout (`recordPayment()` accepts any Carbon instance), so the host's
  date class is used as it is.

## [0.1.0] - 2026-07-11

### Added

- **IKS internal-control hooks (segregation of duties):** every lifecycle action
  now resolves an actor via a pluggable `ActorResolver` (default: the
  authenticated user) and records it on the audit trail and as a document's
  `created_by`. A `SegregationPolicy` gate is consulted BEFORE finalize and
  cancel (a preventive control, distinct from the after-the-fact log): the
  default `PermissiveSegregationPolicy` is unrestricted, and an opt-in
  `FourEyesSegregationPolicy` (via `gobd-invoice.iks.segregation`) enforces that a
  document's creator may not also finalize or cancel it (Vier-Augen-Prinzip). The
  Storno that `cancel()` writes internally is exempt from the finalize gate (the
  cancellation itself is already gated). Hosts bind their own policy for
  role-based rules.
- **Mahnung / Verzug (dunning & §288 default interest):** a `DunningInterestCalculator`
  (`StatutoryDunningInterestCalculator`) computing §288 BGB default interest on an
  overdue principal — Basiszinssatz (§247, a dated Bundesbank table shipped in
  config and verified to 2026-07-01 = 1.52 %) plus the §288 surcharge (consumer
  +5, business +9 points), the €40 flat fee (§288 Abs. 5, business debtors only),
  computed act/act, simple (§289, no compounding), split per half-year rate
  change. **Interest is opt-in:** a goodwill reminder (Kulanz) sets
  `withInterest: false` and demands only the principal. Exposed as
  `GobdInvoice::assessDunning($principal, $options)` (a `DunningAssessment` with a
  per-period breakdown) and `GobdInvoice::dun($invoice, $options)`, which creates a
  Mahnung — a non-tax business document linked to the invoice, the assessment in
  its metadata, deliberately kept out of the immutable tax record. Debtor regime
  and the base-rate table are configurable (`gobd-invoice.dunning.*`).
- **DATEV export (EXTF Buchungsstapel):** a `DatevExporter` contract and an
  `ExtfExporter` producing the byte-correct DATEV booking batch a tax advisor
  imports (format 700 / layout 13) — Windows-1252, CRLF, semicolon-delimited,
  comma decimals, the 31-field EXTF header, the verbatim 125-column heading row
  and one booking per VAT group (gross to the debtor "Konto" against the mapped
  revenue "Gegenkonto"; Storno/Gutschrift book "H", everything else "S").
  Exposed as `GobdInvoice::exportDatev($documents, $options)`. Account numbers
  are client-specific, so the package hardcodes none: the debtor and per-VAT-group
  revenue accounts come from `gobd-invoice.datev.*` config (keyed by the canonical
  "<category>:<rate>" group, e.g. "S:19"), or from a custom `DatevAccountResolver`;
  an unmapped group fails the export loud. See `DatevExportOptions` for the header
  metadata (Berater/Mandant/fiscal year/date range/Festschreibung).
- **Anzahlungsrechnung (advance-payment invoice) document type:** a distinct
  advance-invoice type (§13 Abs. 1 Nr. 1a UStG) alongside the Abschlagsrechnung.
  It is tax-relevant, deductible in a Schlussrechnung — the §14 Abs. 5 double-VAT
  gate now covers *all* advance-invoice types via
  `DocumentType::advanceInvoiceValues()` — and maps to EN 16931 type code 386
  (prepayment invoice).
- **M6 GoBD / GDPdU data export (Z3):** a `GobdDataExporter` contract and a
  `GdpduExporter` producing a tax-audit "Datenträgerüberlassung" data set — the
  `rechnungen.csv` and `positionen.csv` tables plus the `index.xml` GDPdU
  descriptor (columns defined positionally, decimal point, semicolon-delimited,
  quoted, CRLF) — exposed as `GobdInvoice::exportGdpdu($documents)`. The host
  supplies the documents (e.g. a date-range query); finalized documents are never
  deletable (the retention/immutability guard already enforces this).
- **M4 hybrid PDF/A-3 (ZUGFeRD / Factur-X):** an `EInvoicePdfBuilder` contract and
  a `ZugferdPdfBuilder` that embed the finalized document's CII XML into a
  host-supplied base PDF (via `horstoeko/zugferd`), yielding a hybrid PDF/A-3 —
  exposed as `GobdInvoice::eInvoicePdf($document, $basePdf)`. The visual PDF is
  rendered by the host; this package owns the compliant embedding. Round-trips
  through the PDF reader (the embedded XML is extractable and parses back to the
  invoice). `ZugferdCiiSerializer` now exposes `buildDocument()` so the PDF path
  reuses the exact same EN 16931 mapping as the XML export.
- **M5 EN 16931 validation (native, Java-free):** an `EInvoiceValidator` contract
  and a `NativeEInvoiceValidator` driver backed by the new, dependency-free
  [`john-wink/en16931-php`](https://github.com/john-wink/en16931-php) engine — **no
  KoSIT jar, no JRE, no subprocess**. Exposed as `GobdInvoice::validateEInvoice()`;
  accepts CII and UBL (UBL is bridged to CII first). Setting
  `einvoice.validate_on_export` makes `eInvoiceXml()` validate the produced XML and
  throw on a fatal EN 16931 violation, so a non-conformant e-invoice cannot be
  emitted. XRechnung formats add the German CIUS rules (BR-DE-*) on top of the
  EN 16931 core. The validator ships a growing high-value rule subset (presence,
  the tolerance-free BR-CO-* calculations, VAT-category and code-list rules, the
  Leitweg-ID); full KoSIT-corpus parity is the ongoing goal.
- **M5 e-invoice receiving / parsing:** an `EInvoiceReader` contract and a
  `ZugferdCiiReader` that parse an incoming EN 16931 e-invoice — CII **or** UBL
  (UBL is bridged to CII first, so both syntaxes are accepted) — into a
  framework-agnostic `ParsedEInvoice` value object (header, seller/buyer parties,
  the monetary summation BT-106 → BT-115, lines and the VAT breakdown), exposed as
  `GobdInvoice::parseEInvoice()`. This fulfils the B2B e-invoice **receiving**
  obligation in force since 2025-01. The values are the sender's declarations,
  surfaced as-is (the package does not re-compute or trust them). Money is parsed
  **locale-independently** (`number_format`, never the LC_NUMERIC-sensitive
  `sprintf('%f')`); a **non-two-decimal currency** (JPY, BHD, …) is **rejected**
  rather than silently mis-scaled, since the engine's `Money` is two-decimal; an
  unreadable or malformed payload fails loud with a `GobdInvoiceException`.
- **M5 XRechnung UBL export:** an `XRechnungUblSerializer` that converts the
  XRechnung-CII output to UBL syntax via `horstoeko/zugferdublbridge`, selected by
  `einvoice.default_format = 'xrechnung-ubl'`. There is one source of truth (the
  CII mapping); the bridge picks the UBL root document from the EN 16931 type code,
  so an invoice becomes a UBL `Invoice` and a Storno a UBL `CreditNote`. Adds
  `horstoeko/zugferdublbridge ^1.0`; see [`docs/dependencies.md`](docs/dependencies.md)
  for the borrow-vs-build rationale and the KoSIT-XSLT fallback.
- **M5 e-invoicing — ZUGFeRD / Factur-X / XRechnung CII export (EN 16931):** an
  `EInvoiceSerializer` contract and a `ZugferdCiiSerializer` that map a finalized
  document to EN 16931 Cross-Industry-Invoice XML via `horstoeko/zugferd`, exposed
  as `GobdInvoice::eInvoiceXml()`. The library is wrapped behind the serializer so
  the domain model never depends on its API (nor on the ZUGFeRD 2.x / XRechnung
  3.x→4.x transitions). The configured `einvoice.default_format` selects the
  profile — ZUGFeRD/Factur-X **EN16931 (COMFORT)** by default, or the XRechnung 3
  CIUS; the **MINIMUM and BASIC WL profiles are refused** (booking aids, not a
  valid §14 invoice). Maps parties and tax registrations (USt-IdNr = VA,
  Steuernummer = FC), lines (with exact BT-131 line-total reconciliation via the
  price base quantity), the per-(category,rate) VAT breakdown with exemption
  reasons (BT-120, host-overridable via `meta.exemption_note`), payment terms, and
  the full monetary summation (BT-106 → BT-115) with paid amount + already-invoiced
  advances folded into the prepaid amount so BR-CO-16 holds. A **Storno is emitted
  as a 381 credit note with positive amounts** (the credit is conveyed by the type
  code, not a negative sign — BR-27); a **non-EUR invoice additionally carries the
  accounting currency (BT-6) and the EUR VAT total (BT-111)** at the §16 Abs. 6
  rate; a **reverse-charge (AE) or intra-community (K) invoice without a buyer VAT
  id fails loud** (BR-AE-02 / BR-IC-02, BT-48); a zero-quantity line no longer
  breaks the line-total division. `DocumentType` gains `en16931TypeCode()` (BT-3:
  380 invoice / 381 credit note / 389 self-billed). KoSIT Schematron validation,
  the XRechnung UBL syntax, PDF/A-3 embedding and the receive/parse path are later
  M5 slices.
- **M3 document conversion:** `GobdInvoice::convert()` turns a pre-invoice
  document (Angebot, Kostenvoranschlag, Leistungsnachweis) into an invoice draft
  (Rechnung, Abschlags-/Schlussrechnung), copying its lines, parties and
  document-level allowances/charges/payment-terms/rate forward and keeping a
  `source_document_id` audit link (offer → contract → invoice). Allowed pairs are
  gated by `DocumentType::canConvertTo()`; the conversion preserves the source
  currency (no FX) and the §14 Abs. 5 cross-order advance guard applies (a
  converted Schlussrechnung cannot deduct a different order's advance). `draft()`
  now also accepts raw `documentable_type`/`documentable_id` and
  `source_document_id` attributes.
- **M3 Schlussrechnung double-VAT gate (§14 Abs. 5):** an `AdvanceDeduction`
  value object and a `deducts` draft key that references prior finalized
  Abschlagsrechnungen by id; the manager snapshots each advance's net + VAT (as
  shown) and deducts both from the final invoice's amount due, and
  `DocumentTotals` exposes `advancesNetTotal` / `advancesVatTotal`. Finalizing a
  Schlussrechnung runs an unconditional gate that blocks it while a finalized,
  non-cancelled Abschlagsrechnung for the same order (documentable) is left
  un-deducted — preventing the §14c double-VAT error. Resolution fails loud on a
  missing, duplicate, cancelled, wrong-type, wrong-currency or wrong-order
  advance. The structured EN 16931 representation of the deduction is deferred to
  the e-invoice exporter (M5).
- **M3 §14 content validation (fail-closed):** a `Party` value object plus
  `seller` / `buyer` on the document (§14 Abs. 4 Nr. 1/2 parties), and a
  `DocumentContentValidator` (`MandatoryContentValidator`) that `finalize()` runs
  before assigning a number: a tax-relevant document missing a §14 mandatory
  field (parties, supplier Steuernummer/USt-IdNr, line quantity+description, time
  of supply) throws a `DocumentContentException` instead of being festgeschrieben.
  The §33 UStDV Kleinbetragsrechnung relaxation (gross ≤ €250 drops the recipient
  and supplier tax id, unless §13b/§6a) is honoured; non-invoice types are
  skipped. Parties are part of the immutable snapshot and are carried into the
  Storno. Toggle via `gobd-invoice.content_validation` (default on).
- **M3 finalization wiring:** `finalize()` now computes and persists the full
  EN 16931 monetary chain (BT-106 → BT-115 plus BT-111) via the
  `DocumentTotalsCalculator`. `draft()` accepts per-line price modes (`net` /
  `gross`) and allowances/charges, and document-level allowances/charges,
  `payment_terms` (Skonto), an `accounting_rate` (§16 Abs. 6 UStG rate to EUR)
  and `paid_minor`. The immutability guard, the content-hash snapshot (incl. the
  Storno→original link) and the Storno reversal (which flips document-level
  allowances/charges) all cover the new fields. Malformed adjustment/rate/amount
  input fails loud at draft; a non-EUR invoice without an accounting rate fails
  loud at finalize.

- **M2 multi-currency VAT (BT-111):** `ExchangeRate` value object +
  `Money::convertedTo()`. When the invoice currency is not the VAT accounting
  currency (EUR), `DocumentTotals` additionally carries the total VAT expressed
  in EUR (EN 16931 BT-111), converting the already-rounded BT-110 once at the
  supplied §16 Abs. 6 UStG rate and retaining the rate for GoBD reproducibility.
  Rate values are host-supplied (the package does not fetch exchange rates).
- **M2 §19 Kleinunternehmer rule:** `KleinunternehmerRule` contract +
  `ThresholdKleinunternehmerRule` + `KleinunternehmerAssessment` value object.
  Assesses §19 UStG exemption from prior-/current-year net turnover against the
  configurable 2025-reform limits (€25,000 / €100,000), modelling the mid-year
  "Fallbeil" (exceeding the upper limit ends the exemption at once) and the
  founding-year case (no prior year → only the €25,000 limit applies). An exempt
  assessment maps to EN 16931 category `E` and owns the mandatory §19 note
  (§34a S. 1 Nr. 5 UStDV), decoupled from the bare category so non-§19 exemptions
  do not inherit it. Turnover is host-supplied; limits are config-driven.

### Changed

- Corrected the §19 Kleinunternehmer invoice note to reference the
  *Steuerbefreiung* (§34a UStDV / the 2025 reform), replacing the outdated
  pre-2025 "keine Umsatzsteuer wird berechnet" (Nichterhebung) wording.
- `TaxCategory::Exempt->noteTranslationKey()` now returns `null`: an exemption's
  note depends on its legal basis (§19 vs §4 UStG), so it is no longer derived
  from the bare category.
- **M2 effective-date VAT rate table:** `TaxRateKind` (standard/reduced),
  `TaxRatePeriod` value object and a `TaxRateResolver` (`PeriodTaxRateResolver`)
  that resolves the §12 UStG rate in force on the Leistungszeitpunkt, falling
  back to the flat rates. Ships NO unverified dated rates (config `rate_periods`
  defaults to `[]`); malformed rate config fails loud (real-date, non-negative
  canonical-decimal and non-boolean validation).
- **M2 totals engine (core):** net/gross price modes (`PriceMode`), line- and
  document-level allowances & charges (`AllowanceCharge`, Rabatt/Zuschlag, fixed
  or percentage), payment terms with Skonto metadata (`PaymentTerms`), and a
  `DocumentTotalsCalculator` (`GroupedDocumentTotalsCalculator`) producing the
  full EN 16931 monetary chain (BT-106 → BT-115). VAT is rounded once per
  (category, rate) group; document-level allowances/charges are apportioned into
  their VAT group before the group VAT is computed (REQ-14). Skonto is carried as
  metadata only and never reduces the totals (§17 Abs. 1 UStG: the base changes
  on Inanspruchnahme). `Money::netFromGross()` extracts the net base contained in
  a gross amount. Not yet wired into finalization/persistence (next slice).
- Project scaffold, tooling (PHPStan max, Rector, Pint, Pest, Prettier), CI matrix
  and community health files.
- **Release hardening:** a mutation-testing gate over the correctness-critical
  core (integer money math, VAT grouping/rounding, gapless numbering and the
  immutability hash) enforcing a ≥90% mutation score (currently 91.94%; the
  remainder are documented equivalent mutants). Pinned `setasign/fpdf` to
  `^1.8.6` — the floor the hybrid-PDF feature actually needs (horstoeko's `fpdi`
  chain), which `^1` alone would not guarantee. CI runs prefer-stable on PHP 8.4
  and 8.5.
- `docs/research/` — verified reference notes on GoBD, UStG invoice content,
  mandatory B2B e-invoicing (XRechnung / ZUGFeRD), retention & tax-audit data
  access, the German document taxonomy, money/VAT/rounding rules, a
  reference/competitor analysis, the package architecture and the quality gates.

[Unreleased]: https://github.com/john-wink/gobd-invoice/compare/v0.1.0...main
[0.1.0]: https://github.com/john-wink/gobd-invoice/releases/tag/v0.1.0
