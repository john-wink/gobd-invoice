# GoBD Compliance Requirements

> Currency note: Verified against the official BMF letter of 14.07.2025 (the 2nd GoBD amendment), gesetze-im-internet.de (§§ 146, 147 AO; § 14b UStG), and the Viertes Bürokratieentlastungsgesetz / Art. 97 § 19a EGAO transitional provisions, as current in mid-2026. The consolidated GoBD in force is the version of 28.11.2019 as amended 11.03.2024 and 14.07.2025. Always re-check the version that applies to the period in question and against the official consolidated text before hard-coding any threshold-, deadline- or §-reference-based behaviour.

## Scope and purpose

These notes are the authoritative GoBD reference for the framework-only Laravel package `john-wink/gobd-invoice`. GoBD = **Grundsätze zur ordnungsmäßigen Führung und Aufbewahrung von Büchern, Aufzeichnungen und Unterlagen in elektronischer Form sowie zum Datenzugriff** (Principles for the proper keeping and retention of books, records and documents in electronic form, and for data access). It is **not a statute** but an administrative interpretation (Verwaltungsanweisung) by the German Federal Ministry of Finance (Bundesministerium der Finanzen, BMF) that binds the tax administration and tells taxpayers how the underlying law — chiefly **§§ 145–147, 158, 200 AO (Abgabenordnung)** and **§§ 238, 239, 257 HGB** — is applied to IT systems [1][6][8]. It applies to anyone with bookkeeping/recording duties under tax law, including the e-invoicing flows our package generates.

### Governing version chain (verify every fact against the version that applies)

| Version | Reference (GZ) | Published / effective | What it does |
|---|---|---|---|
| Original | IV A 4 - S 0316/13/10003 | 14.11.2014 | First GoBD (superseded) |
| 1st reissue | IV A 4 - S 0316/19/10003 :001, BStBl I 2019, 1269 | **28.11.2019** | The base text still in force; all Randziffer (Rz, marginal number) references below are to it [1][8] |
| 1st amendment | BStBl I 2024, 374 | published **11.03.2024**, applicable **from 01.04.2024** (the amended text itself states "gelten ab dem 1. April 2024") | "Datenträgerüberlassung" → "Datenüberlassung" (Z3 may go via data-exchange platforms; authorities may process/retain data on mobile systems regardless of location); new appendix (Anhang) on data transfer; EBCDIC/AS-400 formats no longer supported after 31.12.2024; EU record-keeping (§§ 146 Abs. 2a/2b AO) no longer needs prior approval, while third-country storage still does [2][6] |
| 2nd amendment | IV D 2 - S 0316/00128/005/088, BStBl I 2025, 1502 | **14.07.2025**, **"mit sofortiger Wirkung"** / new Rz 185: "mit Wirkung vom 14. Juli 2025 anzuwenden" | Adapts the GoBD to the mandatory inland-B2B **E-Rechnung** (obligatory since 01.01.2025); amends Rz 76, 118, 119, 121, 125, 127, 131, 133, 166, 175 and adds new Rz 185 (verified against the official BMF PDF) [3][7] |

The currently authoritative consolidated text is the **GoBD of 28.11.2019, as amended 11.03.2024 (BStBl I 2024, 374) and 14.07.2025 (BStBl I 2025, 1502)** [3][7]. The 14.07.2025 letter itself names its base as "veröffentlicht mit BMF-Schreiben vom 28. November 2019, BStBl I S. 1269, geändert durch BMF-Schreiben vom 11. März 2024, BStBl I S. 374". When stating any GoBD requirement in code comments or docs, cite the Rz and note it reflects the 14.07.2025 consolidation.

> Verified correction: the 2nd-amendment Rz list above (76, 118, 119, 121, 125, 127, **131, 133, 166, 175** + new 185) is confirmed verbatim from the official BMF PDF. Some secondary summaries (e.g. one Haufe article) incorrectly list "135, 166" — Rz 135 is only *referenced* in the new Rz 131 text (as the condition for permitted format conversion), it is not itself amended.

## The core principles (Grundsätze) — with software translation

### Nachvollziehbarkeit und Nachprüfbarkeit (traceability and verifiability) — Rz 30–37

A *sachverständiger Dritter* (knowledgeable third party, i.e. an auditor) must be able, **in angemessener Zeit** (within reasonable time), to gain an overview of the transactions and the situation of the business [1][6]. Every business transaction must be traceable **in its origin and processing without gaps** ("in ihrer Entstehung und Abwicklung lückenlos verfolgen") — this is the **Prüfpfad** (audit trail), required in both directions:

- **Progressive Prüfbarkeit**: Beleg (voucher) → Grundaufzeichnung/Journal (basic record) → Konto (account) → Bilanz/GuV (financial statement).
- **Retrograde Prüfbarkeit**: financial statement → account → journal → voucher [1][6].

**Software requirement:** every finalized document must carry an immutable link to its source data and to any resulting bookings/exports; the package must be able to reconstruct, for any document, the full chain from raw input to output and back, for the entire retention period.

### Vollständigkeit (completeness) — Rz 36 ff.

Every transaction must be recorded completely and only once; gapless capture. **Software requirement:** no transaction may be silently dropped; gapless or systematically justified numbering (see below); a registered document must always be discoverable.

### Richtigkeit (correctness) — Rz 38 ff.

Records must reflect the actual transactions and map to the correct accounts/periods. **Software requirement:** validation (Erfassungskontrollen, Plausibilitätsprüfungen) before a document is finalized; correctness of VAT, totals, dates, currency.

### Zeitgerechtheit / zeitgerechte Buchung und Erfassung (timeliness) — Rz 45–51

Concrete, load-bearing deadlines (28.11.2019 text, unchanged by the 2024/2025 amendments) [4][6]:

- **Kasseneinnahmen und Kassenausgaben** (cash receipts/payments): record **täglich** (daily) — Rz 48. This mirrors the statutory rule in **§ 146 Abs. 1 Satz 2 AO** ("Kasseneinnahmen und Kassenausgaben sind täglich festzuhalten"), verified against gesetze-im-internet.de.
- **Unbare Geschäftsvorfälle** (non-cash transactions): record in a Grundbuch/subsidiary ledger **within 10 days** — Rz 47/50. Note: this 10-day window is a GoBD/administrative interpretation; § 146 AO itself only requires recording "zeitgerecht" without naming a fixed number of days.
- **Periodenweise Buchung** (periodic posting): permitted only if non-cash transactions are booked **by the end of the following month (bis zum Ablauf des Folgemonats)** *and* organisational measures (running numbering, separate folders, electronic Grundbuchaufzeichnungen) guarantee the documents are not lost and remain assignable before posting — Rz 50 [4].

**Software requirement:** record a *capture timestamp* and a *document/business date* on every document; surface overdue items; never allow a document's effective date to be back-dated silently after finalization.

### Ordnung (orderliness) — Rz 52 ff.

Records must be systematically and clearly organized (no chaotic intermingling of cash/non-cash, taxable/non-taxable). **Software requirement:** consistent classification, deterministic ordering, clear separation of document types.

### Unveränderbarkeit (immutability) — Rz 58–60, 107–112 (the most important for this package)

Anchored in **§ 146 Abs. 4 AO** and **§ 239 Abs. 3 HGB** (both verified against gesetze-im-internet.de): a record may not be changed in such a way that the original content is no longer ascertainable ("darf nicht in einer Weise verändert werden, dass der ursprüngliche Inhalt nicht mehr feststellbar ist") [6]. The GoBD demands that the DV-Verfahren (IT procedure) guarantee that **all information once entered into the processing pipeline (Beleg, Grundaufzeichnung, Buchung) can no longer be suppressed, or overwritten/deleted/changed/falsified without being made evident (ohne Kenntlichmachung)**; already-entered information may not be replaced by new data without Kenntlichmachung [1][9]. Changes and deletions of/at electronic bookings or records **must be protokolliert (logged)** — Rz 59 [6]. Settings/parameterisation changes must also be logged [9].

The GoBD explicitly lists ways immutability may be achieved — **hardware** (e.g. WORM / unalterable media), **software** (Sicherungen, Sperren, **Festschreibungen** [write-once/finalization], **Löschmerker** [deletion markers instead of physical delete], **automatische Protokollierung** [automatic logging], **Historisierungen**, **Versionierungen**), or **organisational** (access-rights concepts) — Rz 110/112 [9]. Plain storage in a file system does **not** satisfy immutability by itself [9].

Explicitly **impermissible** (Rz 109/112): exporting data to an Office tool, editing it unprotokolliert and re-importing; keeping batch/pre-entry bookings open until and beyond year-end so all entries remain freely changeable; switching off logging of changes; irreversibly overwriting prior-year financial data; use of "Zapper"/Phantomware to make unlogged changes [1][9].

**Software requirements (the heart of `gobd-invoice`):**
- A document has a lifecycle; once **finalized (festgeschrieben)** it is append-only — no UPDATE/DELETE of the legal content.
- **Storno statt Löschen**: cancel via a counter-document (Storno) or a documented correction, never by physical deletion or silent overwrite.
- Every change is recorded with **who / what / when / old→new** (a full, tamper-evident change history).
- Optional content **hashing / Prüfsumme** and hash-chaining of finalized records and of the audit log itself (tamper evidence). Note: the GoBD names hashing only as *one example* among software measures (alongside Festschreibung, Löschmerker, Protokollierung, Historisierung, Versionierung) — it is a strong recommended control, not a stand-alone legal mandate. Where the package touches cash registers, the separate **KassenSichV/TSE** regime (§ 146a AO) imposes its own cryptographic requirements.
- Document numbering is **finalized at finalization** and never reused.

### Aufbewahrung (retention) — and the 2024/2025 deadline reform

Retention duties follow the general tax/commercial rules (**§ 147 AO**, **§ 257 HGB**, **§ 14b UStG**), all verified against gesetze-im-internet.de.

**2024/2025 change** (Viertes Bürokratieentlastungsgesetz / BEG IV, G. v. 23.10.2024, BGBl 2024 I Nr. 323): the retention period for **Buchungsbelege** (accounting vouchers, including invoices) was **shortened from 10 to 8 years** [5]:

- **§ 147 Abs. 3 AO**: 8 years for "die in Absatz 1 Nummer 4 aufgeführten Unterlagen" (Buchungsbelege).
- **§ 14b Abs. 1 UStG**: invoices (issued and received) → **acht Jahre** (8 years) — confirmed by direct read of the statute.
- **§ 257 Abs. 4 HGB**: matching shortening on the commercial-law side.

> Verified correction on applicability: the 8-year period applies via the transitional rule in **Art. 97 § 19a EGAO** "erstmals auf Unterlagen, deren Aufbewahrungsfrist [nach altem Recht] **am 31. Dezember 2024 noch nicht abgelaufen ist**" — i.e. it took effect 01.01.2025 but only for documents not already expired at the 31.12.2024 cut-off. (The draft's phrasing "not yet expired on 01.01.2025" was imprecise; the statutory cut-off is 31.12.2024.) For taxpayers under BaFin supervision (Kreditinstitute, Versicherungs- und Wertpapierinstitute) there is a special rule (Art. 97 § 19a Abs. 3 EGAO): originally a one-year delay (cut-off 01.01.2026), and following **Art. 17 G. v. 22.12.2025, BGBl 2025 I Nr. 369** these supervised institutions effectively continue under the rules in force on 31.12.2024 (the longer/old retention). This carve-out is unlikely to apply to typical `gobd-invoice` users but must not be hard-coded away.

**Caution:** the 8-year clock does **not** end while the Festsetzungsfrist (§ 169 AO) runs or a Betriebsprüfung/Verfahren is open — effective retention can exceed 10 years (§ 147 Abs. 3 Satz: "Die Aufbewahrungsfrist läuft jedoch nicht ab, soweit und solange die Unterlagen für Steuern von Bedeutung sind, für welche die Festsetzungsfrist noch nicht abgelaufen ist") [5].

Other categories (per § 147 Abs. 1/3 AO, verified):

- **Books and records (Bücher/Aufzeichnungen, incl. journals/Grundbücher), inventories (Inventare), financial statements (Jahresabschlüsse/Bilanzen), opening balance sheets (Eröffnungsbilanzen)** — Nr. 1 — and **customs documents (Nr. 4a, Art. 15/163 UZK)** → **10 years** (these were *not* shortened).
- **Commercial/business letters (Handels-/Geschäftsbriefe, incl. business emails) and all other tax-relevant documents** → **6 years** [5][10].

The retention period begins at the end of the calendar year in which the last entry/preparation/receipt/creation occurred (§ 147 Abs. 4 AO).

Documents must be kept **in the received/created format** (Rz 131, as recast 14.07.2025) — an incoming XML e-invoice must be retained as XML, not printed [3][7].

## Verfahrensdokumentation (process documentation) — Rz 151–153

Each DV-system needs its own Verfahrensdokumentation that mirrors the organisationally and technically intended process. It typically has four parts: **allgemeine Beschreibung** (general description), **Anwenderdokumentation** (user documentation), **technische Systemdokumentation** (technical/system documentation), **Betriebsdokumentation** (operations documentation) — Rz 153 [1]. For electronic documents it must cover the full path "von der Entstehung der Information über die Indizierung, Verarbeitung und Speicherung, das eindeutige Wiederfinden, die maschinelle Auswertbarkeit, die Absicherung gegen Verlust und Verfälschung und die Reproduktion" (Rz 152) [1]. It must show **both current and historical** procedure states for the whole retention period (Rz 34) and itself be **versioned with a change history** [1][8].

**Software requirement:** ship a Verfahrensdokumentation template/skeleton tied to the package's actual finalization, numbering, logging, retention and export behaviour, with a versioned changelog. Document automatic bookings (e.g. recurring/Dauersachverhalte, Rz 81) so they are traceable.

## Internes Kontrollsystem (IKS, internal control system) — Rz 100–102

With reference to § 146 AO the taxpayer must set up, exercise **and log** controls. The GoBD-Leitfaden lists the mandatory Schutzmechanismen (protective mechanisms) verbatim [9]:

- Zugangs- und Zugriffsberechtigungskontrollen (access and authorization controls) based on documented authorization concepts.
- **Funktionstrennungen** (segregation of duties).
- **Erfassungskontrollen** (input controls: error hints, plausibility checks).
- Abstimmungskontrollen (reconciliation controls on input).
- Verarbeitungskontrollen (processing controls).
- Schutzmaßnahmen against intended and unintended falsification of programs, data and documents [9].

**Software requirement:** role/permission gates around finalization, cancellation and export; segregation of who can create vs. finalize vs. cancel; validation on capture; reconciliation hooks; and logging of the controls themselves.

## Maschinelle Auswertbarkeit and Datenzugriff (Z1/Z2/Z3) — Rz 126–127, 165–175

Records must remain **maschinell auswertbar** (machine-evaluable) — retained in a structured, analysable form, not flattened to an image where the structure is lost. For hybrid e-invoices (e.g. ZUGFeRD) the **structured XML part must be kept** and must not be destroyed by format conversion (e.g. to TIFF); the PDF (human-readable) part need only be kept if it carries additional or differing tax-relevant info such as Buchungsvermerke or qualified electronic signatures (Rz 125, Beispiel 10; Rz 119/131 as recast 14.07.2025 — verified against the official BMF text) [3][7]. Under § 147 Abs. 6 AO the tax authority may require three access types:

- **Z1** unmittelbarer Zugriff (read-only into the system),
- **Z2** mittelbarer Zugriff (the taxpayer runs evaluations to the authority's spec; Rz 166 was recast 14.07.2025 to allow the taxpayer/an engaged third party to provide the evaluation in machine-evaluable format or grant read-only access afterwards),
- **Z3** Datenüberlassung (hand over data in machine-evaluable form, since the 11.03.2024 amendment also via data-exchange platforms) — Rz 165–175 [2][6].

**Software requirement:** store structured data structured (do not only render to PDF); provide a **Z3 export** in a machine-evaluable, documented format (CSV/structured) preserving the audit trail; preserve incoming e-invoice XML byte-for-byte.

## E-invoice context the package must respect

Mandatory inland-B2B **E-Rechnung** was introduced by the **Wachstumschancengesetz** (§ 14 UStG). An e-invoice is a structured electronic invoice that is created, transmitted and received in a structured format conforming to **EN 16931** and that permits automated, media-break-free processing. The German formats **XRechnung** and **ZUGFeRD from version 2.0.1** qualify — **except the ZUGFeRD MINIMUM and BASIC-WL profiles, which do not** carry all VAT-mandatory data and therefore are not valid e-invoices [7]. All VAT-mandatory information (§§ 14, 14a UStG) must be in the structured part.

Timeline (transitional rules in **§ 27 Abs. 38 UStG**) [7]:

- **Receipt** capability mandatory **since 01.01.2025** for all (VAT) businesses — including Kleinunternehmer.
- **Issuance** — paper / other electronic formats (e.g. PDF, with the recipient's consent) allowed through **31.12.2026** for all; through **31.12.2027** for issuers whose **prior-year total turnover did not exceed €800,000**; an EDI procedure that does not itself meet the e-invoice requirements may likewise be used through end of 2027.
- From **01.01.2027**: issuers with prior-year turnover **> €800,000** must send e-invoices.
- From **01.01.2028**: **all** inland-B2B issuers must send e-invoices.

Exceptions remain for **Kleinbetragsrechnungen ≤ €250** brutto (§ 33 UStDV) and **Fahrausweise** (§ 34 UStDV), as well as B2C invoices and invoices to non-domestic recipients [7]. **Kleinunternehmer** (§ 19 UStG) are **not obliged to issue** e-invoices but, like everyone, must be **able to receive** them since 01.01.2025.

The 14.07.2025 GoBD amendment makes retaining only the structured part sufficient (Rz 119/131) and removes the need to store a bildhafte Kopie of outgoing invoices created by a Fakturierungsprogramm if an inhaltlich identisches Mehrstück (content-identical duplicate) can be reproduced on demand (Rz 76) [3].

> 2025 currency caveats relevant to the package's e-invoicing flows: (a) the **Kleinunternehmer reform** effective 01.01.2025 raised the § 19 UStG thresholds to €25,000 (prior year, now a hard limit) and €100,000 (current year, now a firm — no longer prognosis-based — ceiling), and made Kleinunternehmer turnover steuerfrei; a new EU-wide Kleinunternehmer scheme exists in § 19a UStG. (b) The BMF issued a second, clarifying e-invoicing letter on **15.10.2025** (Umsatzsteuer-Anwendungserlass). Confirm any threshold-driven behaviour against the current UStG / BMF text before hard-coding.

## Concrete technical requirements checklist for `gobd-invoice`

The package MUST enforce:

1. **Immutable finalized documents.** Distinct lifecycle (Entwurf → finalisiert/festgeschrieben). On finalization the legal content becomes append-only; no UPDATE/DELETE on finalized records (DB-level guards, model-level guards) [1][9].
2. **Storno instead of delete.** Corrections/cancellations only via Storno/Gutschrift or a documented, logged correction document — never silent overwrite or physical delete (Löschmerker, not DELETE) [1][6][9].
3. **Complete audit log: who / what / when / old→new.** Append-only, immutable change history for every document and for configuration/parameter changes; the log itself must be tamper-evident (e.g. hash-chained) [6][9].
4. **Gapless or systematically justified numbering**, finalized at finalization and never reused; if not strictly gapless, gaps must be explainable in the Verfahrensdokumentation (Vollständigkeit) [1].
5. **Timestamps.** Capture timestamp + document/business date on every record; enforce timeliness windows (cash daily per § 146 Abs. 1 S. 2 AO; non-cash 10 days; periodic by end of following month — Rz 47/48/50) at least as warnings; no silent back-dating after finalization [4].
6. **Audit trail (Prüfpfad), both directions.** Persist immutable links Beleg ↔ Grundaufzeichnung ↔ Buchung ↔ output; be able to navigate progressively and retrogradely [1][6].
7. **Optional hashing / Prüfsumme** of finalized documents and hash-chaining for integrity (Festschreibung evidence) — recommended control, not a stand-alone GoBD mandate; mandatory under KassenSichV/TSE only where cash registers are involved [9].
8. **Preserve original structured formats** (e-invoice XML byte-exact; no lossy conversion that drops structure) and keep data **machine-evaluable** (Rz 119/125/131) [3][7].
9. **Z3 / Datenüberlassung export** in a documented, machine-evaluable format including the audit trail [2][6].
10. **IKS hooks:** role/permission gates and **segregation of duties** for create vs. finalize vs. cancel vs. export; input validation/plausibility on capture; reconciliation hooks; log the controls [9].
11. **Retention metadata:** stamp each document with its retention category and computed end-date — **8 years** for Buchungsbelege/invoices (§ 147 Abs. 3 / § 14b Abs. 1 UStG / § 257 Abs. 4 HGB, for documents not yet expired on 31.12.2024), **10 years** for books/records/journals/inventories/financial statements (§ 147 Abs. 1 Nr. 1), **6 years** for business letters and other documents — and never purge while a record is still within retention or relevant for an open assessment (Festsetzungsfrist, § 169 AO). Do not auto-delete; require explicit, logged disposal. Make retention configurable per category/jurisdiction (e.g. BaFin-supervised entities, non-tax laws such as SGB) [5][10].
12. **Versioned Verfahrensdokumentation** generated/maintained alongside the engine, with its own change history (Rz 151–153, 34) [1][8].
13. **Data security as an ordnungsmäßigkeit prerequisite:** unprotected/unproducible data renders the bookkeeping formally not ordnungsgemäß (Rz 104) — so backups, access protection and reliable producibility are in-scope, not optional [1].

### Practical Laravel mapping (non-binding implementation hints)

- Append-only event/audit tables; model events that reject mutation of finalized records; a `finalized_at` + `document_hash` (+ `previous_hash` chain) on each document.
- A `DocumentNumberSequence` per type/year guaranteeing gapless allocation at finalization.
- A Storno relationship (`reverses_document_id`) rather than soft/hard delete of finalized rows.
- Policy/permission gates separating capture, finalization, cancellation and export roles.
- An export command producing a documented, structured Z3 dataset plus the audit log.

## Sources

[1] GoBD – Begriff, BMF-Schreiben, Grundsätze (consolidated overview with Rz citations) - https://www.bosch-bertel.de/service/links_infos/gobd/gobd_ger.pdf
[2] BMF-Schreiben 11.03.2024 – Änderung der GoBD (Datenüberlassung, neuer Anhang); BDO summary (confirms applicability from 01.04.2024) - https://www.bdo.de/de-de/insights/aktuelles/assurance/die-neuen-gobd-gelten-seit-dem-1-april-2024-datenuberlassung-und-neuer-anhang-im-fokus
[3] BMF-Schreiben 14.07.2025 – 2. Änderung der GoBD (E-Rechnung), official PDF (GZ IV D 2 - S 0316/00128/005/088, BStBl I 2025, 1502; verified Rz list and Rz 185) - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.pdf
[4] Haufe – GoBD: Vollständige und zeitnahe Aufzeichnungen (Rz 48/50 timeframes) - https://www.haufe.de/steuern/finanzverwaltung/neufassung-der-gobd-kommentierung/gobd-vollstaendige-und-zeitnahe-aufzeichnungen_164_496516.html
[5] Verkürzte Aufbewahrungsfrist für Buchungsbelege (8 Jahre, BEG IV, ab 01.01.2025) - https://www.landberatung.de/buchungsbelege-aufbewahrungsfrist-hat-sich-geaendert/
[6] IHK München – Ordnungsgemäße Buchführung: GoBD (overview of principles and Datenzugriff) - https://www.ihk-muenchen.de/ratgeber/steuern/finanzverwaltung/grundsaetze-elektronische-buchfuehrung-gobd/
[7] Haufe – Elektronische Rechnung wird Pflicht: E-Rechnung im Überblick (EN 16931, § 14/§ 27 Abs. 38 UStG, 2025/2027/2028 timeline, €800,000 threshold) - https://www.haufe.de/steuern/gesetzgebung-politik/elektronische-rechnung-wird-pflicht-e-rechnung-im-ueberblick_168_605558.html
[8] Anpassung der GoBD aufgrund gesetzlicher Änderungen – Haufe (version chain, Verfahrensdokumentation versioning) - https://www.haufe.de/steuern/finanzverwaltung/anpassung-der-gobd-aufgrund-gesetzlicher-aenderungen_164_656348.html
[9] GoBD in der Praxis – Leitfaden (PSP/AWV): IKS Schutzmechanismen, Unveränderbarkeit (Festschreibungen, Löschmerker, Protokollierung, Historisierung, Versionierung; Rz 110/112) - https://www.psp.eu/assets/pdfs/gobd_psp_leitfaden.pdf
[10] IHK München – Aufbewahrungspflichten von Steuerunterlagen (10/8/6-year categories) - https://www.ihk-muenchen.de/ratgeber/steuern/finanzverwaltung/aufbewahrungsfristen/
[11] § 147 AO (gesetze-im-internet.de) – retention categories and § 147 Abs. 3 8-year period - https://www.gesetze-im-internet.de/ao_1977/__147.html
[12] § 14b UStG (gesetze-im-internet.de) – 8-year invoice retention - https://www.gesetze-im-internet.de/ustg_1980/__14b.html
[13] § 146 AO (gesetze-im-internet.de) – daily cash recording (Abs. 1 S. 2) and immutability (Abs. 4) - https://www.gesetze-im-internet.de/ao_1977/__146.html
[14] Art. 97 § 19a EGAO transitional provision (BMF AO-Handbuch; buzer.de for the 22.12.2025 amendment, BGBl 2025 I Nr. 369) - https://www.buzer.de/gesetz/1652/al233399-0.htm
[15] BMF FAQ / second e-invoicing letter context (Kleinunternehmer receipt obligation; § 27 Abs. 38 UStG) - https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html

## Open Questions

1. The verbatim Rz of the IKS in the 28.11.2019 base text could not be read from the bot-protected official AO-Handbuch; the Schutzmechanismen list and immutability wording are drawn from the official BMF amendment PDF, the GoBD-Leitfaden, and the bosch-bertel consolidation. The 14.07.2025 amendment PDF was read directly and confirms the Rz numbers it changes, but a direct read of the *consolidated* official text (ao.bundesfinanzministerium.de) is still recommended to pin the precise Rz for IKS (commonly cited as Rz 100–102) and Verfahrensdokumentation (Rz 151–153).
2. The 8-year Buchungsbelege retention (BEG IV) is well-sourced and verified against § 147 Abs. 3 AO / § 14b Abs. 1 UStG, but the package should keep retention configurable per category/jurisdiction: the effective period can be extended by open Festsetzungsfristen/Betriebsprüfungen (§ 169 AO); BaFin-supervised entities have a special carve-out (Art. 97 § 19a Abs. 3 EGAO, as amended by Art. 17 G. v. 22.12.2025); and some non-tax laws (e.g. SGB) impose longer keeps. Confirm the exact current wording of Art. 97 § 19a EGAO for any institution-specific behaviour.
3. The e-invoice issuance turnover threshold for the 2027 phase (prior-year B2B turnover > €800,000) and the staged dates sit in § 27 Abs. 38 UStG; confirm against the final UStG/BMF text (incl. the 15.10.2025 BMF letter) before hard-coding any threshold-based behaviour, as transitional rules were still settling in 2025–2026.
4. Whether GoBD strictly mandates cryptographic hashing/hash-chaining: the GoBD names hashing only as one example among software measures (Festschreibung, Löschmerker, Protokollierung, Historisierung, Versionierung). Treat hashing as a strong recommended control, not a legal must, and verify whether any sector-specific rule (KassenSichV/TSE, § 146a AO) imposes it where the package touches cash registers.
5. ZUGFeRD profile validity: confirm the current set of EN 16931-compliant ZUGFeRD profiles (≥ 2.0.1, excluding MINIMUM and BASIC-WL) against the latest ZUGFeRD/Factur-X specification and BMF guidance before validating or generating hybrid invoices.
