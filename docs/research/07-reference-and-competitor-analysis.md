# Reference Projects & Competitor Analysis

> **Fact-check status (verified 2026-06-25).** Every load-bearing claim below was independently re-checked against primary/authoritative sources. License, star count, version, release date, profile/syntax support, and the German legal facts were confirmed live unless a claim is explicitly flagged as *unverifiable* or *as of <date>*. GitHub star counts and release versions drift continuously; the numbers below are point-in-time snapshots (June 2026) and should be treated as approximate. See **Sources** and **Open Questions**.

## Purpose of these notes

This document is the competitive and reference baseline for `john-wink/gobd-invoice`, a framework-only (no Filament) Laravel 13 / PHP 8.4+ backend engine for GoBD-compliant German business documents. It captures (1) the **Rechno** project the maintainer cited, (2) the OSS and German-PHP landscape, (3) the commercial feature-set we must learn from, (4) how GoBD / IDW PS 880 certification actually works and what an OSS library can and cannot claim, and (5) a sharp differentiation statement. Every load-bearing legal fact carries its applicable date/version because German e-invoicing and bookkeeping law moved substantially in 2024-2026.

---

## 1. Rechno — the cited reference project

> **Verification caveat.** The Rechno project page (`https://oliver.software/projects/rechno`, source [1]) returns **HTTP 403 Forbidden** to automated fetchers and is **not indexed by public search engines** — consistent with the draft's note that the link is marked "RESTRICTED." The author, **Oliver Wycisk**, is independently verifiable as a real Laravel Technical Lead / consultant via his resolving homepage `oliver.software` [1a]. However, **all Rechno-specific claims below (scope, feature set, status, start date) originate from maintainer-supplied marketing copy and could not be independently re-confirmed.** Treat them as the maintainer's account, not as verified fact.

Rechno is a project attributed to Oliver Wycisk (oliver.software), reported as started **2025-08, "Active / Deployed"** as of mid-2026 [1] (*dates unverifiable — see caveat*). It is the closest stated analogue to what we are building, so it is the primary reference target.

**Scope & positioning (verbatim claims, per the cited page):** "A laravel invoicing package for the german market (GoBD compliant)"; a "comprehensive, closed-source PHP Laravel package engineered to solve the immense technical and legal challenges of German business invoicing"; designed as a "foundational billing and document engine across [the author's] entire product ecosystem and client projects." It is explicitly **proprietary / Closed Source**, with the link marked "RESTRICTED" [1].

**Stack:** Laravel package (PHP). Tagged `Laravel`, `Package`, `Invoicing` [1].

**Architecture & feature claims** [1]:

| Claim | Concrete software requirement it implies for us |
|---|---|
| "Strict GoBD Compliance Engine: immutable document workflows, automated hash verification, and internal audit logging" | Append-only documents; per-document content hash; tamper-evident audit log |
| "Polymorphic Document Architecture: unified core" for Rechnungen, Angebote (quotes), **Lieferscheine** (delivery notes) | One document engine, many document types via polymorphic/STI model |
| "Precision Calculation Core … net/gross … line-item adjustments … zero rounding drift" | Integer-minor-unit or BC-math money; documented German rounding rules |
| "Seamless Laravel Injection … integrated … in less than an hour" | First-class package DX: config, migrations, contracts, events |

**Gaps / things to beat.** Rechno's public description names **only** Rechnung, Angebot, Lieferschein. It does **not** advertise the harder document types in our brief — Kostenvoranschlag, Beleg, **Leistungsnachweis, Teilzahlung, Abschlags-/Schlussrechnung, Storno, Gutschrift, Mahnung** — nor does it claim **EN 16931 e-invoice output** (XRechnung / ZUGFeRD), DATEV export, or any third-party validation/Testat. Its decisive strategic weakness for our purposes is that it is **closed source and unbuyable** ("kept proprietary as a competitive advantage") [1]. That is precisely the opening for an open, auditable package: Rechno (if its claims hold) proves the demand and validates the architecture (polymorphic core + hash + audit log + precise money), but it cannot be inspected, trusted-by-reading, or community-hardened. **We beat it on openness, breadth of document types, and real e-invoice (EN 16931) output.**

---

## 2. The OSS PHP / Laravel landscape

> All figures below verified on **2026-06-25** against GitHub and Packagist. Star counts are snapshots and will drift.

### 2.1 Full invoicing applications (not libraries)

| Project | Type | License | Stars | Last activity | German compliance? |
|---|---|---|---|---|---|
| **Crater** (`crater-invoice-inc/crater`) | Self-hosted app (Laravel + Vue, RN mobile) | AGPL-3.0 | ~8.3k | **Stale** — last release 6.0.6, **2022-03-06**; ~385 open issues [2] | None (generic VAT/GST) |
| **InvoiceShelf** (`InvoiceShelf/InvoiceShelf`) | Self-hosted app; **fork of Crater** | AGPL-3.0 | ~1.7k | **Active** — v2.4.1 (**2026-06-14**), Laravel 13 since v2.2.0, PHP 8.4+ [3] | None — no GoBD/XRechnung/ZUGFeRD/DATEV advertised [3] |
| **Firefly III** (`firefly-iii/firefly-iii`) | Self-hosted **personal-finance / double-entry** manager | AGPL-3.0 | ~23.8k | **Active** — v6.6.3 (2026-05) [4] | Out of scope — it is *not* an invoicing tool [4] |

> **Correction / clarification vs. draft.** (a) Firefly III's scale is now quantified: **~23.8k stars**, actively maintained (v6.6.3, May 2026) — the draft's "very large / Active" is accurate but vague. (b) The "InvoiceShelf is a fork of Crater" claim is **confirmed**: InvoiceShelf's own README states it "is a fork of Crater that focuses on stability, updates and new features," continuing the project after Crater "went into an unsupported state" [2][3].

**Lessons.** (a) Crater's death-by-staleness is the cautionary tale: a monolithic app accreted features, then stalled (last release 2022-03), and the community had to fork it (InvoiceShelf) to survive [2][3]. A **narrow, embeddable library** with a stable contract ages far better than a full app. (b) All three are **AGPL-3.0** — an aggressive copyleft that scares commercial integrators. A backend engine meant to be embedded into proprietary SaaS should prefer **MIT** (matching horstoeko) to maximise adoption. (c) None of them touch German legal compliance — the entire OSS app tier leaves GoBD + EN 16931 unserved. (d) Firefly III defines our **anti-scope**: we are not a bookkeeping ledger, not double-entry accounting, not a bank-reconciliation tool [4].

### 2.2 Pure invoice/PDF libraries

**`laraveldaily/laravel-invoices`** — **GPL-3.0-only**, ~1.6k stars, **v4.2.0 (2026-03-19)**, supports **Laravel 10/11/12/13**, **PHP ≥ 8.2** [5] (verified on Packagist). It is a **PDF-only generator** (templates, taxes fixed/rate, discounts fixed/percentage, shipping, serial numbers, currency formatting, translations, automatic totals). It has **no** e-invoice formats, **no** GoBD/immutability/audit, **no** XML/structured export [5]. **Lesson:** this is among the most-installed Laravel invoice packages and it is *just rendering* — confirming a clear, unoccupied niche for a compliance/structured-data engine. We should keep PDF rendering pluggable/optional and not compete on template prettiness; our value is the legal/data layer beneath the PDF.

> **Note on the package's repository casing:** the canonical Packagist/GitHub vendor is **`laraveldaily`** (lowercase). Some references write `LaravelDaily/laravel-invoices`; GitHub redirects are case-insensitive, so both resolve.

### 2.3 German e-invoice libraries (the components, not competitors)

**`horstoeko/zugferd`** — **MIT**, **~428 stars**, actively released (**v1.0.123, 2026-06-01**), **PHP ≥ 7.0** (runs on 8.x) [6]. Reads/writes **ZUGFeRD / XRechnung / Factur-X** XML across all profiles (MINIMUM, BASIC, BASIC-WL, EN16931/Comfort, EXTENDED, and XRechnung 1.x/2.x/3.x) and can embed the XML into a PDF/A-3 via its PDF merger. **CII syntax only — no native UBL** (the README states "This package provides only support for CII-Syntax - not UBL-Syntax") [6]. Sibling packages (all by horstoeko, verified on Packagist): `zugferd-laravel` (Laravel facades/service-provider wrapper), `zugferdvisualizer`, **`zugferdublbridge`** (converts ZUGFeRD/Factur-X **CII ↔ PEPPOL UBL** in both directions), `zugferd-mail`; plus the separate **`horstoeko/orderx`** (**MIT**, Order-X *order* documents — Bestellungen, not invoices) [6][7].

> **Correction vs. draft.** The draft described `zugferd-ubl-bridge` as merely "(adds UBL)." More precisely, the package (repo `zugferdublbridge`) is a **bidirectional CII ↔ PEPPOL UBL converter**, not a from-scratch UBL writer. This matters for the adapter design: it transforms an existing CII document into UBL, so the EN 16931 semantics still flow through the CII pipeline first.

**Lesson and strategic decision:** Do **not** reimplement EN 16931 XML. Adopt `horstoeko/zugferd` as the structured-format engine behind a thin, swappable adapter interface (`EInvoiceRenderer`). Note the UBL gap: native XRechnung-UBL output requires routing through `zugferdublbridge` (CII→UBL) or an alternative codec — keep the adapter format-agnostic. The horstoeko ecosystem's **MIT** license is another argument for us to ship MIT so the dependency and the consumer license align cleanly.

---

## 3. Commercial German tools — the compliance feature checklist to match or beat

These are SaaS products, not libraries, so they are reference *feature-sets*, not code competitors. What they **advertise** is the de-facto market checklist a serious German invoicing tool is expected to satisfy. (Vendor feature claims verified against their own product/help pages and independent reviews, June 2026.)

| Tool | Advertised compliance hooks |
|---|---|
| **Lexware Office** (formerly *lexoffice*; renamed 2023) | "GoBD-konform"; GoBD certification by **Ernst & Young GmbH (initial GoBD certification 2016, with regular reaudits)** — i.e. a Testat; DATEV export/interface; XRechnung & ZUGFeRD **creation, receipt and validation per EN 16931** with an integrated ZUGFeRD validator; GoBD-compliant (revisionssicher) cloud archiving. *Reported* gap: weaker on *pure* XRechnung-UBL export workflows for some plans [8a] |
| **sevDesk** | Revisionssichere archiving advertised as **GoBD-certified** ("GoBD-Testat"); DATEV export; e-invoice send/receive (XRechnung/ZUGFeRD); document capture into audit-proof (revisionssichere) storage; data on German servers [8] |
| **Easybill** | "GoBD-compliant" management; **ZUGFeRD (2.4 Extended) + XRechnung**; bulk invoice creation; marketplace integrations (Amazon/eBay/Shopify + 50+ shops); DATEV interface (ELSTER/tax-advisor data export) [9] |
| **FastBill / Billomat / Papierkram** | ZUGFeRD + XRechnung; DATEV interface; revision-safe archiving; project/time billing; Papierkram reported to have *limited* XRechnung [9] |

> **Citation-precision note (corrected vs. draft).** The draft cited a single sevDesk URL ([8], `sevdesk.de/archivierungssoftware/`) for *both* sevDesk **and** Lexware claims. That URL only covers sevDesk. The Lexware claims are real but are sourced separately ([8a], Lexware's own GoBD page and independent reviews). Both citations are listed in **Sources**.

**The recurring four claims** — *GoBD-konform / GoBD-Testat*, *E-Rechnung (XRechnung + ZUGFeRD)*, *DATEV-Export*, *revisionssichere Archivierung* — are the table stakes. Translated into concrete requirements for our **backend engine** (we own the data layer; the deploying app owns UI and storage policy):

1. **GoBD data integrity:** immutability after Festschreibung (commit), full change history, tamper-evident audit trail (see §5).
2. **E-Rechnung output:** EN 16931 via the zugferd adapter; configurable XRechnung 3.0.2 and ZUGFeRD ≥ 2.0.1 profiles (see §4).
3. **DATEV export:** ship a DATEV-format export contract (e.g. EXTF / "DATEV-Format" booking + document export). This is a discrete, well-scoped deliverable that none of the OSS options provide — a clear win.
4. **Archiving:** provide the immutable record + hash + export; let the host application supply WORM/audit-proof storage. We must **document** that the storage medium's revision-safety is the deployer's responsibility (see §5).

---

## 4. The legal facts the engine must encode (with dates/versions)

These drive hard software requirements. Cross-checked against the BMF E-Rechnung FAQ (**Stand: 23. März 2026** — verified), the BMF GoBD-Schreiben (28.11.2019, geändert 11.03.2024 und ergänzt **14.07.2025**), and KoSIT/xeinkauf.

### 4.1 E-Rechnung definition & formats
- An **E-Rechnung** is a **structured** electronic format that is transmitted and enables **electronic processing**, complying with **EN 16931** [10]. A **plain PDF is NOT** an e-invoice from **1 Jan 2025** — it counts as a "sonstige Rechnung" [10]. *(Confirmed verbatim against BMF FAQ, Stand 23.03.2026.)*
- Explicitly compliant: **XRechnung** (all versions), and **ZUGFeRD from version 2.0.1** — but the **MINIMUM and BASIC-WL profiles are NOT sufficient** for a VAT-valid (umsatzsteuerlich gültige) invoice [10]. The **BASIC** profile (note: *not* BASIC-WL) **is** sufficient, alongside EN16931/Comfort, EXTENDED and XRechnung [10]. → *Requirement: default ZUGFeRD profile must be EN16931 (Comfort) or higher; reject/forbid MINIMUM and BASIC-WL for legal invoices.*
- **XRechnung** current version: **3.0.2**; KoSIT shipped a **Bugfix Release Winter 2025/26 dated 2026-02-05** — Validator 1.6.0 (current Java) / 1.5.0 (Java 8), with **SeMoX models bundled for the first time** and a switch of the Schematron implementation from ISO Schematron to **SchXslt** [11] *(confirmed)*. UBL **and** CII syntaxes both exist for XRechnung. Public-sector invoices require a **Leitweg-ID** routing identifier (validated by BR-DE rules, alongside the Käuferreferenz/Buyer Reference) [11]. → *Requirement: target XRechnung 3.0.2; allow Leitweg-ID + Buyer Reference fields; validate against the current KoSIT/xeinkauf rules (re-test against the Feb 2026 validator release).*

### 4.2 Obligations & transition timeline (B2B, domestic) — confirmed against BMF FAQ (Stand 23.03.2026) & IHK
- **Receive:** every domestic business (B2B) must be able to **receive** e-invoices since **1 Jan 2025 — no transition, no exemption** [10][12].
- **Send (transition):**
  - **2025-2026:** the precedence of the paper invoice falls away, but any business may still send "sonstige Rechnungen" (paper/PDF) — PDF/other electronic formats only **with the recipient's consent** [10][12].
  - **Through end of 2027:** businesses whose **prior-year (2026) turnover was ≤ €800,000** may continue sending "sonstige Rechnungen"; the **EDI** procedure may also continue to be used (and may persist **beyond 2028** if it can extract EN-16931-conforming data) [10][12].
  - **From 1 Jan 2027:** businesses whose **2026 turnover exceeded €800,000** must send e-invoices [10][12].
  - **From 1 Jan 2028:** e-invoicing is **mandatory for all** domestic B2B senders, including those ≤ €800,000 [10][12].
- **Send exemptions:** **Kleinbetragsrechnungen ≤ €250 gross** (§ 33 UStDV), **Fahrausweise** (travel passes), and **Kleinunternehmer** outgoing invoices — these may stay PDF/paper even after 2028; but Kleinunternehmer (and everyone) must still be able to *receive* e-invoices [10][13]. → *Requirement: a per-tenant/per-document policy flag for "e-invoice required vs. allowed-other," driven by date, turnover, Kleinunternehmer status, and amount.*

> **Note vs. draft.** The draft's framing was accurate; the timeline above is tightened so the two distinct dates are unambiguous: **> €800,000 → from 2027**, **all (incl. ≤ €800,000) → from 2028**. The ≤ €800,000 cohort's transition therefore *runs through end-2027*.

### 4.3 Kleinunternehmer reform (from 2025) — confirmed (Wachstumschancengesetz / JStG 2024, § 19 UStG; BMF-Schreiben 2025-03-18)
- § 19 UStG was recast as a **Steuerbefreiung** (genuine tax exemption), not a mere non-collection. Net thresholds raised to **€25,000 prior year / €100,000 current year**; once **€100,000 is exceeded mid-year** the status ends **immediately** for the turnover exceeding the threshold ("Fallbeileffekt") [13]. A new **§ 19a UStG** adds an EU-wide Kleinunternehmer special procedure (cross-border). → *Requirement: model Kleinunternehmer status with the new net thresholds and the mid-year cut-off; suppress VAT line items and add the § 19 exemption note.*

### 4.4 Retention & numbering (GoBD)
- **Retention:** the Viertes Bürokratieentlastungsgesetz (**BEG IV**) cut **Buchungsbelege** retention from **10 → 8 years**, effective for documents whose former 10-year period had **not yet expired at the date the act took effect** (so documents from 2025+ are 8 years; pre-2025 documents already expired stay as they were) [14]. → *Requirement: retention clock = 8 years from end of the issue year for Buchungsbelege from 2025+ (10 for items whose 10-year clock had already run); expose as config, never auto-delete without explicit policy.*

  > **Important caveats (corrected/added vs. draft).** The 10→8-year cut is **specific to Buchungsbelege** (booking vouchers/invoices) — *not* a blanket cut of every GoBD retention category. Furthermore, a **2025 sector carve-out re-obligated banks, insurers and securities institutions to 10 years** (Gesetz zur Modernisierung und Digitalisierung der Schwarzarbeitsbekämpfung, Kabinettsbeschluss 2025-08-06). The engine's retention model must therefore be **per-document-category and overridable per tenant/industry**, defaulting to 8 years for ordinary businesses' Buchungsbelege.

- **Numbering:** invoices need a **unique, continuous (fortlaufend), gap-free** number; the BMF/GoBD framework and § 14 UStG require sequential, prompt numbering, and any gaps must be **documented/explicable** [15]. → *Requirement: per-document-type, per-tenant atomic sequence; no reuse; gap-detection/report; numbers assigned only at Festschreibung, never during draft.*

---

## 5. GoBD-Testat / IDW PS 880 — what an OSS library can and cannot claim

**IDW PS 880** ("Softwarebescheinigung") is a **voluntary** auditing standard from the Institut der Wirtschaftsprüfer (IDW). A Wirtschaftsprüfer examines whether a software product processes data "sicher, ordnungsgemäß, nachvollziehbar und revisionssicher" — i.e. meets GoBD criteria **from a software perspective only** [16] *(confirmed)*. Critical nuances for positioning:

- The audit is **independent of the end user**: user error, deployment, and configuration are explicitly **out of scope** [16].
- A Testat does **not** discharge the deploying company: the company must still keep a **Verfahrensdokumentation** (process documentation) and run GoBD-compliant operations and audit-proof storage [16][15].
- It is a **product certification commissioned and paid for by the manufacturer** — a quality mark, **not a legal requirement** [16].

**Implication for `gobd-invoice`.** A library is **not a deployed accounting system**, so it cannot itself hold a meaningful end-to-end GoBD-Testat — certification attaches to the *running application as configured at the customer*, and GoBD compliance is ultimately the **deploying company's responsibility**. The honest, defensible positioning:

- **Do NOT claim "GoBD-zertifiziert" or imply a Testat.** That would be misleading.
- **DO claim "GoBD-ready / GoBD-konforme Bausteine"**: the package implements the technical GoBD building blocks (Unveränderbarkeit/immutability, lückenlose fortlaufende Nummerierung, vollständige Protokollierung/audit trail, Nachvollziehbarkeit/traceability, Festschreibung/commit, export for Datenzugriff).
- **Ship a Verfahrensdokumentation template** describing the engine's data flows, hashing, and immutability model, so the deployer can fold it into *their* documentation — a concrete, differentiating deliverable.
- **Design to be testable:** clean immutability boundaries and audit logging make it realistic for a *deployer* to obtain IDW PS 880 for **their product** that embeds us. That is a sales argument ("Testat-fähig") without us over-claiming.

GoBD technical controls the engine must implement (mapped from the BMF GoBD wording on Unveränderbarkeit, which lists software measures as "Festschreibung, Löschmerker, automatische Protokollierung, Historisierungen, Versionierungen" [15] — *confirmed verbatim against the BMF GoBD-Schreiben*): append-only persistence after commit; soft-delete markers (Löschmerker), never hard deletes; full versioning/history; an automatic, immutable audit log of every state change; a content hash per finalized document; corrections only via **Storno + new document** (never silent edits), preserving both the original content and the fact of change [15].

---

## 6. Differentiation statement for `john-wink/gobd-invoice`

**Why it should exist.** As of mid-2026 there is a precise, unserved gap: the popular OSS Laravel invoice tools (laravel-invoices, Crater/InvoiceShelf) do **PDF or app UX but zero German legal compliance** [2][3][5]; the only widely used library that does German *structured e-invoice XML* (`horstoeko/zugferd`) is a **format codec, not a document/compliance engine** [6]; the tools that do full GoBD + e-invoice + DATEV are **closed commercial SaaS** [8][8a][9]; and the one stated closed-source library analogue (**Rechno**) is **proprietary and unobtainable**, and narrower in published document scope [1]. Nothing **open, embeddable, framework-native, and GoBD-aware** exists.

**Who it is for.** Laravel teams building German/DACH SaaS or internal business software who must issue legally valid documents and (from 2025/2027/2028) e-invoices, and who want the **legal/data engine** as a dependency rather than rebuilding it or buying into closed SaaS or AGPL apps.

**What it deliberately is and does:**
- A **framework-only backend engine** (no Filament, no bundled UI) — a composable dependency.
- A **polymorphic document core** spanning the full set: Rechnung, Angebot, Kostenvoranschlag, Beleg, Leistungsnachweis, Teilzahlung, Abschlags-/Schlussrechnung, Storno, Gutschrift, Mahnung — *broader than Rechno's published three (Rechnung, Angebot, Lieferschein)*.
- **GoBD building blocks built in:** immutability/Festschreibung, gap-free sequential numbering, content hashing, full audit trail, soft-delete markers (Löschmerker), versioning, and **per-category retention modeling** (default 8 years for Buchungsbelege post-2025; configurable for the bank/insurer 10-year carve-out) [14][15].
- **EN 16931 e-invoice output via a swappable adapter** over `horstoeko/zugferd` (XRechnung 3.0.2, ZUGFeRD ≥ 2.0.1 at EN16931/Extended profiles; UBL via the CII→UBL bridge where needed) [6][10][11].
- **DATEV export** and a **Verfahrensdokumentation template** as first-class, GoBD-supporting deliverables.
- **MIT-licensed**, to align with the horstoeko dependency and enable commercial embedding — beating the AGPL OSS tier on adoptability.

**What it deliberately does NOT do (anti-scope):**
- Not a UI/admin panel, not a SaaS, not a full bookkeeping ledger or double-entry accounting system (that is Firefly III's lane [4]).
- Not its own EN 16931 XML codec — it integrates horstoeko, never reimplements it [6].
- Not a payment processor, bank-reconciliation, or marketplace-integration tool.
- **Does not claim to be "GoBD-zertifiziert" / hold a Testat.** It provides Testat-*fähige* (certifiable) building blocks and explicitly assigns final GoBD/archiving responsibility and the IDW PS 880 certification of the *deployed product* to the deploying company [16].

In one line: **the open, MIT-licensed, framework-native German document & e-invoice engine that does the legal-hard parts (immutability, numbering, audit, EN 16931, DATEV) and nothing else — the trustworthy, inspectable counterpart to closed tools like Rechno and the compliance layer the existing OSS invoice tools never had.**

---

## Sources

[1] Rechno project page — Oliver Wycisk (oliver.software) — https://oliver.software/projects/rechno  *(returns HTTP 403 / "RESTRICTED"; Rechno-specific claims are maintainer-supplied and unverifiable as of 2026-06-25)*
[1a] Oliver Wycisk — Laravel Technical Lead (homepage, author identity) — https://oliver.software/
[2] Crater — Open Source Invoicing Solution (GitHub) — https://github.com/crater-invoice-inc/crater
[3] InvoiceShelf — Open Source Invoicing Solution; fork of Crater (GitHub) — https://github.com/InvoiceShelf/InvoiceShelf
[4] Firefly III — personal-finance / double-entry manager (GitHub) — https://github.com/firefly-iii/firefly-iii
[5] laraveldaily/laravel-invoices — PDF invoice generator (GitHub) — https://github.com/LaravelDaily/laravel-invoices
[5a] laraveldaily/laravel-invoices — package metadata (license, PHP/Laravel versions) — https://packagist.org/packages/laraveldaily/laravel-invoices
[6] horstoeko/zugferd — ZUGFeRD/XRechnung/Factur-X library (GitHub) — https://github.com/horstoeko/zugferd
[7] horstoeko/orderx — Order-X library (GitHub) — https://github.com/horstoeko/orderx
[7a] horstoeko/zugferdublbridge — ZUGFeRD/Factur-X (CII) ↔ PEPPOL UBL bridge (GitHub) — https://github.com/horstoeko/zugferdublbridge
[8] sevDesk — revisionssichere Archivierung / GoBD / DATEV / E-Rechnung — https://sevdesk.de/archivierungssoftware/
[8a] Lexware Office — GoBD-konform (Ernst & Young certification, reaudits); E-Rechnung XRechnung/ZUGFeRD — https://office.lexware.de/steuerberater/vorteile/gobd-konform/
[9] E-Rechnung Software Vergleich 2026 (Easybill / FastBill / Billomat / Papierkram) — https://e-rechnung-ratgeber.de/software-vergleich/
[9a] Easybill — E-Rechnung (ZUGFeRD/XRechnung), DATEV, Marktplatz-Integrationen — https://www.easybill.de/ratgeber/zugferd-der-neue-standard-fuer-die-europaeische-e-rechnung/
[10] BMF — FAQ zur verpflichtenden E-Rechnung ab 1.1.2025 (Stand: 23. März 2026) — https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
[11] XRechnung 3.0.2 / KoSIT Validator (Bugfix Release Winter 2025/26, 2026-02-05) / Leitweg-ID — https://xeinkauf.de/xrechnung/
[12] E-Rechnungspflicht ab 2025 — Fristen 2025/2027/2028 & Ausnahmen (IHK Frankfurt am Main) — https://www.frankfurt-main.ihk.de/recht/uebersicht-alle-rechtsthemen/steuerrecht/umsatzsteuer-national/e-rechnungspflicht-ab-2025-6055774
[13] BMF — Sonderregelung für Kleinunternehmer ab 2025 (§ 19 UStG, 25.000/100.000 EUR; BMF-Schreiben 2025-03-18) — https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-03-18-sonderregelung-kleinunternehmer.pdf
[14] Bürokratieentlastungsgesetz IV — Aufbewahrungsfrist Buchungsbelege 10→8 Jahre (Haufe) — https://www.haufe.de/finance/buchfuehrung-kontierung/buerokratieentlastungsgesetz-aufbewahrungspflichten-verkuerzt_186_634670.html
[15] BMF GoBD-Schreiben (Unveränderbarkeit: Festschreibung/Löschmerker/Protokollierung/Historisierung/Versionierung; fortlaufende Nummerierung; Verfahrensdokumentation; geändert 11.03.2024, ergänzt 14.07.2025) — https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.pdf
[16] IDW PS 880 — Softwarebescheinigung / GoBD-Testat (d.velop, Doxis/SER) — https://www.d-velop.de/blog/compliance/idw-ps-880/

---

## Open Questions

1. **Rechno is unverifiable.** The project page is access-restricted (HTTP 403) and unindexed; its exact data model, hashing scheme, document-type breadth, start date, and "Active/Deployed" status are inferred from maintainer-relayed marketing copy and cannot be confirmed. Only the author's identity (Oliver Wycisk, Laravel consultant) is independently verifiable.
2. **UBL coverage.** Does `horstoeko/zugferd` + `zugferdublbridge` (CII→PEPPOL-UBL) fully satisfy XRechnung 3.0.2 **UBL** syntax against the **2026-02-05 KoSIT validator** (Validator 1.6.0, SeMoX, SchXslt), or is a secondary native-UBL path needed? Needs a hands-on validation test.
3. **DATEV export contract.** The exact DATEV target (EXTF / "DATEV-Format" booking export vs. document/Belegbilder export, and the required field set/format version) is not yet pinned to an official DATEV spec version.
4. **IDW PS 880 boundary for an embedded library.** Whether a deployer can realistically obtain IDW PS 880 for a product embedding a general-purpose library — and what audit boundary the engine must expose to make that feasible — is unconfirmed with an actual Wirtschaftsprüfer.
5. **Edge document types vs. EN 16931.** Treatment of document types outside EN 16931 invoice semantics (Mahnung, Angebot, Lieferschein, Kostenvoranschlag) for any structured-format output — these are typically PDF-only; confirm none require structured e-invoice transmission. (Order documents would map to the separate Order-X / `horstoeko/orderx` standard, not the invoice profiles.)
6. **Retention category modeling.** Confirm the precise list of document categories subject to the BEG IV 8-year Buchungsbelege cut vs. those that remain 10 years (e.g. the 2025 bank/insurer/securities-institute carve-out), and whether any of the engine's document types fall under the longer regime by default.
7. **Lexware "pure XRechnung-UBL" gap.** The claim that Lexware Office is weaker on pure XRechnung-UBL export was only weakly corroborated by third-party reviews; it should be re-confirmed against Lexware's current feature matrix before relying on it competitively.
