# gobd-invoice — Architecture & Roadmap Synthesis

> **Role of this document.** This is the lead-architect synthesis that ties together the nine verified research notes (01–09) into one actionable plan. It is the single entry point for building `john-wink/gobd-invoice`: a **framework-only** (no Filament, no UI), **MIT-licensed**, GoBD-compliant + EN 16931 e-invoice **document engine** for Laravel 13 / PHP 8.4+. Source documents are cited inline as `[NN]` (e.g. `[01]` = `01-gobd-compliance.md`). Where the underlying notes carry a `[verified]` flag or a §-reference, that authority flows through here.
>
> **Currency posture (mid-2026).** German bookkeeping and e-invoicing law moved repeatedly in 2024–2026 and is still moving. Every threshold, deadline, retention period, §-reference, and format version below is **data, never a hard-coded constant** — the 10→8-year retention cut and its partial financial-sector reversal are the proof of why. Re-verify against the cited primary sources at every release.

---

## 1. Prioritized compliance-requirements checklist

Each item is a single MUST/SHOULD the package enforces, tagged with its source doc(s) and the load-bearing legal anchor. Ordered by priority tier: **P0** = GoBD/legal-correctness load-bearing (a defect here makes output non-compliant or creates tax liability); **P1** = mandatory-but-bounded; **P2** = important-for-market-parity / SHOULD.

### P0 — Correctness-critical (must ship for any "GoBD-compliant" claim)

1. **MUST make finalized documents immutable (Unveränderbarkeit).** Distinct lifecycle `Entwurf → finalisiert/festgeschrieben`; on finalization the legal content is append-only with no UPDATE/DELETE of tax-relevant columns (model `saving` guard + DB-level intent). Anchored in § 146 Abs. 4 AO / § 239 Abs. 3 HGB, GoBD Rz 58–60, 107–112. `[01][04][05][08]`
2. **MUST correct/cancel only via a new linked document (Storno statt Löschen).** Never physically delete or silently overwrite a finalized record; cancellation emits a Stornorechnung (negated lines, own new number, `references_invoice_id`); correction emits a berichtigte Rechnung referencing the original by number + date. `[01][05][08]`
3. **MUST keep a complete, append-only audit log: who / what / when / old→new.** Every state transition (draft→finalized→sent→paid/overdue→cancelled/corrected), every config/parameter change, and every archival event appends a row; the log is insert-only and SHOULD be hash-chained (`previous_hash`) for tamper evidence. Retain it at least as long as the longest document retention it describes. GoBD Rz 59. `[01][04][08]`
4. **MUST assign a unique invoice number at finalization, never reused, never assigned in draft.** Uniqueness per issuer is the hard legal requirement (§ 14 Abs. 4 Nr. 4 UStG); strict gaplessness is **not** legally mandated but is advisable and gaps must be explicable in the Verfahrensdokumentation. Numbering is race-safe via a `lockForUpdate()` sequence row keyed by `(document_type, series, year)`. `[02][05][08]`
5. **MUST validate § 14 Abs. 4 UStG mandatory content (Nr. 1–10) before finalize**, with conditional field sets per document class: full §14 set; Kleinbetragsrechnung reduced set (gross ≤ €250, § 33 UStDV); Kleinunternehmer reduced set (§ 34a UStDV); reverse-charge (§ 14a Abs. 5). The reverse-charge note lives in §14a, **not** §14 Abs. 4. `[02][05]`
6. **MUST always populate and render the Leistungs-/Lieferdatum (§ 14 Abs. 4 Nr. 6)** even when equal to the issue date (then render "Leistungsdatum entspricht Rechnungsdatum"); never silently omit. A calendar month granularity is acceptable. `[02][05]`
7. **MUST compute VAT per `(category, rate)` group, rounding exactly twice** — once per line net (BT-131) and once per VAT-group amount (BT-117) — then only *add* already-rounded values for all totals (no re-rounding of sums, per EN 16931 / Peppol BIS). Kaufmännische Rundung (half-away-from-zero) to 2 decimals; EN 16931 validation has **zero rounding tolerance**, so the stored breakdown must reconcile forever. `[06]`
8. **MUST never represent money as float.** Integer minor units / BCMath / `brick/math`; `Money = {minor amount, ISO-4217 currency}`; persist as `BIGINT` minor-unit or `DECIMAL(15,4)` unit / `DECIMAL(15,2)` booked columns, never `FLOAT`/`DOUBLE`. Store `price_mode` (net|gross) and the authored figure verbatim (it changes the cent result and is locked into the immutable document). `[06][08]`
9. **MUST enforce the Schlussrechnung advance-deduction gate.** A final invoice tied to prior Abschlags-/Anzahlungsrechnungen cannot finalize unless each prior **net AND its VAT** is deducted and referenced by number + date (§ 14 Abs. 5 Satz 2 UStG); otherwise VAT is owed twice under § 14c Abs. 1. `[05]`
10. **MUST emit all §§14/14a mandatory particulars inside the structured XML** for e-invoices — never rely on PDF text, embedded attachments, or external links for legal content (BMF 15.10.2025). The structured part is the leading record; the PDF is decoration. `[01][03][06]`
11. **MUST emit EN 16931-conformant structured XML and hard-block invalid profiles.** Default profile **EN 16931 (COMFORT)**; allow EXTENDED and XRECHNUNG profiles; **refuse to emit ZUGFeRD MINIMUM and BASIC-WL** as legal invoices (BASIC and above are valid per BMF FAQ). `[01][03][05][06][07]`
12. **MUST preserve the original structured payload byte-for-byte and immutably** (the EN 16931 XML, or the XML extracted byte-exact from a ZUGFeRD/Factur-X PDF/A-3 container) for the full retention period; no lossy conversion that destroys machine-evaluability; if normalized into an in-house format, keep **both** original and converted, linked. `[01][03][04]`
13. **MUST stamp each document with a `retention_class` + computed `retention_until`** driven by document class + issue/receipt-year-end + tenant sector flag — **8 years** for Buchungsbelege/Rechnungen (§ 147 Abs. 3 / § 14b Abs. 1 UStG / § 257 Abs. 4 HGB, for docs not expired at 31.12.2024), **10 years permanently** for financial-sector tenants (SchwarzArbMoDiG reversal), **10 years** for books/journals/inventories/financial statements/customs, **6 years** for correspondence (e.g. Lieferschein). Clock starts at end of the relevant calendar year (§ 147 Abs. 4 AO). `[01][04][05]`
14. **MUST never auto-delete and MUST block hard-delete before `retention_until`** (and while an Ablaufhemmung is active — § 147 Abs. 3 S. 5 AO; the period does not end while a Festsetzungsfrist runs or an audit is open). `retention_until` is a floor; disposal is an explicit, logged operator action. `[01][04]`
15. **MUST reserve the literal label "Gutschrift" strictly for self-billing** (§ 14 Abs. 2 S. 4 / § 14 Abs. 4 Nr. 10 — recipient issues the invoice, prior-agreement flag required). Credit/correction documents use "Rechnungskorrektur"/"Stornorechnung" — a guarded, type-driven decision, never free text. `[02][05][08]`

### P1 — Mandatory but bounded

16. **MUST model the Kleinunternehmer (§ 19 UStG) regime:** thresholds €25,000 prior-year / €100,000 current-year (hard cap, net), mid-year "Fallbeil" flip on crossing €100,000 (the breaching transaction and all after it are taxed), genuine Steuerbefreiung (no VAT line), mandatory §19/§34a exemption note, and `E`-category EN 16931 lines. Kleinunternehmer are exempt from *issuing* e-invoices but **must support receiving**. `[02][03][05][06]`
17. **MUST gate e-invoice issuance obligation** by date + recipient type (domestic B2B) + issuer prior-year turnover (>€800,000 → 2027; all → 2028) + amount (>€250 gross) + Kleinunternehmer status; track recipient consent for the *sonstige Rechnung* fallback window (2025–2027). § 27 Abs. 38 UStG. `[01][02][03][05][07]`
18. **MUST ship a receive/parse path** ingesting XRechnung (UBL + CII) and ZUGFeRD, extracting and validating the embedded XML and mapping to the domain model — required since 01.01.2025 for all, including Kleinunternehmer. `[01][03][08]`
19. **MUST run pre-emission validation (XSD + Schematron / KoSIT) that fails closed** and reports the three BMF error categories (format / business-rule / content), distinguishing VAT-relevant vs non-VAT-relevant business-rule errors. `[03][06]`
20. **MUST treat Anzahlungs-/Vorauszahlungsrechnung VAT as due on payment receipt** (§ 13 Abs. 1 Nr. 1 lit. a S. 4 UStG, Mindest-Ist-Versteuerung); record the actual receipt date and feed it into the Schlussrechnung deduction. `[05]`
21. **MUST store the VAT rate AND the EN 16931 category code per line.** Standard 19% and reduced 7% are both UNCL5305 category `S` (rate in BT-119); `Z`/`E`/`K`/`G`/`AE`/`O` all show 0 tax but differ legally and in mandatory text. `(category, rate)` is the breakdown grouping key. `[06][08]`
22. **MUST key the VAT rate table by service category + effective date** (so the 1 Jan 2026 Gastronomie 19%→7% change is data, not code); never hard-code 19/7 as the only options. `[02][05][06]`
23. **MUST provide a Z3 / Datenüberlassung export** in the GDPdU/GoBD Beschreibungsstandard (`index.xml` + `gdpdu-01-08-2002.dtd` + CSV/ASCII payloads) covering documents, lines, tax breakdowns, partners and links to archived originals, scoped by date range; keep it driver-based for a future DSFinV-BV profile (~2027/2028, not yet in force). `[01][04]`
24. **MUST provide a Z1/Z2 read-only audit query surface** (filterable by Zeitraum, Belegart, Steuersatz, Geschäftspartner), Z2 running on pre-existing evaluation logic only (no promise of on-the-fly custom programming). `[04]`
25. **MUST implement IKS hooks: role/permission gates + segregation of duties** for create vs finalize vs cancel vs export; input validation/plausibility on capture; reconciliation hooks; and log the controls themselves (GoBD Rz 100–102). `[01]`
26. **SHOULD content-hash finalized documents (SHA-256) and hash-chain the audit log.** Strong recommended GoBD control (one example among Festschreibung/Löschmerker/Protokollierung/Historisierung/Versionierung), **not** a stand-alone legal mandate — except where cash registers pull in KassenSichV/TSE (§ 146a AO), which is out of scope. `[01][04][07]`

### P2 — Market-parity & document-breadth (SHOULD)

27. **SHOULD ship a versioned Verfahrensdokumentation template** tied to the engine's actual finalization/numbering/logging/retention/export behaviour, with its own change history (GoBD Rz 151–153, 34). This is also a differentiating deliverable and the basis for a deployer's IDW PS 880 Testat. `[01][07]`
28. **SHOULD ship a DATEV export** (EXTF / "DATEV-Format" booking + document export) — a discrete, well-scoped deliverable none of the OSS competitors provide. `[07]`
29. **SHOULD model the full document taxonomy** beyond invoices: Angebot (§ 145 BGB bindingness), Kostenvoranschlag (§ 649 BGB overrun + §649 Abs. 2 notification, ~15–20% configurable tolerance), Auftragsbestätigung, Lieferschein, Leistungsnachweis/Stundennachweis (§ 15 VOB/B 6-Werktage Anerkennungsfiktion), Teilzahlung (payment event, not a document), Mahnung — broader than Rechno's published three. `[05][07]`
30. **SHOULD implement Mahnung/Verzug correctly:** dated Basiszinssatz table (1.27% from 1 Jan 2026; re-verify 1 Jul 2026), +5pp consumer / +9pp B2B (§ 288), €40 B2B Pauschale (§ 288 Abs. 5), § 286 Abs. 3 30-day fallback gated on a stored consumer-warning flag. Dunning is a business process, kept out of the immutable tax record. `[05][08]`
31. **SHOULD treat Skonto as payment-terms metadata (BT-20), not an issuance-time deduction**; the §17 base/VAT correction happens only on actual Inanspruchnahme, via a later append-only correction document. Rabatt/Zuschlag fold into the correct per-group breakdown (line or document level). `[06]`
32. **SHOULD support multi-currency from day one** (currency-bearing Money; throw on mixed-currency arithmetic) and, when BT-5 ≠ EUR, compute and store the EUR VAT total (BT-111) using § 16 Abs. 6 UStG BMF monthly average rates, storing the rate for reproducibility. `[06]`
33. **SHOULD position honestly: "GoBD-ready / Testat-fähig", never "GoBD-zertifiziert".** A library is not a deployed system; certification (IDW PS 880) attaches to the deployer's running, configured product. Document the storage/WORM and final-compliance boundary as the deployer's responsibility. `[07]`
34. **SHOULD document the scope boundary: no TSE/DSFinV-K/KassenSichV inside the package** — it is not a Kassensystem; cash-register fiscalisation belongs to a dedicated POS layer. `[04]`

---

## 2. Conflicts & overlaps between documents to resolve

These are points where the notes diverge, overlap, or leave a decision open that the architecture must settle explicitly.

1. **"Gapless" numbering — legal nuance vs. internal wording.** `[02]` is precise: § 14 Abs. 4 Nr. 4 requires **uniqueness only**, *not* strict gaplessness; gaps are tolerated if explicable. `[05][07][08]` repeatedly use the looser shorthand "gap-free / lückenlos fortlaufend". **Resolution:** treat **uniqueness** as the hard invariant; ship a `gapless` strategy as the GoBD-*safest default* (number assigned only at finalize inside the hashing transaction, so discarded drafts consume nothing), but document that gaplessness is a defensive default and not a statutory precondition, and provide gap detection/reporting rather than gap *prevention* guarantees.

2. **ZUGFeRD/Factur-X default version is unsettled across the docs.** `[01][05]` say "≥ 2.0.1, exclude MINIMUM/BASIC-WL"; `[03]` tracks 2.3 → 2.4 (15.01.2026) → 2.5 (~June 2026) and says default to a current 2.4/2.5 release; `[08]` pins "2.4 / Factur-X 1.08, in force 15 Jan 2026" as current. **Resolution:** make the emitted version **configurable (`einvoice.zugferd_version`)**, default to whatever current published release `horstoeko/zugferd` bundles at build time (2.4/2.5 family), and pin nothing to the now-withdrawn 2.3. Confirm the installed library's profile coverage at release.

3. **XRechnung normative version & bundle dates.** `[03]` says normative is 3.0.2, with a "Winter 2025/26" bundle effective 31.01.2026 and XRechnung 4.0 (EN 16931-1:2026) announced for mid/late 2026 but not yet normative. `[07]` cites the Bugfix Release Winter 2025/26 dated 2026-02-05 (Validator 1.6.0, SeMoX, SchXslt). `[08]` says current published standard is 3.0.2. **Resolution:** no real contradiction — target **3.0.2**, keep the target version configurable, and design for a time-limited 3.0.2 → 4.0 handover window under KoSIT's one-release-per-year policy. Pin the validator config bundle separately from the CIUS version.

4. **Financial-sector retention: a hard correction between docs.** `[08]` (older) says "credit/finance institutions get the change one year later" (the obsolete BEG IV reading). `[04]` and `[07]` carry the **authoritative correction**: SchwarzArbMoDiG (Bundestag 13.11.2025) **permanently reverted** the financial sector to **10 years** — not "+1 year then 8". **Resolution:** implement the financial-sector flag as a **permanent 10-year carve-out for all document dates**; explicitly do **not** model "+1 year then 8". `[08]`'s sentence is superseded.

5. **§16(6) UStG / DIN 1333 citation corrections (already reconciled in `[06]`).** `[06]` corrects an earlier draft that mis-attributed the 2-decimal rule to §16(6) UStG and the rounding method to DIN 1333. **Resolution (carry forward):** the 2-decimal rule is administrative/standard practice + EN 16931; kaufmännische Rundung (half-away-from-zero) is the method; §16(6) UStG governs **only** foreign-currency conversion. Code comments and docs must use these corrected pinpoints.

6. **TaxCategory enum had two real bugs (fixed in `[08]`).** The corrected enum: 7% reduced is category **`S`** (rate carried in BT-119, no distinct "reduced" code; `AA` is invalid), and Kleinunternehmer/Exempt are both **`E`** (a single backed value — duplicate backed-enum values are a PHP fatal error). **Resolution:** ship the corrected enum from `[08]`; `[06]`'s category table is consistent with it.

7. **Reverse-charge note placement.** `[02]` corrects a draft that split the reverse-charge note out as "§14 Abs. 4 Nr. 9" — it actually lives in **§ 14a Abs. 5**; §14 Abs. 4 has exactly Nr. 1–10 (Nr. 9 = retention note, Nr. 10 = "Gutschrift"). `[06]` and `[08]` are consistent. **Resolution:** model the reverse-charge note as a §14a-driven addition (category `AE`, BT-120 reason text), separate from the §14 Abs. 4 field set.

8. **§14 six-month deadline vs. §14a 15th-of-following-month deadline.** `[02]` flags the overlap (a domestic B2B supply that is also an innergemeinschaftliche Lieferung) and leaves precedence as an open question (stricter §14a Abs. 3 likely wins). **Resolution:** compute both applicable deadlines per document and surface the **stricter** as the warning; do not encode a single blanket "6 months" rule.

9. **Z3 export format scope overlap.** `[01]` describes Z3 generically ("documented, machine-evaluable format, CSV/structured"); `[04]` is concrete (GDPdU/GoBD Beschreibungsstandard: `index.xml` + DTD + CSV/ASCII) and notes the future DSFinV-BV. **Resolution:** `[04]` governs the concrete deliverable; build the GDPdU profile as the only mandatory one in mid-2026, behind a driver interface that admits a `dsfinv-bv` profile later.

10. **horstoeko/zugferd is CII-only — a structural gap, not a conflict, but called out in three docs.** `[03][07][08]` all flag that UBL output (XRechnung-UBL, Peppol BIS) requires routing through `horstoeko/zugferdublbridge` (CII→UBL) and that bridge output must be validated against the KoSIT UBL/Peppol Schematron. **Resolution:** the `EInvoiceFormatDriver` adapter is **format-agnostic**; the UBL path is a distinct, separately tested driver; budget a possible native-UBL serializer if the bridge fails KoSIT validation for any document type.

---

## 3. Gaps / topics still missing for a 1.0

Open items the research did not fully close, plus engineering topics no doc owns end-to-end. These should be tracked as 1.0 blockers or explicit deferrals.

**Legal / spec gaps to confirm at build time (from the docs' Open Questions):**
- **§19 mid-year "Fallbeil" invoicing mechanics** — whether the crossing transaction must be split and exactly how to word the first taxed invoice (BMF letter 18.03.2025). `[02][08]`
- **Leistungsdatum in EN 16931** — which BT carries the supply date (BT-72 vs BG-14) and whether a structured field alone satisfies §14 Abs. 4 Nr. 6 or a BT-22 note is still needed. `[02]`
- **KU-IdNr. (`DE…EX`) exact numeric structure** and whether to validate syntactically vs via a BZSt check. `[02]`
- **Skonto BT-20 encoding** — the `#SKONTO#TAGE=..#PROZENT=..#` convention is a KoSIT/forum convention, not codified in EN 16931; confirm current CIUS syntax. `[06]`
- **§12(3) PV/Nullsatz → `Z` vs `E`** category mapping against the current KoSIT validator. `[06]`
- **Exact EGAO transition citation** (Art. 97 §19b vs §19a Abs. 2) and the final SchwarzArbMoDiG statutory wording / in-force date for the permanent financial-sector carve-out. `[04]`
- **Retroactivity of invoice correction** grounded in EuGH Senatex/Barlis case law (not §31 Abs. 5 UStDV text) — confirm the current BFH line on which corrections qualify. `[05]`
- **§286 Abs. 3 consumer-warning wording** the package must print to trigger the automatic 30-day default. `[05]`
- **Basiszinssatz** for periods after 1 Jul 2026 (sourced from an updatable table). `[05]`

**Engineering / product gaps not fully owned by any single doc:**
- **Host-app linkage** from `gobd_documents` to the host's customer/order models — polymorphic `morphTo` vs configurable FK. Must be decided **before** shipping migrations (affects schema). `[08]`
- **PDF/A-3 conformance level** (3b vs 3a) and whether dompdf can produce valid PDF/A-3 without Gotenberg/Ghostscript post-processing — needs empirical veraPDF/KoSIT validation; 3b is the realistic default. `[08]`
- **KoSIT validator packaging** — bundle the Java validator (needs a JRE in the image) vs a pure-PHP Schematron fallback (may diverge from official verdicts). `[03]`
- **`lockForUpdate` is a no-op on SQLite** — the gapless-numbering concurrency guarantee must be proven on MySQL/Postgres in CI, not only the in-memory SQLite suite. `[08][09]`
- **DATEV export contract** — exact EXTF / "DATEV-Format" field set and version not yet pinned to an official DATEV spec. `[07]`
- **IDW PS 880 audit boundary** for an embedded library — what surface the engine must expose so a *deployer* can certify their product; unconfirmed with an actual Wirtschaftsprüfer. `[07]`
- **Vertikale vs horizontale rounding method as a user option** — both tax-accepted, produce different cents, and the choice is locked into the immutable document; decide the default and whether to expose it. `[06]`
- **ViDA / B2B transaction-reporting (Meldesystem)** — not yet law; decide whether to leave export hooks. `[01][03][05]`
- **DSFinV-BV future profile** (~2027/2028) — keep the Z3 export layer pluggable. `[04]`

---

## 4. Recommended high-level package architecture (consistent with `[08]`)

### Top-level namespaces (`JohnWink\GobdInvoice\…`)

```
src/
  GobdInvoiceServiceProvider.php        // spatie/laravel-package-tools PackageServiceProvider
  Facades/        GobdInvoice
  Contracts/      InvoiceDocument, DocumentTypeDriver, EInvoiceFormatDriver,
                  PdfRenderer, NumberSequenceGenerator, TaxStrategy, AuditLogger
  Enums/          DocumentType, DocumentStatus, TaxCategory, EInvoiceFormat,
                  UnitOfMeasure, DunningLevel, RetentionClass
  Data/           InvoiceData, LineItemData, PartyData, TaxBreakdownData,
                  PaymentTermsData            // spatie/laravel-data v4 (base class: Data)
  ValueObjects/   Money, TaxRate, DocumentNumber, VatId, Leitweg
  Models/         Document, DocumentLine, NumberSequence, AuditLogEntry
  Documents/      Drivers/{RechnungDriver, AngebotDriver, KostenvoranschlagDriver,
                  AbschlagsrechnungDriver, SchlussrechnungDriver, StornoDriver,
                  GutschriftDriver, MahnungDriver, LeistungsnachweisDriver, …}
  EInvoice/       Formats/{XRechnungFormat, ZugferdFormat, FacturXFormat},
                  Mapping/En16931Mapper, Validation/KositValidator
  Pdf/            Renderers/{DompdfRenderer, GotenbergRenderer, TypstRenderer}, PdfA3Merger
  Numbering/      LockingSequenceGenerator
  Audit/          ContentHasher, AppendOnlyAuditLogger
  Retention/      RetentionPolicy, DeletionEligibilityQuery
  Export/         Z3/{GdpduExporter}, Datev/{ExtfExporter}
  Pipelines/      FinalizationPipeline, FinalizeStages/{AssignNumber, ComputeTax,
                  SnapshotPayload, HashContent, PersistAudit, GenerateEInvoice}
  Events/         DocumentDrafted, DocumentFinalized, DocumentCancelled,
                  EInvoiceGenerated, DocumentSent, PaymentRecorded, DunningIssued
  Exceptions/
```

### Core contracts / interfaces

- **`InvoiceDocument`** — the swappable model contract (host apps bind their own class via `config('gobd-invoice.models.document')`, the laravel-permission pattern).
- **`DocumentTypeDriver`** — one per `DocumentType` case; declares `type()`, `series()`, `isImmutable()`, `canEmitEInvoice()`, `allowedTransitions(): DocumentStatus[]`, `buildInvoiceData(Document): InvoiceData`. Resolved via a `DocumentTypeManager` (Laravel `Manager`).
- **`EInvoiceFormatDriver`** — `format()`, `generate(InvoiceData): string` (XML), `embedInPdf(xml, pdfBytes): string` (PDF/A-3 hybrid), `validate(xml): ValidationResult` (KoSIT). Resolved via `EInvoiceFormatManager`; **format-agnostic** so the CII-only horstoeko path and a UBL/bridge path coexist.
- **`PdfRenderer`** — `render(Document, theme): string`; resolved via `PdfRendererManager` (dompdf / gotenberg / typst).
- **`NumberSequenceGenerator`** — `next(DocumentType, series, year): DocumentNumber`; default `LockingSequenceGenerator` (pessimistic `lockForUpdate()` inside `DB::transaction`).
- **`TaxStrategy`** — `categorize(Document): TaxBreakdownData`; encapsulates Regelbesteuerung vs Kleinunternehmer mid-year flip, per-group rounding, reverse-charge.
- **`AuditLogger`** — `append(Document, event, diff): AuditLogEntry`; append-only, hash-chained.

### Persisted data model (main tables)

| Table | Key columns (load-bearing) |
|---|---|
| `gobd_documents` | `id`, `type`, `status`, `series`, `number` (null until finalize), `issue_date`, `performance_date`, `price_mode`, `currency`, totals (`net_minor`, `vat_minor`, `gross_minor`), `finalized_at`, `content_hash`, `previous_document_hash`, `finalized_payload` (JSON snapshot), `references_document_id` (Storno/Korrektur/Schlussrechnung chain), `source_document_id` (conversion chain), `retention_class`, `retention_starts_at`, `retention_until`, `is_financial_sector`, `ablaufhemmung_active`, `is_immutable`, `original_archive_hash`, host-link (`documentable_type`/`documentable_id` or configurable FK — **open decision**) |
| `gobd_document_lines` | `id`, `document_id`, `position`, `description`, `quantity`, `unit`, `unit_price_minor`, `net_minor` (BT-131, rounded), `vat_rate`, `vat_category` (UNCL5305), line allowances/charges |
| `gobd_number_sequences` | `(document_type, series, year)` unique, `current_value` — row-locked at allocation |
| `gobd_audit_log` | insert-only: `id`, `document_id`, `actor`, `occurred_at` (UTC), `event`, `before`/`after` (or hashes), `content_hash`, `previous_hash` (tamper-evident chain), `reason` |

Migrations ship as anonymous-class `*.php.stub`, publish-only by default (host owns schema). VAT breakdown rows (`BG-23`: base/rate/tax per group) are derived from the snapshot for export; the **structured XML and PDF/A-3 originals** are persisted byte-exact in a write-once archive store with `archived_at` + SHA-256.

### Public facade API surface

```php
GobdInvoice::draft(DocumentType $type, InvoiceData $data): Document;          // create draft
GobdInvoice::finalize(Document $doc): Document;                               // number, snapshot, hash, audit, validate
GobdInvoice::cancel(Document $doc, string $reason): Document;                 // linked Storno
GobdInvoice::correct(Document $doc, InvoiceData $changes): Document;          // berichtigte Rechnung / Storno+new
GobdInvoice::eInvoice(Document $doc, ?EInvoiceFormat $fmt = null): EInvoiceResult; // XML / hybrid PDF
GobdInvoice::pdf(Document $doc, ?string $theme = null): string;              // PDF/A-3 bytes
GobdInvoice::ingest(string $xmlOrPdf): Document;                              // receive/parse path
GobdInvoice::verify(Document $doc): bool;                                     // re-hash + chain check
GobdInvoice::export()->z3(DateRange $range): string;                         // GDPdU/GoBD Beschreibungsstandard
GobdInvoice::export()->datev(DateRange $range): string;                      // EXTF
GobdInvoice::format(EInvoiceFormat $f): EInvoiceFormatDriver;
GobdInvoice::renderer(?string $name = null): PdfRenderer;
```

### Dependency posture (from `[07][08][09]`)

- **Require:** `php ^8.4`, `illuminate/contracts ^13.0` (not `laravel/framework`), `spatie/laravel-package-tools`, `spatie/laravel-data v4`. **MIT** license (aligns with horstoeko, enables commercial embedding, beats the AGPL OSS tier).
- **Suggest (lazy, not require):** `horstoeko/zugferd` (CII), `horstoeko/zugferdublbridge` (UBL), PDF renderer libs, Gotenberg client — so the core stays slim/framework-only.
- **Do not** reimplement EN 16931 XML — wrap horstoeko behind the adapter.

---

## 5. Phased ~8-month roadmap to a Laracon-ready, GoBD-complete 1.0

Eight milestones (M0–M7). Each names what it delivers, the checklist items it lands (§1 numbering), and its key risks. The order is dependency-driven: domain + numbering + immutability are the load-bearing foundation; e-invoicing and audit/export build on top; hardening/talk last.

### M0 — Scaffold & quality gates (≈ weeks 1–3)
- **Delivers:** repo on the spatie skeleton, paragon tooling from `[09]` (PHPStan max baseline-free, Rector dry-run gate, Pint `--test` gate, Pest 4 with type-coverage `--min=100` + mutation `--min=90`, Testbench/Workbench, CI matrix PHP 8.4/8.5 × Laravel 13 × prefer-lowest/-stable). Service provider, config skeleton, swappable-model binding, anonymous-class migration stubs, enums (`DocumentType`, `DocumentStatus`, corrected `TaxCategory`, `RetentionClass`). MIT license + community files + Verfahrensdokumentation skeleton.
- **Lands:** infra for all later items; corrected TaxCategory enum (conflict #6).
- **Risks:** over-investing in tooling before domain exists; widening the support matrix (Larastan's real Laravel floor is 11.44.2); deciding the host-app linkage (`morphTo` vs FK) late — settle it here because it shapes the migrations.

### M1 — Core domain & numbering (≈ weeks 3–7)
- **Delivers:** `Document`/`DocumentLine`/`NumberSequence`/`AuditLogEntry` models; value objects (`Money`, `DocumentNumber`, `VatId`, `Leitweg`); `DocumentTypeDriver` framework + drivers for the non-tax types (Angebot, Kostenvoranschlag, Auftragsbestätigung, Lieferschein, Leistungsnachweis); the race-safe `LockingSequenceGenerator`; the document state machine and conversion chain (`source_document_id`).
- **Lands:** #4 (numbering), #8 (no-float Money), part of #29 (document taxonomy), conflict #1 (uniqueness vs gaplessness).
- **Risks:** the `lockForUpdate` SQLite no-op — concurrency must be proven on MySQL/Postgres in CI, not just SQLite; separating the three orthogonal axes (civil bindingness / VAT relevance / immutability) instead of one conflated `type` enum.

### M2 — VAT / totals engine (≈ weeks 7–11)
- **Delivers:** `TaxStrategy`, `TaxRate`/`TaxBreakdown`, the per-`(category,rate)` group algorithm with the exactly-twice rounding discipline; net/gross price modes; Rabatt/Zuschlag (line + doc level); Skonto as BT-20 metadata + §17 deferred correction; effective-date-keyed rate table (incl. 1 Jan 2026 Gastronomie 7%); Kleinunternehmer mode with the mid-year €100k Fallbeil flip; multi-currency Money + BT-111 EUR VAT.
- **Lands:** #7, #16, #20, #21, #22, #31, #32, conflict #5.
- **Risks:** EN 16931's zero rounding tolerance — a single mis-placed round breaks validation forever (this is where mutation testing earns its keep); the §19 crossing-transaction mechanics are an unconfirmed open question (BMF 18.03.2025); vertikale-vs-horizontale default choice locks into the immutable document.

### M3 — Documents, lifecycle & immutability (≈ weeks 11–15)
- **Delivers:** the `FinalizationPipeline` (AssignNumber → ComputeTax → SnapshotPayload → HashContent → PersistAudit); immutability guards (model `saving` rejection after `finalized_at`); content hashing + audit hash-chain (`AppendOnlyAuditLogger`); §14 Abs. 4 / §33 / §34a / §14a validation; the tax-document drivers (Rechnung, Abschlags-/Anzahlungs-/Schlussrechnung, Storno, Gutschrift); the Schlussrechnung advance-deduction gate; cancel/correct semantics; Gutschrift terminology guard; Mahnung/Verzug engine.
- **Lands:** #1, #2, #3, #5, #6, #9, #15, #26, #30, conflicts #7, #11 (validation order).
- **Risks:** the Schlussrechnung double-VAT (§14c) gate is subtle and litigated — must block finalize, not warn; getting the immutability boundary exactly right (which columns are frozen); correction retroactivity rests on EuGH/BFH case law, not statute (open question).

### M4 — PDF rendering (≈ weeks 15–18)
- **Delivers:** `PdfRenderer` drivers (dompdf default, Gotenberg, Typst), Blade PDF themes + localization (de/en), `PdfA3Merger`, per-document/config theme selection.
- **Lands:** the human-readable layer feeding M5's hybrid container.
- **Risks:** producing **valid** PDF/A-3 — dompdf likely needs Gotenberg/Ghostscript post-processing; 3b vs 3a conformance is an open question requiring veraPDF validation; keep rendering pluggable and never let the PDF carry legal content the XML lacks (#10).

### M5 — E-invoicing: ZUGFeRD / XRechnung (≈ weeks 18–23)
- **Delivers:** `En16931Mapper` (DTO → BT/BG); `EInvoiceFormatDriver` adapters over `horstoeko/zugferd` (ZUGFeRD/Factur-X CII, hybrid PDF/A-3 embed as `factur-x.xml`), XRechnung-CII, and XRechnung-UBL/Peppol via `zugferdublbridge`; KoSIT/Schematron validation that fails closed with the three BMF error categories; the receive/parse ingest path; issuance-obligation gating (date + turnover + recipient + amount + Kleinunternehmer); configurable target versions.
- **Lands:** #10, #11, #12 (issue side), #17, #18, #19, conflicts #2, #3, #10.
- **Risks:** horstoeko is CII-only — UBL via the bridge may fail KoSIT validation for some document types (budget a native serializer); KoSIT validator needs a JRE (bundle vs pure-PHP Schematron trade-off); format-version churn (ZUGFeRD 2.4/2.5, XRechnung 3.0.2→4.0) — everything must be configurable.

### M6 — Audit, export & retention (≈ weeks 23–27)
- **Delivers:** `RetentionPolicy` (class + year-end + sector flag → `retention_until`), deletion lock + DS-GVO deletion-eligibility query, Ablaufhemmung handling; Z1/Z2 read-only audit query surface; Z3 GDPdU/GoBD Beschreibungsstandard exporter (`index.xml` + DTD + CSV/ASCII) behind a driver interface; DATEV EXTF exporter; IKS role/segregation gates; byte-exact original archive store.
- **Lands:** #12 (preserve side), #13, #14, #23, #24, #25, #28, #34, conflicts #4 (permanent 10y financial-sector), #9.
- **Risks:** the financial-sector permanent-10y carve-out must be implemented as data, not "+1-then-8" (supersedes `[08]`); DATEV field set not yet pinned to an official spec; never-auto-delete must be enforced at the repository/observer layer, not just UI.

### M7 — Hardening, docs & the talk (≈ weeks 27–34)
- **Delivers:** ratchet mutation score toward 95–100 on money/numbering modules; round-trip EN 16931 validation in CI against the KoSIT ruleset; full README/CONTRIBUTING/SECURITY, versioned Verfahrensdokumentation template, "GoBD-ready / Testat-fähig" positioning copy; release hygiene (SemVer + Conventional Commits + Keep-a-Changelog); 1.0 tag; the Laracon demo.
- **Lands:** #27, #33, and the quality bar across all prior items.
- **Risks:** scope creep from competitor parity (DATEV variants, marketplace) — hold the anti-scope line (not a UI, not a ledger, not a POS, not its own XML codec); over-claiming compliance ("GoBD-zertifiziert") is a legal/marketing risk; legal facts may shift before launch (re-verify every threshold/version against primary sources at tag time).

> **Buffer note.** 34 weeks ≈ 8 months with ~no slack; M2 (VAT correctness) and M5 (e-invoicing/UBL/validator) are the highest-risk milestones and the most likely to overrun. If time compresses, M4 (PDF) can ship with dompdf-only + Gotenberg deferred, and DATEV (#28) / the DSFinV-BV-ready export hook can slip past 1.0 without breaking the GoBD-complete claim — but M1–M3 and M5 are non-negotiable for a credible Laracon 1.0.
