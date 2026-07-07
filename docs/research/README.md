# Research notes — gobd-invoice

These are the authoritative, **adversarially fact-checked** reference notes for
the package. Each document was researched against primary sources and then
independently verified; load-bearing dates, thresholds, rates, §-references and
library versions were re-checked (the verifier's corrections are annotated inline
in each file and summarized in `00-SYNTHESIS.md`).

**Verified as of 2026-06-25.** German tax and e-invoicing law is changing
frequently — re-verify any dated fact before relying on it close to a release.
**This is research material, not legal advice.**

## Index

| # | File | What it covers |
|---|---|---|
| 00 | [`00-SYNTHESIS.md`](00-SYNTHESIS.md) | Prioritized compliance checklist (P0/P1/P2), cross-doc conflicts, gaps, the recommended architecture and the 8-month roadmap |
| 01 | [`01-gobd-compliance.md`](01-gobd-compliance.md) | GoBD principles (Unveränderbarkeit, Nachvollziehbarkeit, …) translated into a concrete technical-requirements checklist |
| 02 | [`02-legal-invoice-content.md`](02-legal-invoice-content.md) | §14 UStG mandatory fields, Kleinbetragsrechnung, Kleinunternehmer 2025 reform, numbering |
| 03 | [`03-e-invoicing.md`](03-e-invoicing.md) | E-Rechnungspflicht timeline, XRechnung, ZUGFeRD/Factur-X profiles, PHP libraries |
| 04 | [`04-retention-and-audit-access.md`](04-retention-and-audit-access.md) | Aufbewahrungsfristen, format preservation, Z1/Z2/Z3 data access, deletion locks |
| 05 | [`05-document-types-and-lifecycle.md`](05-document-types-and-lifecycle.md) | The German document taxonomy and a proposed lifecycle state machine |
| 06 | [`06-money-tax-and-rounding.md`](06-money-tax-and-rounding.md) | Money modeling, VAT per tax group, kaufmännische Rundung, Skonto |
| 07 | [`07-reference-and-competitor-analysis.md`](07-reference-and-competitor-analysis.md) | Rechno + OSS/commercial competitor analysis and a differentiation statement |
| 08 | [`08-package-architecture.md`](08-package-architecture.md) | The Laravel 13 package architecture this codebase implements |
| 09 | [`09-tooling-and-quality-gates.md`](09-tooling-and-quality-gates.md) | Exact tooling versions, configs and the CI matrix |

## Highest-value corrections the fact-check caught

These were wrong in the first drafts and are now fixed in the docs **and** the
code — do not regress them:

- **Financial-sector retention is permanently 10 years** (SchwarzArbMoDiG
  reversed the BEG IV cut), not "8 years from 2026". *(04, 07)*
- **`TaxCategory` had two bugs:** `AA` is not a valid reduced-rate code (7% is
  category `S`, rate in BT-119), and `Exempt`/`Kleinunternehmer` sharing value
  `E` is a **PHP fatal error**. *(08)*
- **ZUGFeRD/Factur-X moved to 2.4 / 1.08** (in force 2026-01-15); 2.3.x is
  superseded; MINIMUM/BASIC-WL are not valid e-invoices. *(03, 08)*
- **§14 Abs. 4 UStG has Nr. 1–10 only**; the reverse-charge note is §14a Abs. 5.
  *(02)*
- **Retention cut-off is 31.12.2024** (Art. 97 §19a EGAO), not 01.01.2025. *(01)*
- **Kleinunternehmer-IdNr. uses the suffix `…EX`** (BZSt, §19a cross-border),
  not a `DE…-KU` prefix. *(02)*

See `00-SYNTHESIS.md` for the full conflict list and resolutions.
