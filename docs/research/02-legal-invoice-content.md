# Legal Invoice Content (UStG) & Numbering

## Scope and Legal Frame (as of mid-2026)

These notes cover the **mandatory legal content** (Pflichtangaben) of German invoices and the **invoice-numbering** rules that the `john-wink/gobd-invoice` engine must enforce. The governing statutes are the **Umsatzsteuergesetz (UStG)** and the **Umsatzsteuer-Durchführungsverordnung (UStDV)**. The relevant 2024–2026 reforms are:

- **Wachstumschancengesetz** (BGBl. I 2024 Nr. 108, promulgated 27 March 2024) — introduced the domestic **B2B e-invoice mandate** in §14 Abs. 1/Abs. 2 UStG, phased in 2025–2028.
- **Jahressteuergesetz 2024 (JStG 2024)** (promulgated December 2024, in force **1 January 2025**) — reformed **§19 UStG** (Kleinunternehmer), newly introduced **§19a UStG** (EU-wide small-business special reporting procedure / besonderes Meldeverfahren) and **§34a UStDV** (simplified Kleinunternehmer invoice content). NOTE: the §34a/§19 reform was carried by the **JStG 2024**, *not* by the Wachstumschancengesetz — keep these two laws distinct.
- **Viertes Bürokratieentlastungsgesetz (BEG IV)** (promulgated 29 October 2024, in force **1 January 2025**) — shortened the §14b UStG invoice-retention period from 10 to 8 years (see Retention section). NOTE: the draft's "23 Oct 2024" date for BEG IV was the Bundestag-adoption/Verkündung window; the operative fact for the engine is that it took effect 1 January 2025.

All statutory text below is the consolidated version in force in 2025/2026 [1][3][4][9][10]. Currency note: all §-references, thresholds, the VAT rates, the deadlines and the retention period were re-verified in June 2026 against `gesetze-im-internet.de` and `bundesfinanzministerium.de`; corrections versus the original draft are annotated inline.

> **Software framing**: "Invoice" in §14 Abs. 1 UStG is defined by *function*, not by label — "Rechnung ist jedes Dokument, mit dem über eine Lieferung oder sonstige Leistung abgerechnet wird, gleichgültig, wie dieses Dokument im Geschäftsverkehr bezeichnet wird." A *Beleg*, *Leistungsnachweis*, or *Schlussrechnung* that settles a supply is legally an invoice and the engine must apply §14 validation to it regardless of the German document name the package assigns. (Verified: §14 Abs. 1 S. 1 UStG [1].)

## §14 Abs. 4 UStG — Full Mandatory-Fields Table

A standard invoice (no simplification) must contain the following. The legal basis is **§14 Abs. 4 Satz 1 Nr. 1–10 UStG** — there are exactly **10 numbered items** (Nr. 1 through Nr. 10); the reverse-charge note is *not* a separate number but part of the §14a regime (see below) [1][2]. Each row is translated into an explicit software requirement.

| # (Nr.) | German Pflichtangabe (legal term) | English gloss | Legal basis | Software requirement |
|---|---|---|---|---|
| 1 | Vollständiger Name und vollständige Anschrift des leistenden Unternehmers **und** des Leistungsempfängers | Full name + address of *both* supplier and recipient | §14 Abs. 4 S. 1 Nr. 1 | Two complete address blocks; both non-empty. Postfach allowed; abbreviations of legal form permitted. |
| 2 | Steuernummer **oder** USt-IdNr. des leistenden Unternehmers | Supplier tax number *or* VAT-ID | §14 Abs. 4 S. 1 Nr. 2 | Exactly one of the two required (XOR). For Kleinunternehmer with EU cross-border activity a *Kleinunternehmer-Identifikationsnummer* (KU-IdNr.) may also satisfy this — see §34a / §19a below. |
| 3 | Ausstellungsdatum | Issue date | §14 Abs. 4 S. 1 Nr. 3 | Mandatory `issue_date`; immutable once finalized (GoBD). |
| 4 | Fortlaufende Rechnungsnummer (eine oder mehrere Zahlenreihen, einmalig vergeben) | Sequential, *unique* invoice number | §14 Abs. 4 S. 1 Nr. 4 | See "Numbering" section. Must be **unique**, *not necessarily gapless*. |
| 5 | Menge und Art (handelsübliche Bezeichnung) der gelieferten Gegenstände **oder** Umfang und Art der sonstigen Leistung | Quantity + customary description of goods, or scope + type of service | §14 Abs. 4 S. 1 Nr. 5 | Each line item needs quantity, unit and a "handelsüblich" description; a bare "Diverses" fails. |
| 6 | Zeitpunkt der Lieferung / sonstigen Leistung (oder Vereinnahmung bei Anzahlung) | Delivery/service date (Leistungs-/Lieferdatum) | §14 Abs. 4 S. 1 Nr. 6 | Always required even if equal to the issue date; in that case a note such as "Leistungsdatum entspricht Rechnungsdatum" is mandatory (a month is sufficient: "Leistungsmonat Mai 2026"). Critical: do **not** silently omit when equal — see Open Questions. [2] |
| 7 | Entgelt, **nach Steuersätzen und Befreiungen aufgeschlüsselt**, sowie im Voraus vereinbarte Minderungen (Skonto/Rabatt) | Net amount broken down by tax rate / exemption + agreed reductions | §14 Abs. 4 S. 1 Nr. 7 | Per-rate net subtotals; any Skonto/Rabatt condition stated. |
| 8 | Anzuwendender Steuersatz **und** Steuerbetrag **oder** Hinweis auf eine Steuerbefreiung | Applicable rate + tax amount, or exemption note | §14 Abs. 4 S. 1 Nr. 8 | Per rate: rate %, tax amount. If exempt: textual exemption reason (e.g. §4 Nr. … UStG). |
| 9 | In Fällen des §14b Abs. 1 S. 5: Hinweis auf Aufbewahrungspflicht des Leistungsempfängers | Retention-obligation note (private recipients, real-estate works) | §14 Abs. 4 S. 1 Nr. 9 | Only for §14b Abs. 1 S. 5 cases (taxable Werklieferung/sonstige Leistung **im Zusammenhang mit einem Grundstück** to a non-entrepreneur, or to an entrepreneur using the supply for non-business purposes). A general note that the recipient must keep the invoice **two years** suffices [verified, §14 Abs. 4 Nr. 9 + §14b Abs. 1 S. 5; recipient retention = 2 years]. |
| 10 | Bei Abrechnung durch den Leistungsempfänger: Angabe **"Gutschrift"** | "Gutschrift" label when recipient issues the invoice | §14 Abs. 4 S. 1 Nr. 10 | See Gutschrift terminology trap. |

> CORRECTION vs. draft: the draft listed 11 rows and split the reverse-charge note out as its own "Nr. 9". The statute has only **Nr. 1–10**. The retention-obligation note is **Nr. 9** and the "Gutschrift" label is **Nr. 10**. The reverse-charge note lives in **§14a Abs. 5** (not in §14 Abs. 4) — the table above is corrected accordingly. The substance the draft intended is preserved; only the numbering is fixed.

> Standard German VAT rates in 2026: **19 %** (Regelsteuersatz) and **7 %** (ermäßigter Satz) — both unchanged and current (verified June 2026; no general-rate change has been enacted, though sector-specific reduced-rate measures, e.g. for Gastronomie, recur in legislation and should be configurable). The package must not hard-code these as the only options; exemptions and reverse-charge produce 0 %/no-tax lines that still need the corresponding textual note.

## §14 Abs. 2 — Obligation and the Six-Month Deadline

§14 Abs. 2 UStG obliges issuance **within six months** after performance of the supply ("innerhalb von sechs Monaten nach Ausführung der Leistung") in these cases [1]:

1. **Nr. 1** — Supply to **another entrepreneur** (B2B) for their business ("für eine Leistung an einen anderen Unternehmer für dessen Unternehmen"). From the 2025 mandate, in this case the invoice **must be an e-invoice (elektronische Rechnung)** if *both* parties are domestically established (`inländisch`): "die Rechnung ist als elektronische Rechnung auszustellen, wenn der leistende Unternehmer und der Leistungsempfänger im Inland ansässig sind." The e-invoice obligation attaches to **Nr. 1 only**.
2. **Nr. 2** — Supply to a **legal entity that is not an entrepreneur** ("eine juristische Person, die nicht Unternehmer ist").
3. **Nr. 3** — Taxable **Werklieferung / sonstige Leistung im Zusammenhang mit einem Grundstück** (real-estate-related) — here the issuance obligation extends to private recipients (B2C) too, and the §14b Abs. 1 S. 5 retention note (Nr. 9) applies.

**Software requirement**: store `performance_date`; compute an issuance deadline = `performance_date + 6 months`; surface a warning/flag when an unissued document approaches/passes it. Note the *competing*, stricter deadline in §14a (15th of the following month) for cross-border / intra-community cases — the engine should pick the stricter applicable one per document type (see Open Questions on the overlap rule).

### E-invoice phase-in (Wachstumschancengesetz) — precise dates

The mandate is staged; the engine should gate its "must issue structured e-invoice" rule on these dates and the issuer's prior-year turnover [11]:

- **From 1 Jan 2025**: every domestic business (including Kleinunternehmer) must be **able to receive and process** structured e-invoices (EN 16931, e.g. XRechnung/ZUGFeRD). Issuing a paper/PDF invoice is still allowed during the transition.
- **Until 31 Dec 2026**: domestic B2B paper invoices remain permitted; other electronic formats (e.g. plain PDF) only with the recipient's consent.
- **From 1 Jan 2027**: issuers with **prior-year (2026) turnover > 800.000 EUR** must issue structured e-invoices in domestic B2B; smaller issuers may still send "sonstige Rechnungen" through 31 Dec 2027.
- **From 1 Jan 2028**: all domestic B2B issuers must issue structured e-invoices (EDI remains usable if a compliant Meldedatensatz can be extracted).

## Kleinbetragsrechnung — §33 UStDV (Simplified Small-Amount Invoice)

Threshold: **gross total ≤ 250 EUR** ("Eine Rechnung, deren Gesamtbetrag 250 Euro nicht übersteigt", einschließlich USt) — unchanged and current for 2026 [3]. Such an invoice needs only a reduced field set:

| Required | Field |
|---|---|
| ✓ | Full name + address of the **supplier only** (recipient NOT required) |
| ✓ | Ausstellungsdatum (issue date) |
| ✓ | Menge + Art der gelieferten Gegenstände / Umfang + Art der sonstigen Leistung |
| ✓ | **Gross** Entgelt + Steuerbetrag *in one sum* **and** the applicable tax rate (or exemption note) |
| ✗ | Recipient name/address — *not required* |
| ✗ | Sequential invoice number — *not required* |
| ✗ | Supplier tax number / USt-IdNr. — *not required* |
| ✗ | Separate net/tax split — gross + rate is enough |

**Excluded** from the Kleinbetrag simplification (§33 does not apply to "Leistungen im Sinne der §§ 3c, 6a und 13b des Gesetzes"): distance sales / Fernverkauf (§3c), intra-community supplies (§6a), and reverse-charge cases (§13b) — these always need the full §14 set [3]. **Software requirement**: a `Kleinbetragsrechnung` document type that conditionally drops Nr. 1 (recipient), Nr. 2, Nr. 4 and the net/tax split when gross ≤ 250 EUR and none of the exclusions apply.

## §14a UStG — Special Cases

§14a layers *additional* mandatory content on top of §14 in cross-border and special constellations [4]:

- **Reverse charge / §13b**: the note **"Steuerschuldnerschaft des Leistungsempfängers"** is mandatory (§14a Abs. 5: "zur Ausstellung einer Rechnung mit der Angabe 'Steuerschuldnerschaft des Leistungsempfängers' verpflichtet"). The exact German phrase is prescribed; an English-only version is insufficient for a domestic-law-governed invoice (EN 16931 / XRechnung carries it in a structured code, see below).
- **Innergemeinschaftliche Lieferung** (intra-community supply, §6a / §4 Nr. 1 Buchst. b): the invoice must show **both** the supplier's *and* the recipient's USt-IdNr. (§14a Abs. 3: "In der Rechnung sind auch die Umsatzsteuer-Identifikationsnummer des Unternehmers und die des Leistungsempfängers anzugeben") plus a tax-exemption note.
- **Innergemeinschaftliche Dreiecksgeschäfte** (§14a Abs. 7), Reiseleistungen / "Sonderregelung für Reisebüros" (§25, §14a Abs. 6), Differenzbesteuerung / margin scheme "Gebrauchtgegenstände/Kunstgegenstände/Sammlungsstücke und Antiquitäten/Sonderregelung" (§25a, §14a Abs. 6), new vehicles — each has its own §14a note.
- **Deadline**: in §14a Abs. 1 (innergemeinschaftliche sonstige Leistung) and Abs. 3 (innergemeinschaftliche Lieferung) cases the invoice is due **bis zum fünfzehnten Tag des Monats, der auf den Monat folgt, in dem der Umsatz ausgeführt worden ist** — i.e. the **15th of the month following the supply**, stricter than the §14 six-month rule [verified, §14a Abs. 1 + Abs. 3].

## Reverse-Charge — Required Wording

The legally mandated German wording is exactly: **"Steuerschuldnerschaft des Leistungsempfängers"** (the recipient owes the tax) [1][4]. In a reverse-charge invoice the supplier shows **no VAT amount and no VAT rate** (Nettobetrag only), plus this note. In structured e-invoices the equivalent is the EN 16931 VAT category code **`AE` (VAT Reverse Charge)**; the package should set both the human-readable phrase and the code consistently.

## §19 UStG — Kleinunternehmer (2025 Reform)

The §19 reform (via the **Jahressteuergesetz 2024**) took effect **1 January 2025** and is current for 2026 [5][7][9].

### Thresholds (in force from 2025)

| | Old (≤ 2024) | New (≥ 2025) |
|---|---|---|
| Prior-year turnover (Vorjahresumsatz) | ≤ 22.000 EUR | **≤ 25.000 EUR** |
| Current-year turnover (laufendes Jahr) | ≤ 50.000 EUR (forecast) | **≤ 100.000 EUR (hard limit)** |

Verified against §19 Abs. 1 UStG: status applies "soweit der … Gesamtumsatz … im vorangegangenen Kalenderjahr 25 000 Euro nicht überschritten hat und im laufenden Kalenderjahr 100 000 Euro nicht überschreitet" [9].

Critical change in semantics: the new **100.000 EUR is a hard cap, not a beginning-of-year forecast**. (BMF/IHK guidance: "die Umsatzgrenze von 100.000 Euro im laufenden Kalenderjahr ist keine Prognoseentscheidung mehr, sondern eine feste Umsatzgrenze" [5][7].) The moment current-year turnover **exceeds** 100.000 EUR, Kleinunternehmer status ends **immediately** — the *very transaction that breaches the limit* is already taxed at standard VAT, and all subsequent ones too (the rest of the year is no longer protected) [5][7]. **Software requirement**: track running annual turnover; raise a hard stop / status-change event at the 100.000 EUR boundary mid-year, and re-evaluate eligibility against the 25.000 EUR prior-year figure at year start.

### Genuine tax exemption + required note

Since 2025, Kleinunternehmer turnover is a **genuine VAT exemption** — the statute now says the turnover **"ist steuerfrei"** (§19 Abs. 1: "ein von einem im Inland … ansässigen Unternehmer bewirkter Umsatz … ist steuerfrei") [9], not merely "Umsatzsteuer wird nicht erhoben" as before. The invoice must **not** show any VAT and must carry a note that the small-business exemption applies. No verbatim wording is prescribed by law; colloquial form is acceptable as long as it unambiguously identifies the §19 exemption [6][8]. Acceptable examples:
- "Gemäß § 19 UStG wird keine Umsatzsteuer berechnet."
- "Kein Umsatzsteuerausweis aufgrund Anwendung der Kleinunternehmerregelung gemäß § 19 UStG."
- "Steuerfreier Kleinunternehmer (§ 19 UStG)."

> Caveat: §34a UStDV Nr. 5 *does* prescribe that the invoice carry **"ein Hinweis darauf, dass für die Lieferung oder sonstige Leistung die Steuerbefreiung für Kleinunternehmer gilt"** — so while no exact sentence is fixed, the *presence* of a §19-exemption note is now a hard statutory content requirement, not merely good practice. The engine must enforce that such a note exists on every Kleinunternehmer invoice.

### §34a UStDV — simplified Kleinunternehmer invoice content (new since 2025)

§34a UStDV (introduced by JStG 2024, in force 1 January 2025) defines a *reduced* mandatory field set for invoices over §19-exempt supplies — "müssen … mindestens die folgenden Angaben enthalten" [10]:

1. Full name + address of supplier **and** recipient
2. Die dem leistenden Unternehmer vom Finanzamt erteilte Steuernummer, USt-IdNr. **or** the new **Kleinunternehmer-Identifikationsnummer (KU-IdNr.)**
3. Ausstellungsdatum
4. Menge + Art der gelieferten Gegenstände / Umfang + Art der sonstigen Leistung
5. **Entgelt in einer Summe** (no rate/tax split) **plus** the mandatory note: *"einen Hinweis darauf, dass für die Lieferung oder sonstige Leistung die Steuerbefreiung für Kleinunternehmer gilt"*
6. In den Fällen der Ausstellung der Rechnung durch den Leistungsempfänger: die Angabe **"Gutschrift"**

Note: a **sequential invoice number is not listed** among the §34a content requirements (confirmed against the statute text), mirroring the relief intent — but the engine may still assign one for internal GoBD traceability.

> CORRECTION vs. draft — KU-IdNr. format: the draft claimed the KU-IdNr. uses a prefix `DE…-KU`. That is **wrong**. The KU-IdNr. is a German identification number with the **suffix "EX"** (form `DE…EX`), assigned by the **Bundeszentralamt für Steuern (BZSt)**. It is **only relevant for the EU-wide scheme under §19a UStG** — i.e. a German Kleinunternehmer who wants to apply the small-business exemption in *other* EU member states. For a Kleinunternehmer operating **purely domestically, no KU-IdNr. is needed**; the §34a Nr. 2 field is then satisfied by the ordinary Steuernummer or USt-IdNr. The engine should treat the KU-IdNr. as an *optional cross-border* identifier and validate the `DE…EX` shape, not `DE…-KU` [5][7].

### E-invoicing for Kleinunternehmer — verified precisely

This is a common misconception, so stated exactly [10][11]:

- Kleinunternehmer are **exempt from *issuing* structured e-invoices** — §34a UStDV S. 2 lets them *always* transmit a "sonstige Rechnung" (paper or simple PDF/other electronic format): "Eine Rechnung nach Satz 1 kann abweichend von § 14 Absatz 2 Satz 2 des Gesetzes immer als sonstige Rechnung … übermittelt werden", even after the 2028 full mandate.
- They are **NOT exempt from *receiving* e-invoices**: since **1 January 2025** *all* domestic businesses, including Kleinunternehmer, must be technically able to **receive and process** structured e-invoices (a plain e-mail inbox suffices) [11].

So the precise framing: Kleinunternehmer are largely exempt from the *issuing* mandate but **must support receiving**. The package's receive/parse path must not exclude Kleinunternehmer.

## Invoice Numbering Rules — §14 Abs. 4 Nr. 4

The number must be a **fortlaufende, einmalig vergebene Rechnungsnummer** ("sequential, uniquely assigned") [1][12]. Key legally-confirmed points:

- **Uniqueness is the only hard requirement** — each number may be assigned only once by the issuer.
- **Strict gaplessness (Lückenlosigkeit) is NOT legally required.** A gap in the sequence does not by itself invalidate the invoice. *However*, large unexplained gaps can prompt the Finanzamt to estimate (Schätzung) undeclared revenue, so in practice gaplessness is advisable but not a legal precondition for input-tax deduction [12].
- **One or several number ranges (Zahlenreihen) are allowed.** Per-series numbering is explicitly permitted: separate ranges by time period (year/month/day), branch, location, or document class are valid as long as each number is unique within the issuer and the invoice can be unambiguously assigned to its range.
- Digits, letters, and combinations are all permitted.

**Software requirements**: enforce uniqueness per issuer (DB unique constraint across the whole numbering namespace, not just per series); allow configurable per-series prefixes/patterns (e.g. `RE-2026-000123`, `AN-…` for Angebot which is *not* an invoice, `GU-…` for Gutschrift); allow per-series counters; do **not** force gaplessness, but log/track gaps for audit. Note Angebot (quote) and Kostenvoranschlag (cost estimate) are **not** invoices and must use separate non-invoice series.

## Gutschrift Terminology Trap

§14 Abs. 4 Nr. 10 reserves the word **"Gutschrift"** for the legal meaning: an invoice **issued by the recipient** of the supply (self-billing / Abrechnungsgutschrift) [1]. This collides with everyday German where "Gutschrift" also means a *commercial credit note* (a correction/refund document, properly a **Rechnungskorrektur** or **Stornorechnung**).

- Using the label "Gutschrift" on a document that is in fact a credit/correction note is a **terminology error** and can wrongly trigger VAT liability under §14c (unberechtigter Steuerausweis — issuing a document with an open tax statement creates a Gefährdung des Steueraufkommens; the tax can then be owed under §14c Abs. 2) [verified, §14c UStG].
- **Software requirement**: the package must only emit the literal label "Gutschrift" for the self-billing case (recipient-issued invoice). For commercial credit/correction documents use a different label such as **"Rechnungskorrektur"** or **"Stornorechnung"** / "Korrekturrechnung", never the bare word "Gutschrift". Make this a guarded, type-driven decision, not free text.

## Leistungs-/Lieferdatum Requirement (recap, load-bearing)

§14 Abs. 4 Nr. 6 requires the **time of supply** even when it equals the invoice date. The package must always populate a performance date and, when it coincides with the issue date, render the note "Leistungsdatum entspricht Rechnungsdatum" (or equivalent). A calendar **month** is an acceptable granularity. Omitting this is a frequent input-tax-deduction defect [2].

## Retention (cross-cutting, GoBD-adjacent) — §14b UStG

Since BEG IV (in force **1 Jan 2025**), the retention period for invoices under **§14b Abs. 1 S. 1 UStG** was **shortened from 10 to 8 years** ("acht Jahre"). The relief applies to **all invoices whose retention period had not yet expired as of 31 December 2024** [13]. Verified June 2026 against the consolidated §14b UStG text ("acht Jahre aufzubewahren") and corroborated by IHK/Deloitte BEG IV summaries.

Caveats the engine must respect:
- **§22 Abs. 1 UStG records remain at 10 years.** The BMF expressly confirmed (BMF letter of 8 July 2025) that umsatzsteuerliche **Aufzeichnungen** under §22 Abs. 1 (and §22f) UStG stay at **zehn Jahre** — the 8-year cut applies to Rechnungen/Buchungsbelege (§14b Abs. 1 S. 1 UStG, §147 Abs. 3 S. 1 AO), not to those VAT records.
- **The period does not end while documents still matter for tax** (§147 Abs. 3 S. 5 AO): e.g. open Betriebsprüfung, ongoing §15a Vorsteuerberichtigung in real-estate cases, or unverjährte Festsetzungsfrist — the document must be kept until those are resolved even past 8 years.

**Software requirement**: default invoice retention = **8 years** from the end of the issuing calendar year, but make it configurable; apply a separate **10-year** default to §22-class VAT records; and **never auto-purge** documents flagged as still tax-relevant (open audit / §15a / unverjährt).

## Summary of Differences (cheat-sheet for the engine)

| Field | Standard §14 | Kleinbetrag ≤250 EUR (§33 UStDV) | Reverse-Charge (§13b/§14a) | Kleinunternehmer (§19/§34a) |
|---|---|---|---|---|
| Recipient name+address | Required | **Not required** | Required | Required |
| Sequential number | Required | **Not required** | Required | **Not required** (per §34a; assign internally for GoBD) |
| Supplier tax no./USt-IdNr | Required | **Not required** | Required (+ recipient USt-IdNr for ig. Lieferung) | Required (Steuernr./USt-IdNr.; KU-IdNr. `DE…EX` only for §19a cross-border) |
| VAT rate + amount | Required | Gross+rate (one sum) | **No VAT shown** | **No VAT shown** |
| Special note | (Nr. 9/10 if applicable) | — | "Steuerschuldnerschaft des Leistungsempfängers" (§14a Abs. 5) | §19 exemption note (mandatory per §34a Nr. 5) |
| Net/tax split | Required | Not required (gross sum) | Net only | Single sum only |
| Must issue as e-invoice (B2B, domestic) | Yes (phased: receive 2025; issue >800k 2027; all 2028) | n/a (≤250 EUR / typically B2C) | Yes (if domestic B2B) | **No** (§34a S. 2 exemption from *issuing*; must still *receive*) |

## Sources

[1] § 14 UStG — Ausstellung von Rechnungen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14.html
[2] Neue Pflichtangaben für Rechnungen — IHK Region Stuttgart - https://www.ihk.de/stuttgart/fuer-unternehmen/recht-und-steuern/steuerrecht/umsatzsteuer-national/neue-pflichtangaben-fuer-rechnungen-684834
[3] § 33 UStDV — Kleinbetragsrechnung (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustdv_1980/__33.html
[4] § 14a UStG — Zusätzliche Pflichten bei der Ausstellung von Rechnungen in besonderen Fällen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14a.html
[5] Kleinunternehmerregelung, Umsatzgrenzen, § 19 UStG — IHK Region Stuttgart - https://www.ihk.de/stuttgart/fuer-unternehmen/recht-und-steuern/steuerrecht/umsatzsteuer-national/kleinunternehmerregelung-in-der-umsatzsteuer-1843632
[6] Kleinunternehmer-Hinweis gem. § 19 UStG mit Muster — FreeFinance - https://www.freefinance.com/de-de/kleinunternehmer/kleinunternehmer-hinweis.html
[7] BMF-Schreiben: Sonderregelung für Kleinunternehmer (18.03.2025) — Bundesfinanzministerium - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-03-18-sonderregelung-kleinunternehmer.pdf?__blob=publicationFile&v=3
[8] Kleinunternehmer-Rechnung 2025 — kleinunternehmer.de - https://www.kleinunternehmer.de/rechnungsstellung.htm
[9] § 19 UStG — Besteuerung der Kleinunternehmer (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__19.html
[10] § 34a UStDV — Rechnungen von Kleinunternehmern (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustdv_1980/__34a.html
[11] BMF FAQ: Einführung der obligatorischen E-Rechnung zum 1. Januar 2025 — Bundesfinanzministerium - https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
[12] Pflichtangaben in Rechnungen — fortlaufende Rechnungsnummer (rechnungswesen-info.de) - https://www.rechnungswesen-info.de/rechnungen_rechnungsnummer.html
[13] Verkürzung der Aufbewahrungsfristen für Rechnungen ab 2025 (BEG IV) — KMLZ - https://www.kmlz.de/en/shortening-retention-periods-invoices-2025-what-you-need-bear-mind
[14] § 19a UStG / besonderes Meldeverfahren & KU-IdNr. ("EX"-Nummer), BZSt — Europäische Kleinunternehmerregelung (EU-KU-Regelung) - https://www.bzst.de/DE/Unternehmen/Umsatzsteuer/EU-KU-Regelung/eu_ku_regelung_node.html
[15] § 14b UStG — Aufbewahrung von Rechnungen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14b.html
[16] § 14c UStG — Unrichtiger oder unberechtigter Steuerausweis (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14c.html
[17] Aufbewahrungsfristen: umsatzsteuerliche Aufzeichnungen (§ 22 UStG) bleiben zehn Jahre (BMF-Schreiben 08.07.2025) — IWW - https://www.iww.de/pfb/steuern-und-recht-aktuell/aufbewahrungsfristen-umsatzsteuerliche-aufzeichnungen-bleiben-zehn-jahre-aufbewahrungspflichtig-n168758

## Open Questions

1. **KU-IdNr. validation pattern**: confirmed the format is `DE…EX` (suffix "EX"), issued by BZSt under the §19a EU-KU-Regelung, and needed only for cross-border use. Remaining: the exact digit/length structure of the numeric core and whether the engine should validate it via a BZSt check or only a syntactic regex before relying on it for §34a Nr. 2.
2. **Sequential number for Kleinunternehmer invoices**: §34a UStDV omits a fortlaufende Nummer from the required content. Should the engine genuinely skip numbering §19 invoices, or still number them internally for GoBD audit traceability (likely the latter — confirm against a GoBD/§146 AO-specific source whether internal numbering is expected even when not invoice-mandatory).
3. **Leistungsdatum-equals-Rechnungsdatum in structured EN 16931 / XRechnung**: which BT field carries the supply date (BT-72 Actual delivery date / BG-14 invoicing period) and whether a free-text note (BT-22) is still needed when the structured ActualDeliveryDate already equals IssueDate, or whether the structured field alone satisfies §14 Abs. 4 Nr. 6.
4. **§14 six-month deadline vs §14a 15th-of-following-month deadline overlap**: for a document qualifying under both (e.g. a domestic-leg B2B supply that is also an innergemeinschaftliche Lieferung), confirm the §14a Abs. 3 15th-of-following-month rule governs (stricter) — verify the precedence in Abschnitt 14a UStAE per document type before encoding a single "stricter wins" rule.
5. **§19a EU-wide scheme content obligations**: the EU-wide small-business scheme (≤ 100.000 EUR EU-wide turnover, BZSt special reporting procedure) requires quarterly Umsatzmeldungen and the KU-IdNr. Confirm whether it imposes any *additional invoice-content* obligations (beyond carrying the KU-IdNr.) that the package must support for cross-border Kleinunternehmer, or whether invoice content is governed by the destination member state's own small-business rules.
6. **Sector reduced-rate currency**: confirm no enacted change to the 19 %/7 % rates and track any pending Gastronomie or other sector reduced-rate legislation that would require the engine's rate table to be updated for 2026/2027.
