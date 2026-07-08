# Changelog

All notable changes to `john-wink/gobd-invoice` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
