# E-Invoicing: E-Rechnungspflicht, XRechnung, ZUGFeRD/Factur-X

## Scope and how to read these notes

This is the authoritative reference for the e-invoicing (E-Rechnung) layer of `john-wink/gobd-invoice`. Every time-sensitive fact below is tagged with the date/version it applies to (**state of mid-2026**). The legal landscape was reshaped by three acts in quick succession — the **Wachstumschancengesetz** (Growth Opportunities Act, promulgated/in force end of March 2024), the **Viertes Bürokratieentlastungsgesetz / BEG IV** (Fourth Bureaucracy Relief Act), and the **2025 Kleinunternehmer reform** of § 19 UStG — so do not substitute memory for the cited primary sources (the two BMF-Schreiben of 15.10.2024 [2] and 15.10.2025 [3], and the BMF FAQ [1]).

> **Verification note (mid-2026):** All load-bearing dates, thresholds, retention periods, profile rules and §-references below were re-checked against `gesetze-im-internet.de`, `bundesfinanzministerium.de`, `xeinkauf.de` and `ferd-net.de`. Where the format-version landscape has moved on since the original draft, the text is annotated inline. The biggest currency correction versus older drafts is the **ZUGFeRD/Factur-X version** (no longer 2.3 — see §4.3).

A recurring theme that must drive the architecture: the legally binding invoice is the **structured XML data**, not the human-readable rendering. Everything the engine emits must put the complete set of mandatory invoice particulars (Pflichtangaben der §§ 14, 14a UStG) into the machine-readable part.

---

## 1. Legal basis and the new definition of "elektronische Rechnung"

The **Wachstumschancengesetz** reformatted the invoicing rules of **§ 14 UStG** (German VAT Act) for supplies executed after 31.12.2024. Since **01.01.2025** the law distinguishes two invoice classes [1][2]:

- **Elektronische Rechnung (e-invoice)** — defined in **§ 14 Abs. 1 Satz 3 UStG**: *"Eine elektronische Rechnung ist eine Rechnung, die in einem strukturierten elektronischen Format ausgestellt, übermittelt und empfangen wird und eine elektronische Verarbeitung ermöglicht."* The structured format must, per § 14 Abs. 1 Satz 6, either:
  - **comply with the European standard EN 16931** (in the sense of Directive 2014/55/EU), **or**
  - be a format **bilaterally agreed** by the parties (**§ 14 Abs. 1 Satz 6 Nr. 2 UStG**) from which the UStG-required particulars can be **correctly and completely extracted** into a form that corresponds to or is **interoperable with** EN 16931 (e.g. EDI) [1][2].
- **Sonstige Rechnung (other invoice)** — defined in **§ 14 Abs. 1 Satz 4 UStG**: *"Eine sonstige Rechnung ist eine Rechnung, die in einem anderen elektronischen Format oder auf Papier übermittelt wird."* Crucially this now includes **a simple PDF or image**: the BMF FAQ states *"Beispielsweise ein einfaches PDF-Dokument fällt dann nicht mehr unter diese Definition, da es kein strukturiertes Format hat"* [1].

**Software consequence:** A PDF the engine renders is, on its own, only a *sonstige Rechnung*. To produce a legal e-invoice the engine must emit EN 16931-conformant **structured XML** — either standalone (XRechnung) or embedded into a PDF/A-3 (ZUGFeRD/Factur-X). The PDF layer is decoration; the XML is the invoice.

The mandate covers **domestic B2B** supplies (both supplier and recipient established in Germany). B2C, foreign recipients, and certain tax-exempt supplies are out of scope (see §5).

---

## 2. The phased timeline — exact dates and thresholds

Source of truth: BMF FAQ [1] and the **first** BMF-Schreiben of 15.10.2024 (Az. III C 2 - S 7287-a/23/10001 :007, BStBl 2024 I S. 1320) [2], confirmed/supplemented by the **second** BMF-Schreiben of 15.10.2025 (Az. III C 2 - S 7287-a/00019/007/243) [3] and IHK guidance [5].

### 2.1 Receiving (Empfang) — already mandatory

From **01.01.2025**, **every** domestic business (including Kleinunternehmer) must be **able to receive** an EN 16931 e-invoice. There is **no transition period for receiving** [1][3]. The BMF explicitly says a plain **e-mail inbox is sufficient** to satisfy the receive obligation (*"Dazu reicht bereits ein E-Mail-Postfach aus"*) [1]. The sender does **not** need the recipient's consent to send a compliant e-invoice from 2025; consent is only required for a *sonstige Rechnung* in an electronic format (e.g. a PDF e-mail) [1].

### 2.2 Issuing (Ausstellung) — phased transition

| Period | Who | What is permitted for domestic B2B | Condition |
|---|---|---|---|
| 01.01.2025 – 31.12.2026 | All | Paper or *sonstige Rechnung* (e.g. PDF) still allowed | Electronic non-e-invoice formats require **recipient consent**; paper needs none [1][2] |
| 01.01.2027 – 31.12.2027 | Businesses with **prior-year (2026) total turnover ≤ 800,000 EUR** | May still issue paper / *sonstige Rechnung* | Turnover threshold; recipient consent for electronic non-e-formats [1][2] |
| 01.01.2027 – 31.12.2027 | Businesses with prior-year turnover **> 800,000 EUR** | **Must issue e-invoices** in B2B | — |
| From **01.01.2028** | **All** businesses | **Must issue e-invoices** in B2B | Only the §5 exemptions remain [1][2] |

**EDI:** A pure EDI procedure that does not itself already meet the e-invoice definition may continue to be used **through the end of 2027** under the transition rules; beyond that, from **01.01.2028** the UStG-required data must be **correctly and completely extractable** from the EDI message into an EN 16931-interoperable record for the procedure to remain compliant [1][5]. (Industry expectation is that established EDI keeps working long-term precisely because EDI messages can satisfy the extraction test — but the safe-harbour transition for non-conformant EDI ends with 2027.)

**Software consequence:** Model issuance capability as a per-tenant capability gated by (a) calendar date and (b) the tenant's prior-year turnover. The engine should be able to *emit* e-invoices from day one (to satisfy 2025 senders who choose to), and should track recipient consent for *sonstige Rechnung* fallback during the 2025–2027 window.

### 2.3 The second BMF-Schreiben (15.10.2025) — operational clarifications [3]

The second Schreiben **supplements** (does not replace) the first; it answers open questions one year on and is geared to the issuing obligations that bite from 01.01.2027 (or 01.01.2028 for ≤ 800,000 EUR turnover) [3]. Key points the engine must honor:

- **All mandatory particulars must live in the structured XML.** *"Alle Pflichtangaben der §§ 14, 14a UStG … müssen … im strukturierten Teil der E-Rechnung enthalten sein. Nicht zulässig sind nicht in das .xml eingebettete Anhänge oder ein Link auf ein externes Ziel."* A reference to an attachment or external document is **not** sufficient — only the machine-readable part counts as legal invoice content [3].
- Three distinct **error categories** are now defined, with different legal consequences [3]:
  1. **Format errors** — the file does not match a permitted syntax/spec (EN 16931 syntax / extraction capability fails).
  2. **Business-rule errors** — syntactically valid but violates EN 16931 business rules / missing mandatory fields / wrong totals. These are **sub-divided** by VAT relevance: errors touching VAT-relevant fields (critical for input-tax deduction) vs. errors without VAT relevance (non-critical).
  3. **Content errors** — substantively wrong despite technical compliance (e.g. wrong tax rate, wrong service description/date).

  The engine must validate against both XML schema and Schematron before emission (see §6) and surface the category.
- **Corrections:** a pure **price/assessment-basis change** (rebate, discount, bonus — a § 17 UStG adjustment) does **not** require a corrected invoice in e-invoice format; if the **service content** changes (quantity, type, scope), a **Berichtigung of the original e-invoice with a clear, unambiguous reference (Bezug) to the original** must be issued **in e-invoice format** [3]. This maps directly to Storno/Korrekturrechnung handling. *(Note: it is a correction-with-reference to the original, not necessarily a free-standing brand-new invoice.)*
- **Storage / original format:** the structured part must be preserved *"unversehrt in seiner ursprünglichen Form"* with authenticity, integrity and readability assured for the full retention period [1][3]. *(The widely-cited reading that the structured part may be stored separately from / outside a single GoBD archive system, as long as it remains unchanged and traceable, is consistent with GoBD principles but is treated cautiously here — see Open Questions.)*

---

## 3. Retention period (Aufbewahrungsfrist) — changed by BEG IV

**Critical, recently changed fact:** BEG IV **reduced the retention period for invoices/Buchungsbelege from 10 to 8 years**, amending **§ 14b Abs. 1 UStG** (and correspondingly § 147 AO). Effective **01.01.2025**, the 8-year period applies to all invoices whose old 10-year period had **not yet expired** on 31.12.2024 [3][8]. (Practical corollary: invoices issued before 01.01.2017 no longer need to be retained.) The retention period **begins at the end of the calendar year in which the invoice was issued** [8]. The structured part of an e-invoice must be kept **"unversehrt in seiner ursprünglichen Form"** (intact, in its original form) for **8 years** [1][3].

**Software consequence:** Default retention = 8 years counted from the end of the year the invoice was issued; store the **original, byte-identical XML** (and, for ZUGFeRD/Factur-X, the original PDF/A-3 container) immutably. This dovetails with the package's GoBD `Unveränderbarkeit` (immutability) requirements — the original structured payload is the audit-relevant artifact.

---

## 4. The formats

### 4.1 EN 16931 — the semantic core

EN 16931-1 defines a **semantic data model** of **Business Terms (BT-xx)** grouped into **Business Groups (BG-xx)** — e.g. BT-1 invoice number, BT-2 issue date, BT-10 Buyer reference (the field that carries the Leitweg-ID in German B2G), BG-25 invoice line [9][10]. The same semantic model can be expressed in **two XML syntaxes**:

- **UN/CEFACT CII** (Cross Industry Invoice) — used by ZUGFeRD/Factur-X and selectable in XRechnung.
- **OASIS UBL 2.1** (Universal Business Language) — used by Peppol BIS Billing and selectable in XRechnung.

Both carry the identical EN 16931 data core and differ only in namespaces/element structure [10][9].

> **Caveat (EN 16931 revision):** EN 16931-1 has itself been revised (the **EN 16931-1:2026** edition). The German national CIUS, XRechnung, follows this with its 4.0 version (see §4.2). The semantic BT/BG model the engine maps to is stable in principle, but plan for additive changes (e.g. new line/sub-line handling, attachment-in-core) when EN 16931:2026 / XRechnung 4.0 become normative.

### 4.2 XRechnung — the German CIUS

**XRechnung** is the German **CIUS** (Core Invoice Usage Specification) of EN 16931, maintained by **KoSIT** (Koordinierungsstelle für IT-Standards) and published via **xeinkauf.de** [4][6]. It is a **standalone XML** invoice (no PDF), available in **both UBL and CII** syntaxes, adding German national business rules on top of the EN core.

**Versioning (as of mid-2026):**
- The current **normative** version is **XRechnung 3.0.2** (effective from **20.06.2024**) [4][11].
- The latest maintenance package is the **Bundle 3.0.2 "Winter 2025/26"**, effective **31.01.2026** (published end of January / early February 2026), which ships an updated KoSIT validator configuration, **SeMoX** models (introduced for the first time), and a technical move to **SchXslt** — but contains **no normative changes**; the components stay compatible with XRechnung 3.0 [4][11].
- **XRechnung 4.0** implements the revised **EN 16931-1:2026** standard and is **announced for mid/late 2026** (consolidated/sub-line handling, new extension methodology, attachments in the core); as of mid-2026 it is **not yet normative** — xeinkauf.de still lists 3.0.2 as the only valid version [11].

**Release policy:** KoSIT has moved from two releases per year to **one release per year**, published roughly six months before it becomes effective (effective dates on **31.07** or **31.01**). When a new version becomes effective, the previous version in principle **loses validity**, but there is normally a **time-limited transition phase** in which the predecessor is still accepted before being rejected. So treat "two versions valid at once" as a bounded handover window, **not** a standing policy — and make the engine's target version **configurable, never hard-coded to "3.0.2"**.

### 4.3 ZUGFeRD / Factur-X — the hybrid PDF/A-3

**ZUGFeRD** (Germany, maintained by **FeRD**) and **Factur-X** (France, **FNFE-MPE**) are the **same, technically identical hybrid standard**: a **PDF/A-3** file (the human-readable invoice) with an **embedded CII XML** attachment carrying the structured EN 16931 data [12][13]. They share the Factur-X identifier and use **CII syntax only** (UN/CEFACT) [12].

**Version currency (mid-2026) — CORRECTED:**
- **ZUGFeRD 2.3 = Factur-X 1.0.07** (info package dated **18.09.2024**) was the relevant release for the launch of the 2025 mandate [12], but it is **no longer the current version** and has been withdrawn from download.
- **ZUGFeRD 2.4 = Factur-X 1.0.8** was released **15.01.2026** (EN 16931-aligned, adds sub-line management and EXTENDED-profile elements for interoperability with the French B2B obligation).
- **ZUGFeRD 2.5** is the **latest** version as of mid-2026 (information package released around **10 June 2026**) and supersedes 2.4.
- **All 2.x versions from 2.0.1 onward are recognised by the BMF** (see profile rule below), so any of 2.3/2.4/2.5 produces a legally valid e-invoice provided the profile is adequate. The version a tenant emits should be **configurable**; default to a currently-published version (2.4/2.5 family), not the now-superseded 2.3.

**Profiles and legal validity** (cross-checked [12][14][1][3]):

| Profile | Content | Valid e-invoice under § 14 UStG? |
|---|---|---|
| **MINIMUM** | Header-level totals only ("Buchungshilfe" / booking aid) | **No** — not a complete invoice, not a valid e-invoice [14][1] |
| **BASIC-WL** | Header + totals without line items ("WithoutLines") | **No** — booking aid only, **not** a valid e-invoice [14][1] |
| **BASIC** | EN 16931 subset **with line items** | **Yes** — the BMF excludes only MINIMUM and BASIC-WL, so BASIC (which carries line items) qualifies [14][1] |
| **EN 16931 (a.k.a. COMFORT)** | Full EN 16931 core model | **Yes** — recommended baseline [14] |
| **EXTENDED** | EN 16931 + extra fields (cross-border, complex cases) | **Yes**; use should be bilaterally agreed [12][14] |
| **XRECHNUNG** | Embedded XML conforms to the XRechnung CIUS | **Yes** — B2G-capable [12][14] |

The BMF formulation is explicit (BMF FAQ [1]): *"Insbesondere die in Deutschland üblichen Formate XRechnung und ZUGFeRD ab Version 2.0.1 (mit Ausnahme der Profile MINIMUM und BASIC-WL) erfüllen die umsatzsteuerlichen Voraussetzungen für eine E-Rechnung."* So **only MINIMUM and BASIC-WL are excluded** — BASIC and everything above it are valid [1][14].

> **Annotation:** Some secondary/commercial guides incorrectly lump **BASIC** in with MINIMUM/BASIC-WL as "not valid". That contradicts the BMF's own wording, which excludes *only* MINIMUM and BASIC-WL. Trust the BMF FAQ [1]: **BASIC is valid.** (BASIC is, however, only an EN 16931 *subset* and may omit some optional fields, so EN 16931/COMFORT remains the recommended baseline.)

**Software consequence:** The engine must **never** emit MINIMUM or BASIC-WL as a legal invoice. Default emission profile should be **EN 16931 (COMFORT)**; offer EXTENDED and the XRECHNUNG profile as opt-ins.

### 4.4 Peppol / Peppol BIS Billing 3.0 and B2G (predates the B2B mandate)

E-invoicing to the **public sector (B2G)** is older than the B2B mandate. Transposing **EU Directive 2014/55/EU** (via the **E-Rechnungsverordnung, ERechV**), German federal public bodies have had to **receive** EN 16931 e-invoices since **27.11.2019** (sub-federal contracting authorities followed from **18.04.2020**), and **suppliers to the federal administration must issue** e-invoices since **27.11.2020** (with limited exceptions such as direct orders up to 1,000 EUR) [7]. The carrier format for B2G is **XRechnung**, routed by the mandatory **Leitweg-ID** (carried in the Buyer reference, BT-10) via the federal portals **ZRE** and **OZG-RE** or over the **Peppol** network [7][6].

**Peppol BIS Billing 3.0** is the Peppol delivery profile (UBL) and is, within Germany, **functionally interchangeable with XRechnung** thanks to harmonization into the Peppol national ruleset [7]. Peppol additionally defines a **4-corner transport network** (Access Points + SMP/SML directory) — out of scope for a pure document-generation engine, but the engine should produce Peppol-BIS-valid UBL so a downstream Access Point can transmit it.

---

## 5. Exemptions (no obligation to *issue* an e-invoice)

From the BMF FAQ [1] and §§ 33/34 UStDV:

- **Small-amount invoices ≤ 250 EUR** gross (Kleinbetragsrechnungen, § 33 UStDV) [1].
- **Tickets / Fahrausweise** (§ 34 UStDV) [1].
- **Kleinunternehmer** (§ 19 UStG): **not obliged to issue** e-invoices — may always issue a *sonstige Rechnung* — **but must be able to receive** e-invoices [15][1].
- Supplies to **non-entrepreneurs / private consumers (B2C)** and to **foreign** recipients; certain **tax-exempt** supplies under **§ 4 Nr. 8–29 UStG** [1].

**2025 Kleinunternehmer reform (§ 19 UStG):** new **net** thresholds — **Vorjahr (prior year) ≤ 25,000 EUR** and **laufendes Jahr (current year) ≤ 100,000 EUR** (Gesamtumsatz) [15]. Confirmed against § 19 UStG and BMF guidance: exceeding the **100,000 EUR** current-year limit ends the exemption **immediately, mid-year** — *"ab dem Zeitpunkt des Überschreitens"* — the supply that breaches the limit is already subject to regular taxation; this is no longer a forecast-based test [15]. Kleinunternehmer invoices have a special, reduced content rule (since the 2025 reform: **§ 34a UStDV**), and the invoice must carry a note referencing the tax exemption [15].

**Software consequence:** Make e-invoice issuance conditional on recipient type (B2B domestic), amount (> 250 EUR gross), and tenant status (not Kleinunternehmer). Even exempt tenants need a **receive/parse** path.

---

## 6. Validation — what the engine must enforce before emission

A document is validated in two layers [9][10][3]:

1. **XML Schema (XSD)** validation against UBL 2.1 or CII — catches *format errors*.
2. **Schematron** business-rule validation — catches *business-rule errors* (totals must add up, mandatory BTs present, etc.). The canonical artifacts are the **CEN/TC 434 EN 16931 Schematron** (ConnectingEurope/eInvoicing-EN16931) plus the **KoSIT `validator-configuration-xrechnung`** scenarios run by the **KoSIT validator** (the official German validator) [9][6][4].

**Software consequence:** Ship a validation step that runs the KoSIT validator configuration (or the EN 16931 Schematron) against generated XML and **fails the emission** on any format or fatal business-rule error. `horstoeko/zugferd` can invoke the KoSIT validator via `symfony/process` (it requires a JRE for the Java-based KoSIT validator); plan for an optional bundled validator and a clear error report mapping to the three BMF error categories — and, for business-rule errors, distinguishing VAT-relevant from non-VAT-relevant violations [3].

---

## 7. PHP library landscape (all MIT-licensed unless noted)

| Library | Purpose | Syntax | License | Notes |
|---|---|---|---|---|
| **horstoeko/zugferd** | Build & read ZUGFeRD/Factur-X/XRechnung; embed XML into PDF/A-3 (`ZugferdDocumentPdfMerger`); KoSIT validation hooks | **CII only** (no UBL) | MIT | Latest release **v1.0.123** (released **23.05.2026**). Supports MINIMUM, BASIC, BASIC-WL, EN16931/COMFORT, EXTENDED, and XRechnung 1.x/2.x/3.x profiles [16][17] |
| **horstoeko/zugferdvisualizer** | Render a ZUGFeRD/XRechnung XML to HTML/PDF (KoSIT XSLT) | — | MIT | Add-on to zugferd [16] |
| **horstoeko/zugferd-laravel** | Laravel wrapper (config, facade) around horstoeko/zugferd | CII | MIT | Useful reference, but couples to its own conventions [16] |
| **horstoeko/zugferdublbridge** | Convert CII ↔ UBL | bridges to UBL | MIT | Path to emit UBL (Peppol/XRechnung-UBL) from CII [16] |
| **horstoeko/zugferdmail** | Fetch/parse e-invoices from mailboxes | CII | MIT | Helps the receive path [16] |
| **horstoeko/orderx** | Order-X (electronic orders, EN 16931-3 sibling) | CII | MIT | Out of scope for invoicing, same author/patterns [16] |

**horstoeko/zugferd dependencies (composer.json):** PHP `>=7.3`, license MIT; requires `jms/serializer ^3`, `goetas-webservices/xsd2php-runtime ^0.2.13`, `setasign/fpdf ^1`, `setasign/fpdi ^2` (PDF embedding), `smalot/pdfparser ^0|^2`, `symfony/process|finder|validator|yaml ^5|^6|^7|^8`, plus `horstoeko/mimedb` and `horstoeko/stringmanagement`; PHP ext `fileinfo`, `simplexml` [17]. The `^8` Symfony constraints mean the library installs and runs on **PHP 8.4 / 8.5**.

**Gap to note:** `horstoeko/zugferd` is **CII-only**. For **UBL** output (Peppol BIS Billing 3.0, XRechnung-UBL) you must convert via `zugferdublbridge` or add a dedicated UBL serializer. There is no equally dominant single XRechnung-UBL-native PHP library; the horstoeko stack + UBL bridge is the pragmatic path (verify bridge output against the KoSIT UBL Schematron — see Open Questions).

---

## 8. Concrete requirements and recommended architecture for `gobd-invoice`

### 8.1 Must-have functional requirements

1. **Emit EN 16931-conformant structured XML** for every B2B invoice, in two delivery shapes: **standalone XRechnung** (UBL and CII) and **ZUGFeRD/Factur-X hybrid PDF/A-3** (CII embedded).
2. **Default profile EN 16931 (COMFORT)**; allow EXTENDED and the XRECHNUNG profile. **Hard-block MINIMUM and BASIC-WL** as legal output (BASIC and above are valid, but COMFORT is the recommended baseline) [1][14].
3. **All mandatory particulars in the XML** — never rely on PDF text, attachments, or external links for legal content [3].
4. **Configurable, non-hard-coded target version** for XRechnung (currently 3.0.2; be ready for 4.0 / EN 16931:2026) and for ZUGFeRD/Factur-X (default to a currently-published 2.4/2.5 release, not the superseded 2.3) [4][12].
5. **Leitweg-ID (BT-10, Buyer reference) support** for B2G, plus a clean way to set Buyer Reference; produce Peppol-BIS-valid UBL for downstream Access Points [7].
6. **Pre-emission validation** (XSD + Schematron / KoSIT) that fails closed and reports the three BMF error categories, distinguishing VAT-relevant business-rule errors [3][9].
7. **Receive/parse path**: ingest XRechnung (UBL+CII) and ZUGFeRD, extract the embedded XML, validate, and map to the domain model. Even Kleinunternehmer tenants need this [1][15].
8. **Immutable retention**: persist the **original byte-identical XML** (and PDF/A-3 container) for **8 years** (counted from end of issue year), integrated with the package's GoBD `Unveränderbarkeit` layer [1][3][8].
9. **Issuance gating** by date + prior-year turnover (the 800,000 EUR / 2027 / 2028 logic) and recipient-consent tracking for the *sonstige Rechnung* fallback window (2025–2027) [1][2].
10. **Correction semantics**: distinguish a § 17 price-only change (no e-invoice correction required) from a service-content change (a **Berichtigung in e-invoice format with a clear reference to the original**) — wire into Storno/Korrektur/Gutschrift document types [3].

### 8.2 Recommended library/architecture

- **Core dependency:** `horstoeko/zugferd` (MIT) as the CII builder/reader and the **PDF/A-3 embedding engine** (`ZugferdDocumentPdfMerger`, via setasign FPDI/FPDF). It already covers ZUGFeRD, Factur-X, and the XRechnung-CII profile [16][17].
- **UBL output:** add `horstoeko/zugferdublbridge` (MIT) to convert the CII model to **UBL** for XRechnung-UBL and **Peppol BIS Billing 3.0** [16].
- **Validation:** integrate the **KoSIT validator** (Java) with the current `validator-configuration-xrechnung` scenario bundle (the Winter 2025/26 bundle as of mid-2026), invoked through `symfony/process`; optionally ship the **CEN EN 16931 Schematron** for a pure-PHP fallback (via a Schematron processor) [9][6].
- **Rendering:** keep your own PDF/A-3 templating for the visible page, or use `horstoeko/zugferdvisualizer` (KoSIT XSLT) for a guaranteed-faithful human view [16].
- **Abstraction layer (do build this yourself):** define a **format-agnostic internal invoice value object** mapped to EN 16931 BT/BG terms, with pluggable **serializers** (ZUGFeRD-CII, XRechnung-CII, XRechnung-UBL, Peppol-UBL) and a **profile/version selector**. This insulates the package from horstoeko's API and from the XRechnung 3.0.2 → 4.0 and ZUGFeRD 2.3 → 2.4/2.5 transitions, and lets you swap the UBL path if a better library emerges.
- **Wrap, don't extend:** prefer wrapping `horstoeko/zugferd` behind your own service interfaces rather than adopting `horstoeko/zugferd-laravel`'s conventions, so the domain model (Rechnung, Teilzahlung, Abschlags-/Schlussrechnung, Storno, Gutschrift, etc.) stays the source of truth and the e-invoice serializers are a downstream concern.

This stack is fully **MIT-licensed**, runs on **PHP 8.4/8.5 + Laravel 13**, emits **all legally valid formats**, embeds XML into **PDF/A-3**, and keeps the original structured payload for GoBD-compliant 8-year immutable retention.

---

## Sources

[1] BMF FAQ: Fragen und Antworten zur Einführung der obligatorischen (verpflichtenden) E-Rechnung zum 1. Januar 2025 - https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
[2] BMF-Schreiben vom 15.10.2024 zur Einführung der obligatorischen E-Rechnung (Az. III C 2 - S 7287-a/23/10001 :007, BStBl 2024 I S. 1320) - https://datenbank.nwb.de/Dokument/1046425/
[3] Zweites BMF-Schreiben vom 15.10.2025 zur E-Rechnung (Az. III C 2 - S 7287-a/00019/007/243) - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-10-15-einfuehrung-obligatorische-e-rechnung.pdf?__blob=publicationFile&v=5 (commentary: https://www.haufe.de/finance/steuern-finanzen/bmf-schreiben-v-15102025-zur-e-rechnung_190_669628.html)
[4] XStandards Einkauf (KoSIT): Versionen und Bundles der XRechnung - https://xeinkauf.de/xrechnung/versionen-und-bundles/
[5] IHK Frankfurt am Main: E-Rechnungspflicht ab 2025 - https://www.frankfurt-main.ihk.de/recht/uebersicht-alle-rechtsthemen/steuerrecht/umsatzsteuer-national/e-rechnungspflicht-ab-2025-6055774
[6] E-Rechnung Bund: XRechnung standard / federal e-invoicing portals - https://e-rechnung-bund.de/en/e-invoicing-for-invoice-issuers/
[7] European Commission: eInvoicing in Germany (B2G obligations, Peppol, EN 16931) - https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108886/eInvoicing+in+Germany
[8] Haufe: Bürokratieentlastungsgesetz (BEG IV) – Aufbewahrungspflichten verkürzt (10 auf 8 Jahre) - https://www.haufe.de/finance/buchfuehrung-kontierung/buerokratieentlastungsgesetz-aufbewahrungspflichten-verkuerzt_186_634670.html
[9] ConnectingEurope/eInvoicing-EN16931: Validation artefacts (Schematron) for EN 16931 - https://github.com/ConnectingEurope/eInvoicing-EN16931
[10] e-invoice.be: EN 16931 Field Mapper (UBL & CII XML path reference) - https://e-invoice.be/en16931-mapper
[11] Factora: XRechnung Bugfix Release Winter 2025/26 und XRechnung 4.0 (EN 16931-1:2026) - https://factora.software/blog/xrechnung-bugfix-winter-2025-26/
[12] FeRD-net: ZUGFeRD 2.3 (Factur-X 1.0.07) – profiles, PDF/A-3, CII - https://www.ferd-net.de/en/downloads/publications/details/zugferd-23-english
[13] VATupdate: Factur-X 1.07 / ZUGFeRD 2.3 updated for hybrid e-invoicing (15.11.2024) - https://www.vatupdate.com/2024/11/18/factur-x-1-07-zugferd-2-3-updated-for-hybrid-e-invoicing-as-of-november-15-2024/
[14] zugferd-tools.de: Welche ZUGFeRD-Profile erfüllen die E-Rechnungspflicht? - https://zugferd-tools.de/artikel/zugferd-profile-e-rechnungspflicht/
[15] easyRechtssicher: Kleinunternehmer-Regelung 2025 (25.000/100.000 EUR) und E-Rechnung - https://easyrechtssicher.de/blog/kleinunternehmer-regelung-2025
[16] horstoeko/zugferd (GitHub): ZUGFeRD/XRechnung/Factur-X Library and companion packages - https://github.com/horstoeko/zugferd
[17] horstoeko/zugferd on Packagist (version, PHP constraint, MIT license, dependencies) - https://packagist.org/packages/horstoeko/zugferd
[18] § 14 UStG (gesetze-im-internet.de) – definition of elektronische/sonstige Rechnung - https://www.gesetze-im-internet.de/ustg_1980/__14.html
[19] § 19 UStG (gesetze-im-internet.de) – Kleinunternehmer thresholds 2025 (25.000/100.000 EUR) - https://www.gesetze-im-internet.de/ustg_1980/__19.html
[20] § 14b UStG (gesetze-im-internet.de) – Aufbewahrung von Rechnungen (8 Jahre) - https://www.gesetze-im-internet.de/ustg_1980/__14b.html
[21] FNFE-MPE press release: Factur-X 1.0.8 / ZUGFeRD 2.4 (15.01.2026) and the 2026 update - https://fnfe-mpe.org/wp-content/uploads/2025/12/2025-12-04_Factur-X_1.08_ZUGFeRD_2.4_Press_Release_EN.pdf

---

## Open Questions

1. **Exact normative status of XRechnung 4.0 at the moment of package release.** As of mid-2026 it is announced for mid/late 2026 and implements EN 16931-1:2026, but xeinkauf.de still lists 3.0.2 as the only normative version. Confirm against the KoSIT release calendar before pinning a default target version, and design for a 3.0.2 → 4.0 handover window (predecessor accepted for a time-limited transition, then rejected) under KoSIT's one-release-per-year policy.

2. **Current ZUGFeRD/Factur-X version to ship as default.** The landscape moved during 2025/2026: 2.3 (18.09.2024) → 2.4 / Factur-X 1.0.8 (15.01.2026) → 2.5 (info package ~June 2026). All 2.x ≥ 2.0.1 are BMF-recognised, but 2.3 is withdrawn from download. Decide which currently-published version to emit by default and confirm `horstoeko/zugferd` fully supports it (the library tracks the FeRD releases; verify 2.4/2.5 profile coverage in the installed version).

3. **Future German B2B *reporting/Meldesystem* (transaction reporting).** The BMF has signalled a transaction-reporting system tied to the EU ViDA "Digital Reporting Requirements". No firm domestic go-live date is fixed yet; the EDI extraction rule from 01.01.2028 hints at it. Decide whether to leave hooks for a reporting export.

4. **Precise handling of the EXTENDED profile and bilateral-agreement metadata.** The BMF requires the recipient to be able to extract mandatory data (§ 14 Abs. 1 Satz 6 Nr. 2 UStG). The engine may need a per-recipient capability/agreement record before defaulting to EXTENDED rather than EN 16931 (COMFORT).

5. **Bundle the Java KoSIT validator or rely on a pure-PHP Schematron path?** The KoSIT validator needs a JRE in the deployment image; a pure-PHP Schematron processor avoids that but may diverge from the official German validator's verdicts. Weigh validation fidelity against runtime footprint, and ensure the error report maps to the three BMF error categories (incl. VAT-relevant vs. non-VAT-relevant business-rule errors).

6. **UBL emission quality.** horstoeko is CII-only and UBL is produced via the CII→UBL bridge. Verify the bridge output passes the KoSIT XRechnung-UBL and Peppol BIS 3.0 Schematron cleanly for the full set of gobd-invoice document types (Abschlags-/Schlussrechnung, Storno, Gutschrift), or budget for a native UBL serializer.

7. **Storage of the structured part outside a single GoBD archive.** The 15.10.2025 Schreiben stresses preservation "unversehrt in seiner ursprünglichen Form" with authenticity/integrity/readability. Confirm the precise wording on whether the structured XML may be archived separately from the rest of the GoBD system (as long as unchanged and traceable) before relying on a split-storage design.
