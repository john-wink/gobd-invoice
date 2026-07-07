# Roadmap to a Laracon-ready 1.0 (~8 months)

A dependency-driven, milestone plan. Each milestone is shippable and keeps the
quality gates green. Derived from `docs/research/00-SYNTHESIS.md`. Highest-risk
milestones are **M2** and **M5**; **M4** and the DATEV export are the most
compressible if time runs short. **M1–M3 and M5 are non-negotiable for a
credible 1.0.**

> Legend: ✅ done · 🚧 in progress / next · ⬜ not started

## M0 — Scaffold & quality gates (weeks 1–3) ✅

- spatie/laravel-package-tools provider, config, translations, 4 migrations.
- PHPStan `max` (baseline-free) + Larastan, Rector + rector-laravel, Pint,
  Pest 4 (+ arch, type-coverage, mutation), CI matrix (PHP 8.4/8.5 × Laravel 13
  × prefer-lowest/stable), community health files.
- Core enums incl. the **corrected** `TaxCategory` (valid UNCL5305 codes).
- **Decide host-app linkage** (`documentable` morph vs configurable FK) — *open*.

## M1 — Core domain & numbering (weeks 3–7) ✅ (core)

- Models (swappable), value objects (`Money`, `TaxRate`, `DocumentNumber`),
  document-type/status enums, lifecycle state machine + conversion chain.
- Race-safe `LockingSequenceGenerator` (gapless at finalization).
- **Risk:** `lockForUpdate` is a no-op on SQLite — prove concurrency on
  MySQL/Postgres in CI.

## M2 — VAT / totals engine (weeks 7–11) 🚧

- ✅ per-(category, rate) round-twice algorithm (`GroupedTotalsCalculator`).
- ⬜ net/gross price modes; Rabatt/Zuschlag/Skonto (Skonto as BT-20 metadata,
  §17 correction only on Inanspruchnahme).
- ⬜ effective-date rate table (e.g. Gastronomie 19%→7% from 2026-01-01).
- ⬜ Kleinunternehmer §19 strategy incl. the mid-year €100k "Fallbeil" flip.
- ⬜ multi-currency: store EUR VAT (BT-111) via §16 Abs. 6 UStG average rates.
- **Risk:** EN 16931 zero rounding tolerance; §19 crossing-transaction mechanics.

## M3 — Documents, lifecycle & immutability (weeks 11–15) 🚧

- ✅ finalization (number + snapshot + SHA-256 hash + audit), immutability guard,
  insert-only hash-chained audit log, Storno (Storno statt Löschen).
- ⬜ §14 / §33 / §34a / §14a content validation before finalize (fail closed).
- ⬜ document-type drivers (Abschlags-/Schlussrechnung, Anzahlung, Gutschrift,
  Mahnung, Leistungsnachweis).
- ⬜ **Schlussrechnung double-VAT gate** — block finalize unless prior
  Abschlags-/Anzahlungs nets *and* their VAT are deducted (§14 Abs. 5 / §14c).
- ⬜ finalization pipeline (`Illuminate\Pipeline`) with overridable stages.

## M4 — PDF rendering (weeks 15–18) ⬜

- `PdfRenderer` drivers: dompdf (default), Gotenberg, Typst; Blade themes.
- `PdfA3Merger` (embed `factur-x.xml` into PDF/A-3).
- **Risk:** valid PDF/A-3 (dompdf may need Gotenberg/Ghostscript; 3b vs 3a).

## M5 — E-invoicing: ZUGFeRD / XRechnung (weeks 18–23) 🚧 critical

- `En16931Mapper` (domain → BT-xx), `horstoeko/zugferd` adapters (CII), UBL via
  bridge or native serializer.
- KoSIT validation, fail-closed, reporting the three BMF error categories.
- Receive/parse path (mandatory since 2025-01-01); issuance gating by date /
  domestic-B2B / turnover / amount / Kleinunternehmer.
- **Risk:** CII-only UBL via bridge may fail KoSIT; JRE for the validator;
  format-version churn.

## M6 — Audit, export & retention (weeks 23–27) ⬜

- `RetentionPolicy` + deletion lock + Ablaufhemmung; **permanent 10y carve-out
  for financial-sector tenants** as data, not a 2026 flip.
- Z1/Z2 read-only audit query surface; Z3 GDPdU exporter (driver-based, future
  DSFinV-BV profile); DATEV (EXTF) export.
- IKS hooks (role/permission gates, segregation of duties); byte-exact archive
  of the original structured payload.

## M7 — Hardening, docs & the talk (weeks 27–34) ⬜

- Ratchet mutation score to 95–100 on money/numbering; CI EN 16931 round-trips.
- Verfahrensdokumentation template; "GoBD-ready / Testat-fähig" positioning copy.
- Release hygiene (SemVer, Conventional Commits, Keep-a-Changelog), 1.0 tag,
  Laracon demo app.
- **Risk:** scope creep, over-claiming compliance, shifting legal facts —
  re-verify all dated facts shortly before the release.
