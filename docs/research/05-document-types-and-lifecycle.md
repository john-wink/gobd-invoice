# Document Taxonomy & Lifecycle State Machine

## Scope and how to read these notes

This document models every German business document the package must generate or track, and translates the underlying law into explicit software requirements. Three legal regimes run through everything below and the engine must keep them conceptually separate:

- **Civil/contract law (BGB, HGB, VOB/B)** — governs *bindingness*: is a document an offer, an acceptance, a contract, a proof of performance?
- **VAT law (UStG, UStDV)** — governs *Pflichtangaben* (mandatory invoice content), when VAT is owed, and the e-invoice format mandate.
- **Bookkeeping/retention law (AO, HGB, GoBD)** — governs *Unveraenderbarkeit* (immutability), sequential numbering, and retention.

A document can be civil-law-irrelevant but VAT-critical (an Anzahlungsrechnung) or civil-law-critical but VAT-irrelevant (an Angebot). The data model must carry the right flags for each axis, not a single "type" enum that conflates them.

**Currency note (verified mid-2026):** All time-sensitive facts below state the date/version they apply to and were re-verified in June 2026 against gesetze-im-internet.de, bundesfinanzministerium.de and the Deutsche Bundesbank. The German rules changed materially in 2024–2026 (Wachstumschancengesetz, Buerokratieentlastungsgesetz IV / BEG IV, Jahressteuergesetz 2024, plus the 1 Jan 2026 Gastronomie VAT change in the Steueraenderungsgesetz 2025). Re-verify before each release; several figures (Basiszinssatz, e-invoice timeline thresholds, codelist versions) move on fixed schedules.

---

## 1. Document-by-document reference

### 1.1 Angebot (quote / offer)

- **Purpose:** Propose goods/services at stated terms. No statutory content requirements.
- **Legal weight:** An Angebot is an *Antrag* (offer) under **§ 145 BGB**: the offeror is **bound** to it unless bindingness was excluded — verified primary text: *"Wer einem anderen die Schliessung eines Vertrags antraegt, ist an den Antrag gebunden, es sei denn, dass er die Gebundenheit ausgeschlossen hat."* [3] Two flavours the engine must model as a flag:
  - **Verbindlich (binding):** offeror is bound for the *Bindungsfrist* (acceptance window). Default rule of § 145 BGB. If no period is stated, § 147 BGB applies a "reasonable" window; § 146 BGB extinguishes the offer on rejection or on lapse of the acceptance period [3].
  - **Freibleibend / unverbindlich (non-binding):** bindingness excluded via a *Freizeichnungsklausel* ("unverbindlich", "Preise freibleibend", "solange der Vorrat reicht") — the recipient's "acceptance" becomes a new offer the supplier must confirm [3].
- **Numbering:** No GoBD-mandated sequential numbering (it is not a Buchungsbeleg). The package should still assign an internal Angebot number for referencing/conversion, but it must **not** consume the invoice number sequence.
- **VAT:** None owed; an Angebot triggers no VAT. Show prices net/gross for clarity only.
- **Lifecycle:** draft → sent → accepted (→ converts to Auftrag) / rejected / expired. No immutability obligation, but freezing a snapshot on "sent" is good practice.
- **Software requirements:** store `binding: bool`, `valid_until: date`; on expiry auto-transition to `expired`; never reuse the invoice sequence; support conversion to Auftrag/Auftragsbestaetigung carrying line items forward.

### 1.2 Kostenvoranschlag / Kostenanschlag (cost estimate)

- **Legal anchor (verified):** The governing provision is **§ 649 BGB ("Kostenanschlag")**, *not* § 650 BGB. The 2018 Bauvertragsrecht reform (Gesetz zur Reform des Bauvertragsrechts of 28 April 2017, in force **1 Jan 2018**) renumbered it: it was § 650 BGB until 31 Dec 2017; **§ 650 BGB now covers "Werklieferungsvertrag; Verbrauchervertrag ueber die Herstellung digitaler Produkte"** — confirmed against primary text [7][8]. **Many secondary sources still cite the old "§ 650 BGB" — the package's docs/UI strings should reference § 649 BGB.**
- **Bindingness:** By default a Kostenanschlag is **unverbindlich**: per **§ 649 Abs. 1 BGB**, the contractor assumes **no guarantee for the accuracy of the estimate** ("ohne dass der Unternehmer die Gewaehr fuer die Richtigkeit des Anschlags uebernommen hat") [8]. It can be made *verbindlich* by agreement, in which case the contractor is bound to the figure.
- **Overrun rule (§ 649 BGB):**
  - **§ 649 Abs. 1:** if the work cannot be done without a **wesentliche Ueberschreitung** (substantial overrun) of the estimate, the *Besteller* (customer) may **terminate (kuendigen)**; the contractor then receives only the limited compensation that § 649 Abs. 1 Satz 2 grants via the **§ 645 Abs. 1** measure (compensation for partial work performed and outlays) [8].
  - **§ 649 Abs. 2:** the contractor must **notify the customer without delay (unverzueglich Anzeige zu machen)** as soon as such an overrun is to be expected (*"Ist eine solche Ueberschreitung des Anschlags zu erwarten, so hat der Unternehmer dem Besteller unverzueglich Anzeige zu machen"*); failure to notify gives rise to a damages claim [1][8].
  - **"wesentlich" threshold:** **not fixed by statute.** Case law/commentary commonly treat roughly **15–20 %** over the estimate as the tolerance band; above that it is "wesentlich" and the notification + termination rules bite. This figure is fact-specific and judge-made, not a statutory number — treat it as a configurable default, not a hard rule [1]. (See Open Questions.)
- **VAT:** none owed at estimate stage.
- **Software requirements:** flags `binding: bool`, `tolerance_percent` (default ~15–20, configurable, with a doc note that the precise figure is case-law-driven, not statutory); when actual/forecast cost exceeds estimate × (1 + tolerance), raise a "wesentliche Ueberschreitung" event so the user can issue the **§ 649 Abs. 2** notification and the customer's termination right is documented. Keep this distinct from Angebot: Kostenvoranschlag has its own legal regime.

### 1.3 Auftragsbestaetigung (order confirmation)

- **Purpose/weight:** Confirms a contract has formed. Where the customer's order is the *Annahme* of a binding Angebot, the contract is already concluded (§§ 145–147 BGB) and the Auftragsbestaetigung is merely declaratory; where the Angebot was freibleibend, the Auftragsbestaetigung *is* the operative acceptance that forms the contract [3].
- **Numbering/VAT:** no statutory numbering, no VAT.
- **Lifecycle:** created from an accepted Angebot/Auftrag → the contractual basis for later Rechnung/Abschlagsrechnung.
- **Software requirements:** model as the hinge of the conversion chain (Angebot → Auftrag/Auftragsbestaetigung → Rechnung). Carry a `contract_formed_at` timestamp; this is the civil-law event, separate from any VAT event.

### 1.4 Lieferschein (delivery note)

- **Purpose:** Documents what was delivered and when; commonly the basis for the *Leistungsdatum* (date of supply) later cited on the invoice.
- **Legal weight:** No general statutory obligation to issue one and it is not a VAT invoice, but it is a *Handelsbrief*/business record once issued. It carries no VAT line and confers no payment claim by itself.
- **Retention:** as a *Handelsbrief*, **6 years** (§ 257 Abs. 4 HGB / § 147 Abs. 3 AO) — shorter than invoices/Buchungsbelege (now 8 years; see §4) [10].
- **Software requirements:** optional document linked to an order; capture delivery date to prefill the invoice's *Leistungsdatum*; no number-sequence consumption with invoices.

### 1.5 Rechnung (invoice)

- **Purpose/weight:** Asserts a payment claim and is the VAT-relevant document enabling the recipient's input-tax deduction (Vorsteuerabzug).
- **Mandatory content — § 14 Abs. 4 UStG** (verified against primary text; the full Pflichtangaben list remains in Abs. 4 Nr. 1–10 and was **not** moved out of § 14) [2]. The invoice **must** contain:
  1. Full name + address of supplier **and** recipient
  2. Supplier's *Steuernummer* or *USt-IdNr.*
  3. Invoice date (*Ausstellungsdatum*)
  4. A **unique, sequential invoice number** (fortlaufende Rechnungsnummer; § 14 Abs. 4 Nr. 4: *"eine fortlaufende Nummer ... die zur Identifizierung der Rechnung vom Rechnungsaussteller einmalig vergeben wird"*)
  5. Quantity + type of goods / scope + type of service
  6. Date of supply / performance (*Leistungsdatum*)
  7. Remuneration (Entgelt) broken down by tax rate / exemption
  8. Applicable tax rate and tax amount, **or** a note on the exemption
  9. Where applicable, the recipient's storage-obligation note (§ 14b cases)
  10. The word **"Gutschrift"** in self-billing cases (§ 14 Abs. 4 Nr. 10; see 1.10)

| Requirement | Rule | Software requirement |
|---|---|---|
| Sequential number | § 14 Abs. 4 Nr. 4 UStG [2] | Gap-free, per-series sequence; assigned on finalization, never reused/edited |
| Issue deadline | within **6 months** of performance, in the B2B cases of § 14 Abs. 2 Satz 2 UStG (*"innerhalb von sechs Monaten nach Ausfuehrung der Leistung"*) [2] | Warn if finalizing later than 6 months after Leistungsdatum |
| Small-amount invoice | **Kleinbetragsrechnung ≤ 250 EUR Gesamtbetrag (gross)** (§ 33 UStDV) needs only reduced fields (no recipient, no separate tax amount — Entgelt + tax amount may be stated as one sum, rate suffices) [9] | Branch validation by gross total ≤ 250 EUR |
| Standard VAT rates | 19 % regular / 7 % reduced (verified current as of 2026, § 12 UStG) | Configurable rate table with effective-date validity |

- **VAT rate currency caveat (2026):** Standard 19 % / reduced 7 % remain in force per § 12 UStG. Note a category change effective **1 Jan 2026**: *Restaurant- und Verpflegungsdienstleistungen* (food in gastronomy, **excluding beverages**) were permanently moved from 19 % to **7 %** by the Steueraenderungsgesetz 2025. The rate table must therefore be keyed by service category *and* effective date, not a single global rate.
- **VAT timing:** standard *Soll-Versteuerung* (accrual) — VAT owed when performed/invoiced (§ 13 Abs. 1 Nr. 1 lit. a Satz 1 UStG: tax arises at the end of the Voranmeldungszeitraum in which the supply was performed); *Ist-Versteuerung* (cash basis, § 20 UStG) is an opt-in the engine should support as a company setting.
- **Software requirements:** a finalize step that locks the record (immutability, §4), assigns the sequential number, validates all § 14 Abs. 4 fields, and flags Kleinbetrag vs full invoice.

### 1.6 Abschlagsrechnung / Teilrechnung (progress / partial invoice)

- **Purpose:** Bills a defined, already-rendered part of a larger contract (typical in Bau/Handwerk; cf. § 632a BGB Abschlagszahlungen, § 14 Nr. 1 VOB/B Abschlagsrechnungen).
- **VAT:** a full invoice for the partial *Leistung* — VAT is owed and separately shown on the partial amount.
- **Software requirements:** link multiple Abschlagsrechnungen to one order; track cumulative net + VAT billed so the Schlussrechnung can deduct them (see 1.8).

### 1.7 Anzahlungs- / Vorauszahlungsrechnung (advance-payment invoice)

- **Purpose:** Bills *before* performance (deposit/prepayment).
- **VAT — critical distinction:** VAT becomes due on **receipt of payment** (Mindest-Ist-Versteuerung, **§ 13 Abs. 1 Nr. 1 lit. a Satz 4 UStG**, verified: *"Wird das Entgelt oder ein Teil des Entgelts vereinnahmt, bevor die Leistung oder die Teilleistung ausgefuehrt worden ist, so entsteht insoweit die Steuer mit Ablauf des Voranmeldungszeitraums, in dem das Entgelt oder das Teilentgelt vereinnahmt worden ist"*) — i.e., earlier than for a normal accrual invoice [2][11]. The document must make clear it is an Anzahlungs-/Vorauszahlungsrechnung [11].
- **Software requirements:** mark `vat_due_on: payment`; record actual payment receipt date as the VAT trigger; feed the paid advance into the Schlussrechnung deduction logic.

### 1.8 Schlussrechnung (final invoice) — the classic § 14 compliance trap

- **The rule (§ 14 Abs. 5 Satz 2 UStG, verified):** in the *Endrechnung/Schlussrechnung*, the previously received *Teilentgelte* (the net advances/progress amounts already invoiced) **and the VAT amounts attributable to them** **must be deducted** (*"Wird eine Endrechnung erteilt, sind in ihr die vor Ausfuehrung der Lieferung ... vereinnahmten Teilentgelte und die auf sie entfallenden Steuerbetraege abzusetzen, wenn ueber die Teilentgelte Rechnungen im Sinne der Absaetze 1 bis 4 ausgestellt worden sind"*) [2][6].
- **The classic error:** showing VAT on the *full* contract sum in the Schlussrechnung *and* having already shown VAT on each Abschlags-/Anzahlungsrechnung → the supplier owes the VAT **twice** under **§ 14c Abs. 1 UStG** (unrichtiger Steuerausweis) until corrected. This is not theoretical: the FG Baden-Wuerttemberg held that VAT under § 14c Abs. 1 Satz 1 UStG is owed where, contrary to § 14 Abs. 5 Satz 2 UStG, the tax amounts on advances received before performance were not deducted in the final invoice [4][6]. The deduction is not optional cosmetics; it is a correctness requirement.
- **Software requirements (must implement, not suggest):**
  - Compute the gross final total, then on the same document list each prior Abschlags-/Anzahlungsrechnung with **its net amount and its VAT amount**, subtract both, and show the remaining net + VAT + amount due.
  - Validation gate: a Schlussrechnung tied to an order with prior advance/progress invoices **cannot finalize** unless those prior nets *and* their VAT are deducted. Block on a "VAT shown twice" check.
  - Reference each deducted invoice by its number and date.

### 1.9 Teilzahlung (recording a partial payment)

- **Purpose:** This is a **payment event against a document**, not a document type. Distinguish from Teilrechnung (which is a partial *invoice*).
- **Software requirements:** payment ledger per invoice; statuses derive from `sum(payments)` vs `amount_due` → `partially_paid` / `paid`; never mutate the invoice record to reflect payment — payments are separate immutable rows. Partial payment does not alter the issued invoice's VAT.

### 1.10 Gutschrift — the terminology trap (two distinct meanings)

The single word "Gutschrift" denotes two unrelated things; conflating them can risk an unintended **§ 14c UStG** tax discussion [4][5]:

| | Kaufmaennische Gutschrift (credit note) | Gutschrift im Sinne § 14 UStG (self-billing) |
|---|---|---|
| What it is | The supplier reduces/corrects its own earlier invoice (a *Rechnungskorrektur*) | The **recipient** of the supply issues the invoice *for* the supplier |
| Who issues | The supplier | The customer/recipient |
| Legal basis | Civil/accounting correction | **§ 14 Abs. 2 Satz 4 UStG** (verified): allowed only **if agreed in advance** — *"sofern dies vorher vereinbart wurde (Gutschrift)"* [2] |
| Mandatory wording | Should be labelled "Rechnungskorrektur"/"Stornorechnung" — **not** "Gutschrift" | **Must** bear the word "Gutschrift" (§ 14 Abs. 4 Nr. 10 UStG) [2] |

- **The trap:** labelling a *credit note* "Gutschrift" historically prompted § 14c arguments. The **current administrative/judicial view is settled the other way**: under the UStAE (Abschn. 14c.1) and BFH case law, merely using the word "Gutschrift" for a document that is *not* a § 14 Abs. 2 self-billing Gutschrift does **not** by itself trigger § 14c liability [5]. The risk is in the *substance* (a wrong/excess VAT amount), not the label. Nonetheless the package should still avoid the ambiguous label for credit notes and reserve "Gutschrift" strictly for self-billing, to avoid recipient confusion and Vorsteuer disputes.
- **Software requirements:** two separate document types — `credit_note` (corrects own invoice, references original) and `self_billing_invoice` (requires a stored prior agreement flag and emits the literal label "Gutschrift" + § 14 Abs. 4 fields). Never let one UI control produce both.

### 1.11 Stornorechnung / Rechnungskorrektur (cancellation & correction)

- **Core GoBD principle:** a finalized invoice is **never deleted or overwritten** — you cancel/correct by issuing a *new* document (Stornieren und Neubuchen) [GoBD; §4 below].
- **Two paths:**
  - **Stornierte Rechnung (full cancellation):** issue a Stornorechnung that mirrors the original with **negative amounts** (including negative VAT), carries its **own new sequential number**, and references the original invoice's number + date [12].
  - **Berichtigte Rechnung (correction):** correct via a document that specifically and unambiguously references the invoice being corrected (number + date) and contains the missing/corrected data. **§ 31 Abs. 5 UStDV** is the provision that *permits* the correction (*"Eine Rechnung kann berichtigt werden, wenn ..."*) and prescribes the reference to the original. **Correction note (corrected claim):** § 31 Abs. 5 UStDV itself does **not** state that a correction has retroactive effect to the original issue date. The **retroactivity of an invoice correction (Rueckwirkung der Rechnungsberichtigung)** for the Vorsteuerabzug is derived from EuGH case law (Senatex, C-518/14, and Barlis 06, C-516/14) and follow-on BFH rulings, not from the text of § 31 Abs. 5 UStDV. There is no fixed statutory time limit in § 31 Abs. 5; cite the case-law basis, not the regulation, for the retroactive effect [12]. Where the original showed excess/unauthorised VAT, **§ 14c UStG** governs the correction [4][12].
- **Software requirements:** `cancel(invoice)` creates a linked Stornorechnung (negated lines, new number, `references_invoice_id`); `correct(invoice)` creates a berichtigte Rechnung linked the same way; the original stays immutable and visible; status of the original moves to `cancelled` (or `corrected`) without data loss.

### 1.12 Mahnung (dunning)

- **Sequence (no statutory number of stages):** a *Zahlungserinnerung* (friendly reminder) followed by escalating Mahnstufen (1./2./3. Mahnung) is convention, not law — one Mahnung suffices for *Verzug* [1].
- **Verzug (default) — § 286 BGB:** the debtor is in default after a Mahnung following due date; **at the latest 30 days after due date (Faelligkeit) and receipt of an invoice or equivalent payment statement** (for *Entgeltforderungen*, § 286 Abs. 3 BGB) even **without** a Mahnung [1]. (Verified: *"Der Schuldner einer Entgeltforderung kommt spaetestens in Verzug, wenn er nicht innerhalb von 30 Tagen nach Faelligkeit und Zugang einer Rechnung ... leistet; dies gilt gegenueber einem Schuldner, der Verbraucher ist, nur, wenn auf diese Folgen in der Rechnung ... besonders hingewiesen worden ist."* The automatic 30-day rule applies against a **consumer only if they were specifically warned of it** on the invoice/payment statement.)
- **Verzugszinsen — § 288 BGB (verified):** **5 percentage points** over the *Basiszinssatz* where a consumer is involved (§ 288 Abs. 1); **9 percentage points** over the Basiszinssatz for *Entgeltforderungen* where **no consumer** is involved, i.e. B2B (§ 288 Abs. 2) [1]. The **Basiszinssatz is variable and reset twice yearly** (§ 247 BGB, announced by the Bundesbank each 1 Jan and 1 Jul). **Verified currency: the Bundesbank announcement of 1 Jan 2026 keeps the Basiszinssatz unchanged at 1.27 %**, giving 6.27 % (consumer) / 10.27 % (B2B) for H1 2026 [1]. **Do not hard-code the rate — it changes 1 Jul 2026.**
- **Mahnpauschale — § 288 Abs. 5 BGB:** a flat **40 EUR** lump sum where the debtor of an Entgeltforderung is **not a consumer** (B2B default) [1].
- **Software requirements:** Basiszinssatz must be a dated, editable table (effective-from dates); interest = principal × (Basis + 5 or + 9) pro-rata by days in default; pick the spread by a `debtor_is_consumer` flag; auto-add 40 EUR for B2B; track Mahnstufe per invoice; allow auto-transition to `overdue` at due date + grace, and compute the § 286 Abs. 3 30-day fallback (and gate the consumer auto-default on a stored "warning printed" flag).

### 1.13 Leistungsnachweis / Stundennachweis (proof of performance)

- **Purpose:** Evidence of work actually performed — hours, materials, equipment — central to *Stundenlohnarbeiten* in Handwerk/Bau and services.
- **Legal usefulness — VOB/B (citation corrected):** under the VOB/B the contractor must render a *prueffaehige* (verifiable) Abrechnung — the prüffähige Schlussrechnung/Aufmass duties sit in **§ 14 VOB/B**. The specific *Stundenlohnzettel* / *Stundennachweis* regime is in **§ 15 VOB/B**: for *Stundenlohnarbeiten* the Stundenlohnzettel (listing hours worked plus material/equipment/transport) must be submitted in the locally usual rhythm (typically daily or weekly), and the *Auftraggeber* (client) **must return the certified Stundenlohnzettel without delay, at the latest within 6 Werktage (working days) of receipt** (§ 15 Nr. 3 VOB/B); if the client does not return them in time, they are **deemed accepted (Anerkennungsfiktion)** [13]. Courts emphasise the contractor's **burden of proof**: hourly work is "volatile" and can rarely be reconstructed later, so timely, signed records are what make the Werklohn claim enforceable [13].
- **Software requirements:** model line items of type hours/material/equipment with date, rate, and a `signed_off_by`/`signed_at` field; track the **6-Werktage return window** and an `auto_accepted` flag for the Anerkennungsfiktion; allow conversion of an accepted Leistungsnachweis into an Abschlags-/Schlussrechnung; capture the submission and return/acceptance dates to support the VOB/B evidentiary chain. (This package already surfaces net prices on Leistungsnachweis positions — keep the proof and pricing roles distinct.)

---

## 2. E-invoice (E-Rechnung) format mandate — verified timeline (BMF)

Mandate applies to **domestic B2B** supplies. Confirmed against the BMF FAQ and the BMF Schreiben of 15.10.2024 / 15.10.2025 [11], cross-checked against IHK/DATEV [4]:

| Date | Obligation |
|---|---|
| **1 Jan 2025** | Every domestic business must be **able to receive** a structured e-invoice (no opt-out, no turnover floor) [11] |
| 1 Jan 2025 – **31 Dec 2026** | Issuers *may still send* paper/other-format (incl. PDF) invoices, **with recipient consent** [11] |
| 1 Jan 2027 – **31 Dec 2027** | The paper/other-format option ends 1 Jan 2027 for issuers with **prior-year turnover > 800,000 EUR**; issuers with **prior-year turnover ≤ 800,000 EUR** may continue paper/other formats through 31 Dec 2027. EDI not yet EN-16931-conformant is tolerated through end of 2027 [11] |
| **1 Jan 2028** | **All** domestic B2B invoices must be structured e-invoices, regardless of turnover [11] |

- **Compliant formats:** must conform to **EN 16931 (CEN)**. In practice **XRechnung** and **ZUGFeRD ≥ 2.0.1** (hybrid PDF/A-3 + XML) qualify — **except the ZUGFeRD profiles MINIMUM and BASIC-WL**, which do **not** satisfy the VAT invoice requirements [11].
- **Exemptions from the *issuing* mandate:** Kleinbetragsrechnung **≤ 250 EUR** (§ 33 UStDV), Fahrausweise (transport tickets), **B2C** supplies, and Kleinunternehmer *issuing* (§ 34a UStDV / § 19 UStG, exempted from issuing by the BEG IV-related changes) are exempt from *issuing* a structured e-invoice (they must still be **able to receive** one) [9][11].
- **Software requirements:** generate EN 16931-valid XRechnung and ZUGFeRD ≥ 2.0.1 (CII/UN-CEFACT XML embedded in PDF/A-3) — and refuse to emit MINIMUM/BASIC-WL as legal e-invoices; an inbound parser for receiving; a per-company "must-issue-e-invoice" determination computed from the dated thresholds above (the 800k EUR prior-year turnover test drives the 2027 cut-over).

## 3. Kleinunternehmer reform 2025 (§ 19 UStG)

Reworked by Jahressteuergesetz 2024, effective **1 Jan 2025** [14]:
- Thresholds (verified): **≤ 25,000 EUR** prior-year turnover (raised from 22,000 EUR) **and** **≤ 100,000 EUR** current year (replacing the old 50,000 EUR forecast). The 100,000 EUR limit is now a **hard ceiling, not a forecast**: crossing **100,000 EUR mid-year flips the very transaction that breaches it (and everything after) to standard taxation immediately**, going forward [14].
- Legal nature changed from "VAT not levied" (Steuer wird nicht erhoben) to a **Steuerbefreiung (VAT exemption)** aligned with EU law; Kleinunternehmer invoices need a note on the exemption [14].
- **Transition-year note (2025):** because 2024 turnover still contained VAT under the old regime, the 25,000 EUR prior-year test for the 2025 status is applied to the 2024 *gross* receipts (the practical cut-off being ~29,750 EUR = 25,000 × 1.19). Document this if the package back-computes 2025 eligibility.
- **Software requirements:** track both thresholds with running current-year totals; on crossing 100,000 EUR, switch the breaching and all subsequent invoices to VAT-bearing; emit the § 19 exemption note on Kleinunternehmer invoices; Kleinunternehmer invoices show **no VAT line**.

## 4. GoBD immutability, numbering & retention

- **Unveraenderbarkeit (immutability):** finalized/booked records must not be silently changed; corrections only via *stornieren und neubuchen* with a documented audit trail [GoBD].
- **Sequential numbering:** invoices need a gap-free unique sequence (§ 14 Abs. 4 Nr. 4 UStG) [2]; assigned only at finalization.
- **Retention (verified; reduced by BEG IV, BGBl. 2024 I Nr. 323, effective 1 Jan 2025):**

| Document class | Period | Basis (verified) |
|---|---|---|
| **Buchungsbelege incl. invoices** | **8 years** (was 10) | § 147 Abs. 3 AO / § 257 Abs. 4 HGB — *"die in Absatz 1 Nummer 4 aufgefuehrten Unterlagen acht Jahre"* [10] |
| Handelsbriefe (e.g. Lieferschein, Angebot correspondence) | 6 years | § 257 Abs. 4 HGB (Nr. 2/3) [10] |
| Handelsbuecher, Inventare, Jahresabschluss, Lagebericht, Bilanzen | 10 years (unchanged) | § 257 Abs. 4 HGB (Nr. 1) [10] |

- **Sector exception:** for credit institutions, insurers and investment firms, Buchungsbelege (Nr. 4) stay at **10 years** — verify if any such customer is in scope [10].
- Retention clock starts at the **end of the calendar year** the document was created / the last entry was made [10]. The 8-year rule applies to records whose old 10-year period had **not yet expired on 31 Dec 2024**; invoices issued before 1 Jan 2017 need no longer be retained [10].
- **Software requirements:** per-document-class retention metadata (8/6/10) computed from year-end; documents flagged retained-until and never hard-deleted before expiry; immutable storage of finalized invoices + their XML/PDF rendering; full audit log of every state transition.

## 5. Proposed document state machine

A single shared machine, with per-type allowed transitions. The **finalize** transition is the pivot that triggers GoBD immutability **and** sequential-number assignment for VAT documents.

```
draft ──finalize──> finalized (IMMUTABLE; number assigned for invoices) ──issue/send──> issued
issued ──record_payment(partial)──> partially_paid ──record_payment(rest)──> paid
issued ──due_date+grace / §286 Abs.3──> overdue ──record_payment──> paid
issued|partially_paid|overdue ──cancel──> cancelled   (emits linked Stornorechnung)
issued ──correct──> corrected         (emits linked berichtigte Rechnung)
draft ──expire (Angebot/KV)──> expired
```

Transition rules:
- **`finalize`** on a VAT document (Rechnung, Abschlags-, Anzahlungs-, Schlussrechnung, Storno, self-billing Gutschrift): assigns sequential number, locks the record (immutability), runs § 14 Abs. 4 validation. For **Schlussrechnung** it additionally runs the §1.8 advance-deduction gate.
- **`cancel` / `correct`:** never delete; create a new linked document; original moves to `cancelled`/`corrected` but stays immutable and visible.
- **`expire`:** only Angebot/Kostenvoranschlag (no immutability/number consequences).
- Non-VAT documents (Angebot, Kostenvoranschlag, Auftragsbestaetigung, Lieferschein, Leistungsnachweis) may be finalized for workflow purposes but do **not** consume the invoice sequence.

## 6. Conversion flows

```
Angebot ─accept─> Auftrag/Auftragsbestaetigung ─┬─> Rechnung
                                                ├─> Abschlagsrechnung(*) ─┐
Kostenvoranschlag ─accept─> Auftrag             ├─> Anzahlungsrechnung(*) ─┤
Leistungsnachweis ─signed─> (Abschlags/Schluss) │                         │
                                                └─> Schlussrechnung <──────┘
                                                     (deducts all * nets + their VAT)
Any Rechnung ─cancel─> Stornorechnung ; ─correct─> berichtigte Rechnung
Overdue Rechnung ─> Zahlungserinnerung -> Mahnung (1..n)
```

Each conversion copies line items forward and stores a `source_document_id` link so the audit chain (offer → contract → invoices → final invoice) is reconstructable, and so the Schlussrechnung can enumerate prior advances for the § 14 Abs. 5 Satz 2 deduction.

## 7. Consolidated "must-implement" checklist

1. Separate `type` from three orthogonal axes: civil bindingness, VAT relevance, immutability/retention.
2. Gap-free invoice sequence assigned **only** at `finalize`; never reused; non-VAT docs use separate sequences.
3. § 14 Abs. 4 UStG field validation, with Kleinbetrag (≤ 250 EUR gross) reduced-field branch.
4. **Schlussrechnung deduction gate** — block finalize unless prior advance/progress nets **and their VAT** are deducted (prevents § 14c Abs. 1 double-VAT).
5. Anzahlungsrechnung: VAT due on payment receipt (§ 13 Abs. 1 Nr. 1 lit. a Satz 4 UStG), record receipt date.
6. Two distinct Gutschrift types; reserve the literal label "Gutschrift" for § 14 Abs. 2 Satz 4 self-billing (prior-agreement flag required).
7. Cancel/correct create linked negated/corrected documents; originals immutable; § 31 Abs. 5 UStDV correction support, with retroactive-effect treatment grounded in EuGH/BFH case law (Senatex/Barlis), not in the regulation text.
8. Mahnung: dated Basiszinssatz table (1.27 % from 1 Jan 2026, re-verify 1 Jul 2026), +5 pp (consumer) / +9 pp (B2B) by consumer flag, 40 EUR B2B Pauschale, § 286 Abs. 3 30-day fallback gated on a consumer-warning flag.
9. Kostenvoranschlag overrun engine keyed to **§ 649 BGB** (not § 650), ~15–20 % configurable tolerance, § 649 Abs. 2 notification event.
10. E-invoice: emit EN 16931-valid XRechnung + ZUGFeRD ≥ 2.0.1 (reject MINIMUM/BASIC-WL); inbound parser; per-company issuing-mandate determination on the 2025/2026/2027/2028 + 800k EUR timeline.
11. Kleinunternehmer § 19: dual thresholds 25k / 100k EUR, hard mid-year flip, Steuerbefreiung exemption note, no VAT line.
12. Retention: 8 years for invoices/Buchungsbelege (BEG IV, from 1 Jan 2025), 6 / 10 for other classes (10 for Buchungsbelege only in the financial-sector exception); year-end clock; no hard delete; immutable storage + audit log.
13. VAT rate table keyed by **service category + effective date** (so the 1 Jan 2026 Gastronomie 19 %→7 % change is data, not code).

## Sources

[1] § 286 / § 288 BGB (Verzug, Verzugszinsen) and § 247 BGB Basiszinssatz; Kostenanschlag overrun damages — gesetze-im-internet.de & Deutsche Bundesbank Basiszinssatz announcement 1 Jan 2026 - https://www.gesetze-im-internet.de/bgb/__288.html ; https://www.bundesbank.de/de/presse/pressenotizen/bekanntgabe-des-basiszinssatzes-zum-1-januar-2026-basiszinssatz-bleibt-unveraendert-bei-1-27--973974
[2] § 14 UStG (Rechnung, Pflichtangaben Abs. 4, E-Rechnung-Definition Abs. 1, Gutschrift Abs. 2 Satz 4, 6-Monats-Frist Abs. 2 Satz 2, Endrechnung Abs. 5 Satz 2) — gesetze-im-internet.de - https://www.gesetze-im-internet.de/ustg_1980/__14.html
[3] § 145 BGB (Bindung an den Antrag), §§ 146–147 BGB, Angebotsbindung / freibleibendes Angebot — gesetze-im-internet.de - https://www.gesetze-im-internet.de/bgb/__145.html
[4] E-Rechnungspflicht & Rechnungskorrektur / § 14c UStG; FG Baden-Wuerttemberg double-VAT in Schlussrechnung — DATEV & IHK guidance - https://www.datev.de/web/de/berufsgruppenuebergreifend/themen-im-fokus/e-rechnung-mit-datev/gesetzliche-regelungen
[5] Gutschrift: kaufmaennische vs. § 14 Abs. 2 UStG self-billing — terminology trap; current UStAE/BFH view that the mere label does not trigger § 14c - https://kostenlose-erechnung.de/ratgeber/gutschrift-erstellen/
[6] Schlussrechnung: deducting Abschlaege/Anzahlungen and their VAT (§ 14 Abs. 5 Satz 2 UStG); double-VAT trap — Haufe & BMF UStAE 14.8 - https://www.haufe.de/finance/haufe-finance-office-premium/schlussrechnung-was-bei-der-rechnungsstellung-zu-beacht-2-wie-die-umsatzsteuer-bei-der-schlussrechnung-richtig-ausgewiesen-wird_idesk_PI20354_HI1137892.html
[7] § 650 BGB (Werklieferungsvertrag; Verbrauchervertrag ueber digitale Produkte) — confirms § 650 is NOT Kostenanschlag — gesetze-im-internet.de - https://www.gesetze-im-internet.de/bgb/__650.html
[8] § 649 BGB (Kostenanschlag): no guarantee of accuracy, Kuendigung on wesentliche Ueberschreitung (§ 645 Abs. 1 compensation), unverzuegliche Anzeige Abs. 2; in force since 1 Jan 2018 (Bauvertragsrechtsreform) — gesetze-im-internet.de - https://www.gesetze-im-internet.de/bgb/__649.html
[9] § 33 UStDV (Kleinbetragsrechnung, ≤ 250 EUR Gesamtbetrag/brutto, reduced Pflichtangaben) — gesetze-im-internet.de - https://www.gesetze-im-internet.de/ustdv_1980/__33.html
[10] Retention periods after BEG IV: 8 years Buchungsbelege/invoices, 6 years Handelsbriefe, 10 years Handelsbuecher/Jahresabschluss (10 years Buchungsbelege only for financial-sector firms) — § 257 Abs. 4 HGB / § 147 Abs. 3 AO - https://www.gesetze-im-internet.de/hgb/__257.html
[11] BMF FAQ & BMF-Schreiben (15.10.2024 / 15.10.2025): obligatorische E-Rechnung ab 1.1.2025 — receive obligation, 800k threshold, 2027/2028 timeline, XRechnung/ZUGFeRD ≥ 2.0.1 (no MINIMUM/BASIC-WL), exemptions; § 13 Abs. 1 Nr. 1a Anzahlung VAT timing — bundesfinanzministerium.de - https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
[12] Rechnungskorrektur / Stornorechnung / berichtigte Rechnung; § 31 Abs. 5 UStDV correction (reference to original); retroactive effect from EuGH Senatex (C-518/14) / Barlis 06 (C-516/14) case law, not from the regulation text — gesetze-im-internet.de - https://www.gesetze-im-internet.de/ustdv_1980/__31.html
[13] Leistungsnachweis / Stundennachweis under VOB/B — prueffaehige Abrechnung (§ 14 VOB/B) and Stundenlohnzettel + 6-Werktage return / Anerkennungsfiktion (§ 15 VOB/B), burden of proof — dejure.org - https://dejure.org/gesetze/VOB-B/15.html
[14] Kleinunternehmer reform 2025 (§ 19 UStG, JStG 2024): 25k/100k thresholds, hard mid-year flip, Steuerbefreiung — BMF-Schreiben 18.03.2025 & IHK - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-03-18-sonderregelung-kleinunternehmer.pdf
[15] § 12 UStG (Steuersaetze 19 % / 7 %); 1 Jan 2026 Gastronomie food → 7 % via Steueraenderungsgesetz 2025 — gesetze-im-internet.de & ZDH - https://www.gesetze-im-internet.de/ustg_1980/__12.html
[16] § 13 UStG (Steuerentstehung; Soll-/Mindest-Ist-Versteuerung Abs. 1 Nr. 1 lit. a) — gesetze-im-internet.de - https://www.gesetze-im-internet.de/ustg_1980/__13.html
[17] § 286 BGB (Verzug, 30-Tage-Regel Abs. 3, Verbraucher-Hinweis) — gesetze-im-internet.de - https://www.gesetze-im-internet.de/bgb/__286.html

## Open Questions

1. The ~15–20 % "wesentliche Ueberschreitung" tolerance for Kostenvoranschlaege (§ 649 BGB) is case-law/commentary-derived, not a statutory figure. The package should treat it as a configurable default and flag in docs that the precise threshold is fact-specific. A targeted BGH-citation pass would harden this before shipping.
2. The retroactive effect of an invoice correction is grounded in EuGH (Senatex C-518/14, Barlis 06 C-516/14) and BFH follow-on rulings, not in § 31 Abs. 5 UStDV itself. Confirm the current BFH line on which corrections qualify for Rueckwirkung (a correctable "minimum content" original vs. a document so deficient it is not an invoice at all) before relying on retroactivity in the engine.
3. The exact obligatory XRechnung/ZUGFeRD version and EN 16931 codelist edition the engine must emit for invoices issued in 2026–2028 should be pinned against the current KoSIT XRechnung version (xeinkauf.de) and the CEN EN 16931 amendments at build time, since these revise periodically.
4. Whether the planned B2B transaction-reporting / Meldesystem (the e-reporting follow-on to the e-invoice mandate, expected later in the 2020s) will impose additional structured-data obligations on this engine is not yet finalised in law and should be tracked.
5. The § 286 Abs. 3 BGB automatic-30-day default against a consumer requires that the consumer was specifically warned of this consequence on the invoice/payment statement. Confirm the exact warning wording the package must print on consumer invoices.
6. Basiszinssatz changes every 1 Jan and 1 Jul; the 1.27 % figure applies from 1 Jan 2026 (confirmed unchanged by the Bundesbank). Source it from an updatable table and confirm the 1 Jul 2026 value before any release dated after that.
7. The 1 Jan 2026 reduced rate for Gastronomie applies to food but **not** beverages; confirm the precise category boundary (and any package categories affected) against § 12 Abs. 2 UStG / Anlage 2 before relying on a 7 % default for hospitality customers.
