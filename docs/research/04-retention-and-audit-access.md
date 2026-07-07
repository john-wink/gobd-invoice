# Retention Periods, Archiving & Tax-Audit Data Access

## Scope and how to read these notes

These notes cover the German legal regime for **Aufbewahrung (retention)**, **Archivierung (archiving)** and **Datenzugriff der Finanzverwaltung (tax-authority data access)** as it stands in **mid-2026**, and translate each requirement into concrete obligations for the `john-wink/gobd-invoice` engine. Every time-sensitive fact below carries the date/version it applies to. The package is framework-only and must implement these rules *itself* — it cannot lean on a Filament admin layer or any UI for compliance.

> CURRENCY WARNING (read first). German retention law moved twice in quick succession and the second move *partially reversed* the first. Do not implement the BEG IV reduction blindly: the financial-sector carve-out was **withdrawn permanently** in late 2025 (see §1). Any model written off the original 2024/2025 BEG IV understanding is already out of date.

Three legal instruments dominate the current state of play and all must be assumed in force in mid-2026:

- **Wachstumschancengesetz** (verkündet 27 March 2024, BGBl. I 2024 Nr. 108) introduced the mandatory domestic B2B **E-Rechnung** with structured EN 16931 data, phased from 1 January 2025 (with format-issuance transition rules running through 31 December 2027). The retention/immutability consequences were first spelled out in the **BMF-Schreiben of 15 October 2024** (Az. III C 2 - S 7287-a/23/10001 :007, BStBl I 2024, S. 1320) and then **updated** by the **GoBD 2nd amendment of 14 July 2025** (Az. IV D 2 - S 0316/00128/005/088) and the **follow-up BMF-Schreiben of 15 October 2025** (Az. III C 2 - S 7287-a/00019/007/243), which is the *current, leading* administrative letter. [4][5][7][15][16]
- **Viertes Bürokratieentlastungsgesetz (BEG IV)**, verkündet in the Bundesgesetzblatt on **29 October 2024 (BGBl. I 2024 Nr. 323)**, in force 1 January 2025, which **shortened the retention period for Buchungsbelege (booking vouchers) from 10 to 8 years**. [1][2][3]
- **Gesetz zur Modernisierung und Digitalisierung der Schwarzarbeitsbekämpfung (SchwarzArbMoDiG)** — Bundeskabinett 6 August 2025, **Bundestag 13 November 2025** — which **permanently reversed** the BEG IV shortening *for the financial sector* (Kreditinstitute, Versicherungsunternehmen, Wertpapierinstitute), keeping them at the 10-year period for Buchungsbelege. This is the single most important currency correction in this document. [17][18][19]

> ANNOTATION — date hygiene. There are **two distinct BMF e-invoice letters dated 15 October** (one in 2024, the follow-up in 2025) plus the **14 July 2025 GoBD amendment**. They are easy to confuse. When this document cites "the BMF e-invoice guidance," it means the consolidated position after the 15 October 2025 letter, which carries forward the substance of the 15 October 2024 letter as amended by the 14 July 2025 GoBD change. The package author should pin the *15 October 2025* letter as the controlling source and treat the 2024 letter as superseded-but-foundational.

---

## 1. Aufbewahrungsfristen (retention periods): the current matrix

Two parallel statutory regimes apply simultaneously and the package must satisfy the **longer** of any that bite on a given document: commercial law (**§ 257 HGB**), general tax law (**§ 147 AO**) and VAT law (**§ 14b UStG**). All three were amended in lock-step by BEG IV. [1][2][3]

### The headline change (effective 1 January 2025)

BEG IV reduced the retention period for **Buchungsbelege** (booking vouchers — the document class that an invoice falls into once it underpins a booking) from **10 years to 8 years**, by amending **§ 147 Abs. 3 AO**, **§ 257 Abs. 4 HGB** and **§ 14b Abs. 1 UStG**. The current statutory text confirms the three-tier structure: **10 years** for the documents in § 147 Abs. 1 Nr. 1 and Nr. 4a, **8 years** for Buchungsbelege (Nr. 4), and **6 years** for the rest. The HGB and UStG provisions read identically (10 / 8 / 6). [1][2][3][6][8][14]

> Verified against gesetze-im-internet.de (mid-2026):
> - **§ 147 Abs. 3 AO**: "Die in Absatz 1 Nummer 1 und 4a aufgeführten Unterlagen sind zehn Jahre, die in Absatz 1 Nummer 4 aufgeführten Unterlagen **acht Jahre** und die sonstigen in Absatz 1 aufgeführten Unterlagen sechs Jahre aufzubewahren …"
> - **§ 257 Abs. 4 HGB**: same 10 / 8 / 6 split.
> - **§ 14b Abs. 1 UStG**: invoices retained "**acht Jahre**."

The transition rule (Art. 97 § 19b EGAO / the EGHGB analogue): the new 8-year period applies to all documents **whose previous (10-year) retention period had not yet expired on the first day of the quarter following promulgation**. Promulgation was 29 October 2024, so that first day is **1 January 2025**. In practice: any Buchungsbeleg whose 10-year clock had not run out by 31 December 2024 is now subject only to the 8-year period. [1][3]

> ANNOTATION — Open: the precise EGAO transition citation. Advisory sources resolve the general transition uniformly to 1 January 2025, but they cite the EGAO provision variously as **Art. 97 § 19b** or **Art. 97 § 19a Abs. 2** EGAO (the financial-sector reversal in particular sits in the § 19a strand). The literal BGBl text should be confirmed before relying on an edge-case interpretation (see Open Questions).

### CORRECTED — financial sector is permanently 10 years (NOT "8 from 2026")

> CRITICAL CORRECTION. An earlier reading of BEG IV held that for **Kreditinstitute, Versicherungsunternehmen und Wertpapierinstitute** the reduction merely applied **one year later** (8 years from 1 January 2026). **That is no longer the law.** Via the **SchwarzArbMoDiG** (Bundeskabinett 6 August 2025; **Bundestag 13 November 2025**) the legislator **completely withdrew ("in Gänze zurückgenommen")** the shortening for the financial sector. Banks, insurers and securities institutions therefore remain on the **10-year** period for Buchungsbelege **permanently** — not a one-year delay, but a reversal to the original 10 years indefinitely. [17][18][19]

Consequence for the package: the financial-sector flag must **not** flip to 8 years in 2026. It must keep `booking_voucher` retention at **10 years for all dates** when the tenant is a Kreditinstitut / Versicherung / Wertpapierinstitut. Treat this as a per-tenant configuration flag (`financial_sector = true ⇒ booking voucher retention = 10y, no expiry of the carve-out`). The package should *not* hard-code the obsolete "+1 year, then 8" rule.

### Retention matrix (as of mid-2026)

| Document class | § AO ref. | § HGB ref. | Period | Notes |
|---|---|---|---|---|
| Bücher, Aufzeichnungen, Inventare, Jahresabschlüsse, Lageberichte, Eröffnungsbilanz, Buchführungs-Organisationsunterlagen | § 147 Abs. 1 Nr. 1 | § 257 Abs. 1 Nr. 1 | **10 years** | Unchanged by BEG IV [1][8][14] |
| Zoll-/customs documents (Union Customs Code Art. 15 Abs. 1, Art. 163) | § 147 Abs. 1 Nr. 4a | — | **10 years** | Unchanged [8] |
| **Buchungsbelege** (incl. invoices once they back a booking: Rechnungen, Lieferscheine als Buchungsgrundlage, Zahlungsbelege, Kontoauszüge, Verträge als Buchungsgrundlage) | § 147 Abs. 1 Nr. 4 | § 257 Abs. 1 Nr. 4 | **8 years** (general) / **10 years** (financial sector, permanently) | Reduced from 10 by BEG IV eff. 1 Jan 2025; financial-sector carve-out reverted to 10y permanently by SchwarzArbMoDiG [1][2][3][8][14][17][18] |
| Issued + received **Rechnungen** under VAT law | — | — | **8 years** (§ 14b Abs. 1 UStG) | Both Ausgangs- und Eingangsrechnungen [3][6] |
| Empfangene/abgesandte Handelsbriefe, sonstige steuerlich relevante Unterlagen | § 147 Abs. 1 Nr. 2, 3, 5 | § 257 Abs. 1 Nr. 2, 3 | **6 years** | Unchanged [8][14] |

**Start of the clock (all regimes):** the period begins **mit dem Schluss des Kalenderjahres** in which the document/record was last entered, the invoice was issued, or the document was received — the controlling provisions are **§ 147 Abs. 4 AO** and **§ 257 Abs. 5 HGB** (not § 147 Abs. 3, which sets only the *length*). So an invoice issued on 14 March 2025 starts its clock at 31 December 2025 and may be deleted no earlier than **31 December 2033** (8 years). A Jahresabschluss zum 31 December 2025, drawn up in May 2026, starts its clock at 31 December 2026 and runs to 31 December 2036 (10 years). [8][14]

> Caveat — Ablaufhemmung. The clock does **not** end purely on `retention_until` if the underlying tax assessment is still open: **§ 147 Abs. 3 Satz 5 AO** keeps the retention obligation alive while the Festsetzungsfrist for the relevant tax has not yet run (open Außenprüfung, vorläufige Steuerfestsetzung, ongoing Steuerstrafverfahren, etc.). The engine's `retention_until` is therefore a *minimum*, never a licence to auto-delete. [8]

> Requirement R1 — Retention class per document. Every document the engine produces or ingests must carry a `retention_class` enum (`books_10y`, `customs_10y`, `booking_voucher_8y`, `invoice_vat_8y`, `correspondence_6y`) and a computed `retention_until` date = (end of issue/receipt calendar year) + N years. For documents that are simultaneously a Rechnung (8y) and could be argued into another class, store the **maximum**. Implement the financial-sector rule as a tenant flag that sets `booking_voucher` retention to **10 years for all document dates** (a permanent carve-out — do NOT model it as "+1 year then 8"). Treat `retention_until` as a floor that an Ablaufhemmung can extend.

> Requirement R2 — Do not hard-code "10 years" (and do not hard-code "8 years" either). The most common bug in legacy German invoicing software is a hard-coded fixed purge horizon. Drive everything off the matrix above, the document's issue/receipt year, and the tenant's sector flag. The 2024→2025→2025-reversal history is itself proof that the period must be data-driven, not a constant.

---

## 2. Format preservation and Unveränderbarkeit (immutability) of e-invoices

This is the single most package-defining requirement. Under the **BMF e-invoice guidance** (the 15 October 2024 letter, as amended by the 14 July 2025 GoBD change and the **current 15 October 2025 letter**), an e-invoice is a **rein strukturiertes Datenformat** (purely structured data format — an XML conforming to EN 16931, e.g. XRechnung, or the structured part of a hybrid ZUGFeRD/Factur-X PDF). The legal record is the *data*, not the picture of it. [4][5][7][15][16]

Concrete rules the engine must implement:

1. **Archive the structured XML in its original received/issued form.** The BMF requires that "zumindest der strukturierte Teil so aufzubewahren [ist], dass dieser unversehrt in seiner ursprünglichen Form vorliegt und die Anforderungen an die Unveränderbarkeit erfüllt." The original byte stream must be preserved unchanged for the entire retention period. The 15 October 2025 letter additionally clarifies that storing/archiving the structured e-invoice **outside** a GoBD-compliant DV-system does **not by itself** breach § 14b Abs. 1 UStG, provided the data stays unverändert and the integrity of content (Unversehrtheit des Inhalts) is preserved — useful latitude for an external WORM archive. [5][7][16]

2. **The structured part is the leading record.** Where the human-readable rendering (PDF visualisation) and the XML disagree, "die Informationen im strukturierten Teil der Rechnung … sind ausschlaggebend" (the structured part prevails). The PDF/visualisation is *secondary*. [7][16]

3. **The human-readable rendering is only separately archive-mandatory if it carries additional or differing information** ("zusätzliche oder abweichende Informationen") beyond the XML. A pure re-rendering of the XML need not be stored as a second mandatory artifact, but the machine-readable part is **always** required. [7][16]

4. **Conversion is allowed but never destructive.** If the e-invoice is converted into an in-house format, **both the original and the converted version must be retained and linked** ("sowohl die ursprüngliche als auch die konvertierte Version aufbewahrt werden und … verknüpft werden") for the whole period. Converting to TIFF/PDF-only and discarding the XML **violates** the rules because it destroys machine-readability. [7][16]

5. **Maschinelle Auswertbarkeit (machine evaluability) for the whole period.** "Lesbarkeit" here means the XML must remain machine-readable/evaluable by the Finanzverwaltung throughout retention, retrievable "regelmäßig auf Basis eines eindeutigen Index" (via a unique index). [4][7][16]

> Requirement R3 — Immutable original-format archive. On issue or ingest, persist the exact original structured payload (the EN 16931 XML, or the embedded XML extracted byte-for-byte from a ZUGFeRD/Factur-X PDF — and the original PDF too if it carries extra info) to a write-once store. Record a content hash (e.g. SHA-256) and an `archived_at` timestamp. Never mutate the stored blob; corrections happen via a new linked document (Storno/Gutschrift), never by editing the archived original. Treat the structured XML as the source of truth for all downstream rendering and bookkeeping. (Anchor R3 to the 15 October 2025 letter as the controlling text.)

> Requirement R4 — Conversion linkage. If the engine normalises an incoming invoice into its own model/format, keep the raw original and store a foreign-key/parent link (`source_archive_id`) so original ↔ converted ↔ booking can be traversed during an audit.

> Requirement R5 — Unveränderbarkeit at the persistence layer. GoBD immutability can be met by an unalterable WORM-style store, a documented procedure plus DB-level guarantees, or an append-only chain. For a framework package, the defensible default is: append-only archive table (no UPDATE/DELETE allowed via app code), per-row hash, and an optional hash-chain/sequence so tampering is detectable. Document the chosen procedure (**Verfahrensdokumentation**) — GoBD treats the documented procedure as part of compliance.

---

## 3. Datenzugriff der Finanzverwaltung — § 147 Abs. 6 AO (Z1 / Z2 / Z3)

During a Betriebsprüfung (tax audit), § 147 Abs. 6 AO grants the Finanzverwaltung three escalating access forms, universally abbreviated **Z1/Z2/Z3**. The detailed requirements live in the **GoBD** (the BMF administrative rules, current consolidated version after the **14 July 2025** 2nd amendment, which itself re-drafted the Z2 mittelbarer-Zugriff wording). [9][10][11][15]

| Form | Legal basis | What it means | What the package must enable |
|---|---|---|---|
| **Z1 — unmittelbarer Zugriff** (direct access) | § 147 Abs. 6 Satz 1 AO | The auditor gets **read-only** access to the live DV-system and evaluates data themselves on the taxpayer's hardware. | A scoped, read-only audit view/role over tax-relevant data; no write/delete capability; data presented in human- and machine-evaluable form. [9][10][11] |
| **Z2 — mittelbarer Zugriff** (indirect access) | § 147 Abs. 6 Satz 2 Alt. 1 AO | The auditor demands **specified machine evaluations** which the taxpayer runs on its system and presents (screen/print/read-only). Only evaluations using the **existing** evaluation capabilities of the system may be demanded — the taxpayer need not program new ones (re-confirmed by the 14 July 2025 GoBD amendment). | Pre-built, parameterisable reports/queries (by period, customer, document type, tax rate) the operator can run on the auditor's specification. [9][10][11][15] |
| **Z3 — Datenträgerüberlassung** (data-carrier handover) | § 147 Abs. 6 Satz 2 Alt. 2 AO | Tax-relevant data is exported onto a **machine-readable data carrier** and handed to the auditor, who runs their own software (typically **IDEA**). | A complete, structured export of the tax-relevant dataset in a standard the authority accepts (see §4). [9][10][11] |

Statutory anchor: **§ 147 Abs. 6 AO** is the basis for all three. The Finanzbehörde may (Satz 1) take Einsicht in the stored data and use the DV-system for the audit (Z1); and (Satz 2) demand that the data be made available **maschinell ausgewertet nach ihren Vorgaben** (Z2) or be **in einem maschinell auswertbaren Format an sie übertragen** (Z3). The split of Z1 → Satz 1, Z2 → Satz 2 Alt. 1, Z3 → Satz 2 Alt. 2 is the settled reading in the practitioner literature. [8][9][10]

> Requirement R6 — Audit access surface. Ship (a) a read-only audit query interface/role (Z1/Z2 support: filterable by Zeitraum, Belegart, Steuersatz, Geschäftspartner) and (b) a one-command Z3 export (next section). All three must expose the same canonical, tax-relevant fields with their links to the archived originals. Z2 must run on **pre-existing** evaluation logic — do not promise on-the-fly custom programming, which the law does not require of the taxpayer.

---

## 4. The Z3 export format — GoBD/GDPdU Beschreibungsstandard (and what's coming)

For **Z3 Datenträgerüberlassung**, the de-facto accepted format is the **GDPdU/GoBD Beschreibungsstandard** developed by the Finanzverwaltung with Audicon. A compliant export consists of: the DTD file **`gdpdu-01-08-2002.dtd`**, an **`index.xml`** describing each data file (file names, column headers, field types/formats), and the payload data files (CSV/ASCII in FixedLength or VariableLength format, e.g. `T1.CSV`, `T2.CSV`, `T4.ASC`). Only `index.xml` is XML; the payload itself is delimited/fixed-width text. Data conforming to this standard is accepted as machine-readable by the authority. [12][13]

Terminology note: **GDPdU** was the predecessor administrative regime; it applied through the end of 2014 and was **replaced by the GoBD with effect from 1 January 2015** (the GoBD also absorbed the older GoBS). Export interfaces are still often labelled "GDPdU" or "GoBD interface" interchangeably; the Beschreibungsstandard itself carries over unchanged. [13]

**On the horizon (do NOT assume in force yet):** the BMF is drafting a **Buchführungsdatenschnittstellenverordnung (DSFinV-BV / "DSFinVBV")** on the basis of the **§ 147b AO** authorization, to standardise *accounting/booking* data export across all businesses for Außenprüfungen and Kassen-Nachschauen (XML descriptors + CSV payloads, § 7 DSFinVBV-E). A 2nd Diskussionsentwurf went to software makers on 27 January 2025. Under the current draft the Verordnung would enter into force on **31 December of the third year following promulgation** and apply to Wirtschaftsjahre beginning thereafter — i.e. **roughly from 2027/2028 at the earliest, and not yet in force in mid-2026**. The package should keep its export layer pluggable so a future DSFinV-BV profile can be added without touching the core. [11][20]

> Requirement R7 — Z3 export module. Provide an artisan command / service that produces a Datenträgerüberlassung package: `index.xml` + the `gdpdu-01-08-2002.dtd` + CSV/ASCII payloads covering documents, line items, tax breakdowns, partners, and the linkage to archived originals, scoped by date range. Keep the export driver-based (`gdpdu` profile now, room for a `dsfinv-bv` profile later — but build the GDPdU/GoBD Beschreibungsstandard profile as the only one that is actually mandatory in mid-2026).

---

## 5. DSFinV-K — does it apply to invoicing software? (No.)

**DSFinV-K** = *Digitale Schnittstelle der Finanzverwaltung für Kassensysteme*. It is the export/record standard for **electronic cash registers / Kassensysteme** under the **KassenSichV**, tightly coupled to the **TSE (Technische Sicherheitseinrichtung)**. It governs Einzelaufzeichnung of cash transactions, master data and Kassenabschluss, plus the mandatory TSE export. [11][14-K]

**It does not apply to a pure invoicing/Faktura engine** that is not a point-of-sale cash register. A package generating Rechnungen, Angebote, Mahnungen etc. owes its compliance to the **GoBD + § 147 AO (Z1–Z3, GoBD/GDPdU Beschreibungsstandard)**, *not* to DSFinV-K/TSE. [11]

Practical caveat for the package author: if `gobd-invoice` is ever embedded in a system that records cash sales at a point of sale, that *surrounding* system may pull it into KassenSichV/DSFinV-K/TSE territory. The package itself should stay out of scope but document this boundary so integrators don't misuse it as a cash register.

> Requirement R8 — Scope boundary. Do not implement TSE/DSFinV-K inside the package. Document clearly that the engine is not a Kassensystem and that cash-register fiscalisation (KassenSichV/TSE/DSFinV-K) is out of scope and must be handled by a dedicated POS layer.

---

## 6. Audit trail / change-history retention (Nachvollziehbarkeit & Unveränderbarkeit)

GoBD requires **Nachvollziehbarkeit und Nachprüfbarkeit** (traceability) and **Unveränderbarkeit** (immutability): every tax-relevant record and every change to it must be reconstructable for the entire retention period, and a posted document must not be silently altered. A change must produce a documented, timestamped history; the original content stays retrievable. [4][9][11][15]

> Requirement R9 — Immutable change history. Maintain an append-only audit log for every document covering: creation, status transitions (Entwurf → festgeschrieben/issued), corrections (which always create a new linked document — Storno, Gutschrift, Abschlags-/Schlussrechnung chain — never an in-place edit of an issued record), and archival events. Each entry: actor, UTC timestamp, before/after (or hash), and a reason. Retain the audit log at least as long as the longest retention period of the documents it describes (i.e. up to 10 years for financial-sector tenants). Lock a document against editing once it transitions to "issued/festgeschrieben"; further changes only via linked correction documents.

---

## 7. Deletion locks (Löschsperre until retention expires)

The flip side of retention: tax-relevant documents **must not be deletable before `retention_until`** (and not while an Ablaufhemmung under § 147 Abs. 3 Satz 5 AO keeps the obligation alive). At the same time, DS-GVO (GDPR) Löschkonzept pressure means they generally **should** be reviewed/deleted once the period lapses — the BEG IV shortening from 10→8y was even framed as forcing companies to revisit their deletion concepts. [1] The package should make both directions explicit.

> Requirement R10 — Deletion lock + eligibility. Block hard-delete of any archived document or its structured original while `now() < retention_until` (enforce in a model observer / repository guard, not just UI). Expose a query for "documents eligible for deletion" (`retention_until < now()` **and** no active Ablaufhemmung flag) so an operator's DS-GVO Löschkonzept can act on them. Never auto-delete; surface candidates and require an explicit, logged action.

> Requirement R11 — Retention metadata is mandatory, not optional. Persist per document: `retention_class`, `retention_starts_at` (end of issue/receipt year), `retention_until`, `is_financial_sector` (drives the 10y carve-out), `ablaufhemmung_active`, `is_immutable` (true once issued), `original_archive_hash`, and links to converted/rendered/booking artifacts. These fields are the backbone of R1–R10.

---

## Quick implementation checklist

- [ ] Retention matrix driven by document class + issue/receipt year + tenant sector flag: 8y for Buchungsbelege/Rechnungen (general, eff. 2025), **10y permanently for financial-sector tenants** (SchwarzArbMoDiG reversal), 10y for Bücher/Jahresabschlüsse/customs, 6y for correspondence. [1][2][3][8][14][17][18]
- [ ] Clock starts at end of issue/receipt calendar year (§ 147 Abs. 4 AO / § 257 Abs. 5 HGB); `retention_until` is a floor, extendable by Ablaufhemmung (§ 147 Abs. 3 S. 5 AO). [8][14]
- [ ] Byte-for-byte immutable archive of the original EN 16931 structured XML; structured part is the leading record; anchor to the **15 October 2025** BMF letter (as amended by the 14 July 2025 GoBD change). [5][7][16]
- [ ] Conversion keeps original + converted, linked. [7][16]
- [ ] Append-only audit trail; issued documents locked; corrections via linked Storno/Gutschrift. [9][11][15]
- [ ] Z1/Z2 read-only audit query surface (Z2 on pre-existing evaluations only) + Z3 GDPdU/GoBD Beschreibungsstandard export (`index.xml` + DTD + CSV/ASCII). [9][12][13]
- [ ] Deletion lock until `retention_until` (and while Ablaufhemmung active); deletion-eligibility query for DS-GVO. [1][8]
- [ ] No TSE/DSFinV-K inside the package; document the cash-register boundary. [11]
- [ ] Export layer pluggable for a future DSFinV-BV profile (~2027/2028, § 147b AO; not yet in force). [11][20]

---

## Sources

[1] Bürokratieentlastungsgesetz: Aufbewahrungspflichten verkürzt (Haufe Finance) - https://www.haufe.de/finance/buchfuehrung-kontierung/buerokratieentlastungsgesetz-aufbewahrungspflichten-verkuerzt_186_634670.html
[2] Viertes Bürokratieentlastungsgesetz (BEG IV) - RSM Ebner Stolz - https://www.ebnerstolz.de/de/viertes-buerokratieentlastungsgesetz-494304.html
[3] § 14b UStG - Aufbewahrung von Rechnungen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14b.html
[4] BMF-Schreiben 15.10.2024: Einführung der obligatorischen E-Rechnung; Anpassung des UStAE (NWB Datenbank, Az. III C 2 - S 7287-a/23/10001 :007, BStBl I 2024, S. 1320) - https://datenbank.nwb.de/Dokument/1046425/
[5] BMF: Zur neuen E-Rechnung seit dem 1.1.2025 (Haufe Steuern) - https://www.haufe.de/steuern/finanzverwaltung/weiteres-bmf-schreiben-zur-e-rechnung_164_655070.html
[6] GoBD-konforme Aufbewahrung von Rechnungen 2025 (d.velop) - https://www.d-velop.de/blog/compliance/gobd-konforme-aufbewahrung-von-rechnungen/
[7] BMF: Die E-Rechnung im Kontext der GoBD und des Datenzugriffs (Deloitte Tax-News) - https://www.deloitte-tax-news.de/steuern/indirekte-steuern-zoll/bmf-die-e-rechnung-im-kontext-der-grundsaetze-ordnungsgemaesser-buchfuehrung-und-des-datenzugriffs.html
[8] § 147 AO - Ordnungsvorschriften für die Aufbewahrung von Unterlagen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ao_1977/__147.html
[9] Betriebsprüfung: Datenzugriff Z1-Z3 (Clostermann Wiediger Teckentrup Pietsch) - https://cwtp.de/aktuelles/betriebspruefung-datenzugriff-durch-den-betriebspruefer-z1-z3-zugriff/
[10] Elektronische Betriebsprüfung: Zugriffsmöglichkeiten auf Steuerdaten (Deloitte) - https://www.deloitte.com/de/de/services/tax/analysis/zugriffsmethoden-auf-steuerdaten.html
[11] Digitale Schnittstellen der Finanzverwaltung - DSFinV-K, GoBD-Export, DSFinV-BV (meinbüro) - https://www.meinbuero.de/ratgeber/rechtliches/digitale-schnittstellen-der-finanzverwaltung/
[12] Beschreibungsstandard für die Datenträgerüberlassung (GDPdU/GoBD), index.xml + DTD (Caseware/Audicon) - https://www.caseware.net/fileadmin/user_upload/Beschreibungsstandard-GoBD-GDPdU-01-08-2002.pdf
[13] GDPdU: Alles über den GoBD-Vorgänger (d.velop) - https://www.d-velop.de/blog/compliance/gdpdu/
[14] § 257 HGB - Aufbewahrung von Unterlagen, Aufbewahrungsfristen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/hgb/__257.html
[15] GoBD, 2. Änderung vom 14.07.2025 (Az. IV D 2 - S 0316/00128/005/088) - Bundesfinanzministerium - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html
[16] BMF-Schreiben vom 15.10.2025 zur obligatorischen E-Rechnung (Az. III C 2 - S 7287-a/00019/007/243) - Bundesfinanzministerium - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-10-15-einfuehrung-obligatorische-e-rechnung.pdf
[17] Längere Aufbewahrungsfristen bei Banken, Versicherungen und Wertpapierinstituten (EY Deutschland) - https://www.ey.com/de_de/technical/steuernachrichten/laengere-aufbewahrungsfristen-bei-banken-versicherungen-und-wertpapierinstituten
[18] Steuerhinterziehung effektiver aufdecken: Kabinett verlängert Aufbewahrungsfristen für Buchungsbelege von Banken, Versicherungen und Wertpapierinstituten (BMF Presse, 06.08.2025) - https://www.bundesfinanzministerium.de/Content/DE/Pressemitteilungen/Finanzpolitik/2025/08/2025-08-06-aufbewahrungsfristen-buchungsbelege.html
[19] Kehrtwende bei Aufbewahrungsfristen: Wenn Bürokratieabbau zur Bürokratiezunahme wird (NWB Experten-Blog) - https://www.nwb-experten-blog.de/kehrtwende-bei-aufbewahrungsfristen-wenn-buerokratieabbau-zur-buerokratiezunahme-wird/
[20] BMF-Entwurf zur digitalen Schnittstelle für Buchführungsdaten / DSFinV-BV (EY Deutschland; § 147b AO) - https://www.ey.com/de_de/technical/steuernachrichten/bmf-entwurf-zur-digitalen-schnittstelle-fuer-buchfuehrungsdaten

---

## Open Questions

1. **Exact EGAO transition citation.** The general 8-year shortening resolves uniformly to 1 January 2025, but advisory sources cite the EGAO provision as either **Art. 97 § 19b** or **Art. 97 § 19a Abs. 2** EGAO; the financial-sector reversal sits in the § 19a strand. Confirm the literal BGBl text of both BEG IV and SchwarzArbMoDiG before relying on any edge-case (boundary-of-year) interpretation.

2. **SchwarzArbMoDiG promulgation date and final statutory wording.** Bundeskabinett 6 August 2025 and Bundestag 13 November 2025 are confirmed; the **Bundesrat date and Verkündung/BGBl reference** were not pinned to a primary source here. Confirm the in-force date and the exact statutory mechanism by which the financial-sector carve-out is made *permanent* (vs. merely re-extended), and read the final amended § 147 Abs. 3 AO / § 257 Abs. 4 HGB / Art. 97 EGAO text directly before finalising R1.

3. **Which 15 October letter governs which detail.** Two BMF e-invoice letters (15.10.2024 and 15.10.2025) plus the 14.07.2025 GoBD amendment. Read the **15 October 2025** letter directly to confirm exactly when a *separate human-readable rendering* becomes mandatory ("zusätzliche oder abweichende Informationen") and the precise latitude for archiving outside a GoBD-compliant system, before locking R3/R4/R7.

4. **DSFinV-BV timing and final scope.** Built on the § 147b AO authorization; 2nd Diskussionsentwurf sent 27 January 2025; projected in-force ~31 December of the third year after promulgation (so ~2027/2028, not yet in force in mid-2026). Confirm scope (Außenprüfung + Kassen-Nachschau) and whether any minimum-data requirements exceed the GoBD before committing to a specific export profile beyond the GDPdU/GoBD Beschreibungsstandard.

5. **Financial-sector tenants in the target market.** If Kreditinstitute/Versicherungen/Wertpapierinstitute are in scope, the permanent 10-year carve-out needs precise per-document-date handling and validation against the sector-specific statutory definitions (§ 1 Abs. 1b KWG, § 1 Abs. 1 VAG, § 2 Abs. 1 WpIG) referenced in the financial-sector provisions.
