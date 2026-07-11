# Changelog

All notable changes to `john-wink/gobd-invoice` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

[Unreleased]: https://github.com/john-wink/gobd-invoice/commits/main
