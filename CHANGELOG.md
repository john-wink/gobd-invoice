# Changelog

All notable changes to `john-wink/gobd-invoice` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
- `docs/research/` — verified reference notes on GoBD, UStG invoice content,
  mandatory B2B e-invoicing (XRechnung / ZUGFeRD), retention & tax-audit data
  access, the German document taxonomy, money/VAT/rounding rules, a
  reference/competitor analysis, the package architecture and the quality gates.

[Unreleased]: https://github.com/john-wink/gobd-invoice/commits/main
