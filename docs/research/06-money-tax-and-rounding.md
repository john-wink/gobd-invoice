# Money, VAT Calculation & Rounding Rules

## Scope and how to read these notes

These notes translate German VAT/invoicing law and the European e-invoice standard EN 16931 into explicit **software requirements** for the `john-wink/gobd-invoice` engine. Every time-sensitive fact carries the date/version it applies to. Where a legal rule directly dictates code behaviour it is marked **REQ**. All load-bearing facts below were cross-checked against primary sources; primary sources (gesetze-im-internet.de, BMF / bundesfinanzministerium.de, Peppol/EN 16931, KoSIT-adjacent) are preferred over secondary commentary.

> **Currency note (verified mid-2026).** As of June 2026 the load-bearing legal facts in this document were re-verified against gesetze-im-internet.de and bundesfinanzministerium.de: VAT rates (§12 UStG: 19 % / 7 %), the Kleinunternehmer reform (§19 UStG, effective 1 Jan 2025: €25,000 / €100,000), and the e-invoicing mandate (Wachstumschancengesetz; phase-in 2025 receipt / 2027 / 2028). A **second BMF-Schreiben dated 15 Oct 2025** refined the e-invoicing administrative guidance (see Section 9). Nothing verified here contradicts the 2025 reforms, but the deadlines and thresholds remain subject to further legislative change (a Bürokratieentlastung adjustment has been discussed) — re-confirm at build time.

A central tension runs through the whole domain: **GoBD demands Unveränderbarkeit (immutability) and reproducibility** of every booked document, while **EN 16931 validation has zero rounding tolerance** [1][9]. Together these mean: the exact monetary algorithm (including *where* you round) is part of the document's permanent record. You cannot "fix" a 1-cent drift after the fact by recomputing — the stored breakdown must validate forever. This makes the money model a correctness-critical, append-only concern, not a display detail.

---

## 1. Never use floats for money

**REQ-1: Money is never represented as `float`/`double` anywhere in the engine.** IEEE-754 binary floats cannot represent most decimal cent values exactly (`0.1 + 0.2 != 0.3`), so any float arithmetic silently accumulates error that EN 16931's tolerance-free validation will reject [1][9].

Two acceptable internal representations:

- **Integer minor units** — store €89.99 as the integer `8999` (cents). Fast, exact for addition/subtraction, the storage form most money libraries use internally [6][8].
- **Arbitrary-precision decimal / BCMath / rational** — needed for *intermediate* values where cents are too coarse (per-unit prices like €1.2345, percentage math, currency conversion). PHP's `BCMath` and `brick/math` operate on decimal strings, never binary floats [6].

**REQ-2: Persist money as `BIGINT` minor-unit columns plus a separate currency column** (e.g. `amount_minor BIGINT`, `currency CHAR(3)`), or as `DECIMAL(p,s)` with a scale large enough for unit prices (commonly `DECIMAL(15,4)` for line unit prices, `DECIMAL(15,2)` for booked totals). Never `FLOAT`/`DOUBLE` columns. Storing the currency alongside every amount is mandatory for the multi-currency design (Section 7) and matches the value-object pattern where a Money = {amount in minor unit, currency} [6][7].

**REQ-3: Distinguish the precision tier explicitly.** Unit prices and percentages may keep extra decimals (4+); every amount that is *booked* or *shown on the document* (line net, line tax, totals) must be a clean 2-decimal value in Euro/Cent.

> **Citation correction.** The draft's basis for the 2-decimal rule was "§16(6) UStG / Art. 399 MwStSystRL". Both pinpoints are wrong:
> - **§16 Abs. 6 UStG does not state a 2-decimal/Euro-Cent rounding rule.** Its actual subject is the **conversion of foreign-currency amounts into Euro** for computing tax and deductible input tax, using the **monthly average exchange rates published by the BMF** ("Durchschnittskurse, die das Bundesministerium der Finanzen für den Monat öffentlich bekanntgibt") [3]. It is the correct citation for Section 7 (multi-currency), not for "2 decimals".
> - **Art. 399 MwStSystRL** concerns Member States rounding the euro *amounts expressed in the Directive itself* when converting them into national currency — it is not the per-invoice rounding rule.
> The requirement that booked amounts are expressed in **Euro and Cent (2 decimals)** follows from the euro being the legal accounting currency plus administrative practice (UStAE) and EN 16931's 2-decimal mandate [9]; it is not pinned to a single UStG sentence. Treat the 2-decimal rule as **administrative/standard practice**, and reserve §16(6) UStG for the currency-conversion requirement.

---

## 2. Net vs. gross price representation

A line item can be authored either net-first or gross-first; the engine must support both and record which was used.

- **Nettorechnung (net invoicing):** the per-unit/line **net** price is authoritative; VAT is *added* on top. Typical B2B. EN 16931 is structurally net-oriented: the Invoice line net amount (BT-131) and document net total (BT-109) are the load-bearing values, and VAT is computed from net taxable bases [9].
- **Bruttorechnung (gross invoicing):** the **gross** price is authoritative (common B2C, retail); the contained VAT is *extracted* (`net = gross / (1 + rate)`), then VAT = gross − net. German tax authorities accept both, and §14 UStG only requires the rate and the resulting tax amount to be shown [10][11].

**REQ-4: Store a per-line `price_mode` enum (`net` | `gross`) and keep the authored price verbatim.** The chosen mode changes the rounding order (Section 4) and therefore the final total — the two modes legitimately differ by cents on the same goods, and this is "kein Rechenfehler" [13]. Because the document is immutable under GoBD, the mode and the authored figure must be stored, not re-derived.

**REQ-5: EN 16931 transports net.** When exporting XRechnung/ZUGFeRD, always emit net line amounts and net taxable bases regardless of authoring mode; convert gross-authored lines to net internally first [9].

---

## 3. German VAT rates and per-line tax category

Current rates under **§12 UStG** (verified on gesetze-im-internet.de, in force mid-2026 — standard 19 %, reduced 7 %, both unchanged) [4]:

| Situation | Rate | EN 16931 category code (UNCL5305, subset of UNTDID 5305) | Notes |
|---|---|---|---|
| Regelsteuersatz (standard) | **19 %** | `S` (Standard rated) | §12(1) UStG |
| Ermäßigter Satz (reduced) | **7 %** | `S` with 7 % rate | §12(2) UStG, goods in Anlage 2 (food, books, etc.) |
| Nullsatz (zero rate, §12(3)) | **0 %** | `Z` (Zero rated) | DE example: Solarmodule an Betreiber einer PV-Anlage (Lieferung/Installation) per §12(3) UStG — *see Open Questions re. Z vs E* |
| Steuerbefreit *with* input-VAT credit | 0 % | `E` (Exempt) | requires Befreiungsgrund text (BT-120) |
| Innergemeinschaftliche Lieferung | 0 % | `K` | intra-community supply |
| Export (Drittland) | 0 % | `G` | Free export item, tax not charged (outside EU) |
| Reverse charge §13b UStG | 0 % | `AE` | recipient owes the tax |
| Kleinunternehmer §19 UStG | — (steuerfrei) | `E` | see Section 6 |
| Nicht steuerbar / out of scope | 0 % | `O` (Services outside scope of tax) | tax amount must be 0 [9] |

**REQ-6: Store the VAT rate AND the EN 16931 category code on every line** (e.g. `vat_rate DECIMAL(5,2)`, `vat_category CHAR(2)`). A bare "0 %" is ambiguous — `Z`, `E`, `K`, `G`, `AE`, `O` all show 0 tax but carry different legal meaning, different mandatory texts, and different validation rules. Treat `(category, rate)` as the grouping key for the VAT breakdown (Section 5).

**REQ-7 (§13b reverse charge):** for `AE` lines the line VAT rate is 0 and no VAT is added; the invoice must carry the literal note **"Steuerschuldnerschaft des Leistungsempfängers"** (mapped to BT-120 VAT exemption reason text). The legal basis for the invoice content of a reverse-charge supply is **§14a Abs. 5 UStG** (not §14 alone): it requires the §14-Abs.-4 data *except* a separate tax statement, plus that mandatory note [12]. EN 16931 rule **BR-AE** additionally requires that for `AE` both the seller's and the buyer's VAT/tax registration ID be present and all VAT rates and amounts be zero [9]. A single document may legitimately mix `S` lines and `AE` lines.

---

## 4. Calculation order and kaufmännische Rundung (where rounding happens)

### The rounding method
German booked VAT amounts are stated in **Euro and Cent (2 decimals)**. The method is **kaufmännische Rundung** (commercial rounding = round half away from zero): a third decimal of 5–9 rounds up, 0–4 rounds down [13]. EN 16931 itself prescribes no rounding *method* — it only mandates the number of decimals (two) and consistency; the permissible rounding is anchored in German administrative practice (UStAE), not in the EN [5][9].

> **Caveat (verified).** The draft attributed the "Euro and Cent / 2 decimals" basis to §16(6) UStG and the method to "DIN 1333". The 2-decimal basis is administrative/standard practice (see Section 1 correction), and the half-away-from-zero method is "kaufmännische Rundung" recognised by the Finanzverwaltung; the DIN-1333 label is a commonly cited shorthand for commercial rounding but is not the legal source. The legally load-bearing point — half-up to 2 decimals, both vertical and horizontal methods accepted — is verified [13].

**REQ-8:** implement `roundHalfUp`/half-away-from-zero to 2 decimals as the single canonical money-rounding function. Do not use PHP's default `round()` on floats; use the chosen money library's rounding mode (e.g. brick/money `RoundingMode::HALF_UP`) [6].

### Where to round — the rule that prevents drift
The legally and technically critical rule: **VAT is computed per tax-rate group (je Steuersatz / Steuergruppe), not by summing per-line tax.** Summing rounded per-line tax amounts accumulates drift; the correct approach groups the net bases by `(category, rate)`, sums those bases, *then* applies the rate once per group and rounds once [9].

Two authoring methods produce slightly different cent results, and **both are accepted** by the Finanzamt [13][5]:

- **Vertikale Methode (recommended, net-first):** sum line nets per tax group → compute VAT on the group sum → round once per group. Minimizes drift; aligns with EN 16931's net-oriented totals.
- **Horizontale Methode:** round VAT per line first, then sum. Tends to introduce the well-known 1-cent differences.

**REQ-9:** default to per-group VAT (vertikale Methode). Compute each line's net amount and round it to 2 decimals (EN 16931 / Peppol BIS requires the line net BT-131 to be rounded to two decimals [9]); accumulate those rounded line nets into per-`(category,rate)` taxable bases; compute VAT once per group. Note that EN 16931 rule **BR-CO-10** (BT-106 = Σ BT-131) is satisfied as a sum of *already-rounded* line nets [9].

### EN 16931's "no re-rounding of already-rounded sums" rule
EN 16931 / Peppol BIS Billing 3.0 states (verbatim, Section 9 of the spec): *"All document level amounts shall be rounded to two decimals for accounting"*, *"Invoice line net amount shall be rounded to two decimals"*, and crucially **"Results from calculations involving already rounded amounts are not subject to rounding, like payable amounts and total amounts included VAT."** — e.g. the document total with VAT (BT-112) and the amount due are pure sums of already-rounded components and must NOT be re-rounded [9].

**REQ-10:** round at exactly two points — (a) each line net, (b) each per-group VAT amount — and then only *add* already-rounded values for all document totals. Never apply a second rounding to a sum of rounded values.

---

## 5. Totals, VAT breakdown (Steuerausweis je Steuersatz) and the rounding amount (BT-114)

EN 16931 calculation chain (BT = Business Term), cross-checked on Peppol BIS Billing 3.0 (Nov 2025 release) and the XRechnung BT references [9][14][15]:

| Term | Meaning | Formula |
|---|---|---|
| BT-131 | line net amount | `qty × net unit price` (− line allowance + line charge), rounded to 2 dp |
| BT-106 | Σ line net amounts | sum of all BT-131 (BR-CO-10) |
| BT-107 / BT-108 | document-level allowances / charges | |
| BT-109 | total without VAT (Nettosumme) | `BT-106 − BT-107 + BT-108` (BR-CO-13) |
| BT-116 | taxable base **per VAT category** (Bemessungsgrundlage je Steuersatz) | `Σ(line nets in group) + doc charges − doc allowances` |
| BT-117 | VAT amount **per category** | `BT-116 × (BT-119 / 100)`, rounded to 2 dp |
| BT-119 | the category rate | e.g. 19.00 |
| BT-110 | total VAT (Gesamtbetrag USt) | `Σ BT-117` (rule **BR-CO-14**: must equal the sum) |
| BT-112 | total with VAT (Gesamtbetrag) | `BT-109 + BT-110` (rule **BR-CO-15**) |
| BT-113 | already paid | |
| BT-114 | rounding amount (Rundungsbetrag) | the small +/− adjustment to reach a rounded payable |
| BT-115 | amount due for payment | `BT-112 − BT-113 + BT-114` (rule **BR-CO-16**) |

Key validation rules to honour: **BR-S-8** — a separate VAT breakdown line is required for each standard-rate base/rate combination (no merging different rates) [2]; **BR-CO-17** — each `BT-117 = BT-116 × BT-119/100` rounded to 2 dp [15]; **BR-CO-10** — `BT-106 = Σ BT-131`; **BR-CO-13** — `BT-109` matches the line-total chain; **BR-CO-14** — `BT-110 = Σ BT-117` [9].

**REQ-11:** the Steuerausweis (VAT breakdown shown on the document and exported in BG-23) MUST be one row per `(category, rate)` group with its base (BT-116), rate (BT-119) and tax (BT-117). This is also a §14 UStG requirement (§14 Abs. 4 Nr. 7): *"das nach Steuersätzen und einzelnen Steuerbefreiungen aufgeschlüsselte Entgelt für die Lieferung oder sonstige Leistung (§ 10) sowie jede im Voraus vereinbarte Minderung des Entgelts"* plus, per Nr. 8, the per-rate tax amount [10].

**REQ-12 (BT-114):** BT-114 is bounded to 2 decimals (rule **BR-DEC-17**) and is the ONLY sanctioned place to absorb a residual cent when the payable is deliberately rounded (e.g. cash rounding — the spec's own example: *"Amount 999.81 rounded to 1000. PayableRounding Amount = 0.19"*). It is *not* a dumping ground for calculation drift. If the per-group algorithm is correct, BT-114 is normally `0.00`. **There is no rounding tolerance in EN 16931 validation** [1][9][14] — the stored breakdown must reconcile exactly, so the engine must compute it deterministically and store it, never approximate.

**REQ-13:** also expose the intermediate display lines the German document needs: **Zwischensumme/Nettosumme** (BT-109), the **Steuerausweis** rows, and **Gesamtbetrag** (BT-112).

### Pseudocode — totals + VAT breakdown (per-rate)

```
function buildTotals(lines, docAllowances, docCharges, priceMode):
    groups = {}                      // key = (category, rate) -> taxableBase (Money, 2dp)
    sumLineNet = Money(0)

    for line in lines:
        if priceMode == NET:
            raw = line.netUnitPrice.multiply(line.qty)   // exact / BCMath
            lineNet = raw.round(2, HALF_UP)              // REQ-9: round line net
        else: // GROSS
            gross = line.grossUnitPrice.multiply(line.qty)
            net   = gross.dividedBy(1 + line.rate/100)   // rational, exact
            lineNet = net.round(2, HALF_UP)
        line.netAmount = lineNet                          // store BT-131
        sumLineNet = sumLineNet.plus(lineNet)             // BT-106 (sum of rounded)
        key = (line.category, line.rate)
        groups[key] = groups.get(key, Money(0)).plus(lineNet)

    // distribute document-level allowances/charges onto groups by their category/rate
    applyDocAdjustments(groups, docAllowances, docCharges)

    bt109 = sumLineNet.minus(sumDocAllowances).plus(sumDocCharges) // NO re-round (REQ-10)

    breakdown = []
    totalVat = Money(0)
    for (category, rate), base in groups:                 // BR-S-8: one row per rate
        if category in {Z, E, K, G, AE, O}:
            vat = Money(0)
        else:
            vat = base.multiply(rate/100).round(2, HALF_UP) // BT-117, REQ-9/BR-CO-17
        breakdown.add({category, rate, base, vat})          // BT-116/119/117
        totalVat = totalVat.plus(vat)                        // BT-110 = Σ BT-117

    bt112 = bt109.plus(totalVat)                            // sum of rounded; NO re-round
    bt114 = computeOptionalCashRounding(bt112)              // usually 0.00
    bt115 = bt112.minus(paidAmount).plus(bt114)
    return {bt106:sumLineNet, bt109, breakdown, bt110:totalVat, bt112, bt114, bt115}
```

---

## 6. Discounts: Rabatt, Skonto, Zuschläge

### Rabatt (price reduction / discount)
A Rabatt that is fixed at invoicing time *reduces the taxable base immediately*. It can be a **line-level** reduction (BT-136/BT-138 line allowance) or a **document-level** allowance (BT-107 / BG-20). §14 Abs. 4 Nr. 7 UStG requires that *"jede im Voraus vereinbarte Minderung des Entgelts, sofern sie nicht bereits im Entgelt berücksichtigt ist"* be stated [10].

**REQ-14:** model allowances/charges at both line and document level, each carrying its own VAT category/rate so it folds into the correct breakdown group. A document-level allowance spanning mixed rates must be apportioned per group before computing BT-116.

### Zuschläge (surcharges)
Symmetric to Rabatt: a charge (BT-141 line / BT-108 doc, BG-21). Same per-group, per-category handling as REQ-14.

### Skonto (cash discount) — the subtle one
Skonto is a *conditional, future* reduction (e.g. "2 % within 10 days, otherwise net 30"). Two rules govern it:

1. **VAT base is reduced only when the Skonto is actually taken** (Inanspruchnahme). Until then the invoice's Bemessungsgrundlage and stated VAT remain the full amounts. When payment with Skonto occurs, the taxable base changes under **§17 Abs. 1 UStG** (Änderung der Bemessungsgrundlage); the correction is made in the VAT period in which the change occurs (**§17 Abs. 1 Satz 8 UStG**) [16][17]. The *recipient* must likewise correct input VAT (Vorsteuer) in the same period [16][17].
2. **On the invoice the Skonto need not be shown as an amount** — it is sufficient to give a *reference to the agreement / payment terms* (Verweis auf Höhe und Zahlungsziel). The sentence *"Das Skonto muss nicht betragsmäßig ausgewiesen werden"* is **administrative guidance in the §17-UStAE context, not statutory text in §14 UStG** (verified: §14 itself does not contain this sentence) [16][17]. The statutory hook in §14 is only the general duty to state "jede im Voraus vereinbarte Minderung des Entgelts" [10].

> **Citation correction.** The draft attributed *"Das Skonto muss nicht betragsmäßig ausgewiesen werden"* and *"ist in der Rechnung auf die entsprechende Vereinbarung hinzuweisen"* verbatim to §14 UStG [10]. The exact §14 statutory wording is the Nr. 7 phrase quoted above; the "need not be shown as an amount" rule comes from administrative practice tied to §17 UStG [16][17]. Functionally the requirement is unchanged, but the source pinpoint was wrong.

**REQ-15:** the engine must NOT subtract Skonto from the invoice totals or the VAT breakdown at issuance. Store Skonto as **payment terms metadata** (percentage, deadline days, reference text). In EN 16931, Skonto goes into the **payment terms text (BT-20)**, not into BT-107/BT-116. German practice encodes it in BT-20 with a structured `#SKONTO#TAGE=..#PROZENT=..#` convention recognised by validators (see Open Questions for the exact current CIUS syntax).

**REQ-16:** provide a separate, later event — "Skonto in Anspruch genommen" — that creates a §17 correction document (an adjustment/Korrekturbeleg) reducing base and VAT in the period of payment, leaving the original immutable invoice intact (GoBD). This is exactly the kind of append-only correction the package's document lifecycle must support (relevant to Storno/Gutschrift/Korrektur document types).

---

## 7. Multi-currency

Although German invoices are virtually always **EUR**, design the Money type to be currency-bearing from day one [6][7].

**REQ-17:** every Money value carries its ISO-4217 currency; arithmetic across differing currencies throws rather than silently coercing. EN 16931 distinguishes the document/invoice currency (BT-5) from the **VAT accounting currency (BT-6)**: when BT-5 ≠ the VAT accounting currency (EUR for German VAT), the total VAT must additionally be expressed in the accounting currency (**BT-111**), because German VAT must be accounted in Euro [9].

**REQ-18:** if the invoice currency is non-EUR, compute and store the EUR VAT total (BT-111) using the legally prescribed exchange rate, applying the same per-group, round-once discipline; store the rate used for reproducibility (GoBD). **The legally required conversion basis is §16 Abs. 6 UStG**: foreign-currency values are converted to Euro using the **monthly average rates published by the BMF** ("Durchschnittskurse"), with daily rates permitted only by approval of the Finanzamt; for the special OSS/import procedures (§18 Abs. 4c/4e, §§18i/18j/18k UStG) the ECB rate of the last day of the tax period applies [3]. The EU-law hook for converting the VAT amount on the invoice is Art. 91 MwStSystRL (Directive 2006/112/EC) [9]. This resolves the draft's prior Open Question about the exchange-rate source.

---

## 8. Recommended PHP money implementation

Use a vetted value-object library rather than rolling your own; all candidates store amounts as integer minor units / decimal strings and use BCMath or brick/math internally, never floats [6][8].

| Library | Strengths | Use when |
|---|---|---|
| **brick/money** | Immutable; exact arithmetic on `brick/math`; explicit `RoundingMode`; `RationalMoney` keeps division exact until you deliberately round; clean allocation/split. Best fit for VAT math where you divide gross by `(1+rate)` and need exactness before the single rounding step. | **Recommended default** for the engine core [6] |
| **moneyphp/money** | Mature Fowler money pattern; rich `allocate()` for distributing remainders; large ecosystem. | If you prefer its allocation API / formatter ecosystem [8] |
| **akaunting/laravel-money** | Laravel-flavoured formatting/casting wrapper, locale-aware display. | Presentation/casting layer on top of brick or moneyphp, not the calculation core |

**REQ-19:** keep one Money type at the domain core (recommend brick/money with `RationalMoney` for intermediate VAT/gross-extraction math, converting to a 2-dp `Money` with `HALF_UP` only at the two sanctioned rounding points in REQ-10). Add an Eloquent cast that serializes to `{minorAmount, currency}` columns. Provide a `VatRate` value object pairing `(category, rate)` (REQ-6) and a `TaxBreakdown` aggregate implementing the Section 5 algorithm so the totals logic lives in one tested place.

> **Note.** Draft source [8] linked to a Packagist URL for `brick/money` while labelling it `moneyphp/money`. The correct package is `moneyphp/money` (packagist.org/packages/moneyphp/money); the source link below is corrected.

---

## 9. Compliance dates the money model touches (mid-2026 snapshot)

- **VAT rates** 19 % / 7 % per §12 UStG — verified unchanged and in force mid-2026 [4]. §12(3) UStG keeps the 0 % rate for the supply/installation of photovoltaic modules to the operator of a PV system (capacity limit applies).
- **Kleinunternehmer §19 UStG reform, effective 1 Jan 2025** (Jahressteuergesetz 2024): thresholds are **€25,000 prior calendar year / €100,000 current calendar year**, measured on **net** turnover (Gesamtumsatz); the legal nature changed from "tax not levied" to genuinely **steuerfrei / steuerbefreit** (exempt without input-tax credit). Crossing the €100,000 mark mid-year forces an **immediate** switch to standard taxation — the very transaction that exceeds €100,000 is already taxed under the standard regime [18][19]. The invoice must carry a note indicating the Kleinunternehmer exemption. **REQ-20:** model a Kleinunternehmer mode that emits 0 % `E`-category lines with the mandatory exemption note and no VAT breakdown, and that can flip mid-year on exceeding €100,000.

  > **§19 re-verification (2026-07, primary sources).** Re-checked against §19 UStG, §34a UStDV (gesetze-im-internet.de) and BMF-Schreiben 18.03.2025 (GZ III C 3 - S 7360/00027/044/105):
  > - **Thresholds & nature confirmed:** §19 Abs. 1 S. 1 UStG — *"…ist steuerfrei, wenn der Gesamtumsatz … im vorangegangenen Kalenderjahr 25 000 Euro nicht überschritten hat und im laufenden Kalenderjahr 100 000 Euro nicht überschreitet."* Net Gesamtumsatz (§19 Abs. 2, vereinnahmte Entgelte); genuinely *steuerbefreit*, no input-VAT credit (UStAE 19.1 Abs. 6; BMF Rn. 3). Limits are "nicht überschritten" → a turnover *exactly* at a limit is still within it.
  > - **Fallbeil precision:** the boundary-crossing transaction is standard-taxed **in full**, not merely the part above €100,000 (UStAE 19.1 Abs. 2 S. 4 + official example: 20k prior + 80k+40k → the whole 40k *"unterliegt in voller Höhe der Regelbesteuerung"*). Trigger is **Vereinnahmung** (Ist-Prinzip).
  > - **Founding year (Neugründung):** there is no prior calendar year, so only the current-year turnover decides, measured against the **lower €25,000 limit** (NOT €100,000); the projection (Hochrechnung) of the old €50k rule was abolished in 2025. Exceeding €25,000 in year one already ends the exemption (crossing transaction standard-taxed in full). Sources: IHK Stuttgart / Haufe "Umsatzgrenzen bei Neugründung"; §19 Abs. 1 UStG; BMF 2025-03-18.
  > - **Invoice note (correction):** §34a S. 1 Nr. 5 UStDV requires a note that the *Steuerbefreiung für Kleinunternehmer (§19)* applies; exact wording is free ("umgangssprachlich … ausreichend", UStAE 14.7a Abs. 1 S. 4). The old "keine Umsatzsteuer wird berechnet/erhoben" phrasing reflects the pre-2025 Nichterhebung and is NOT recommended — the note must reference the **Steuerbefreiung/Steuerfreiheit**.
  > - **E-invoicing:** Kleinunternehmer are exempt from the e-invoice *issuance* duty (may always send a *sonstige Rechnung*, UStAE 14.7a Abs. 3) but must still be able to *receive* them. EN 16931 category **`E`** + a BT-120 exemption reason is the EN/KoSIT **convention** (BR-E-10), not a German statutory pinpoint.
  > - **No later change found** (negative proof incomplete) — re-verify the UStAE / any Bürokratieentlastung change before 1.0. Sources: §19 UStG, §34a UStDV (gesetze-im-internet.de); BMF 2025-03-18 (bundesfinanzministerium.de).
- **E-invoicing mandate (Wachstumschancengesetz):** since **1 Jan 2025** every domestic business must be able to *receive* EN 16931 e-invoices. *Issuance* phases in:
  - **1 Jan 2025 – 31 Dec 2026:** issuers may still send paper or other electronic formats (e.g. PDF) for B2B turnover, with recipient consent for non-paper.
  - **from 1 Jan 2027:** issuers whose **prior-year (2026) Gesamtumsatz exceeds €800,000** must issue EN-16931 e-invoices; issuers at or below €800,000 retain the paper/other-format option through end-2027.
  - **from 1 Jan 2028:** **all** domestic B2B issuers must issue EN-16931 e-invoices.

  Valid formats are EN-16931-conformant: **XRechnung** and **ZUGFeRD from version 2.0.1** (the **MINIMUM** and **BASIC-WL** profiles do **not** qualify, as they are not full invoices) [20]. A **second BMF-Schreiben dated 15 Oct 2025** added administrative clarifications: a three-tier error classification (format / business-rule / content errors); all §§14, 14a UStG mandatory data must be machine-readable in the XML part (attachments/links do not satisfy the requirement); in hybrid PDF+XML invoices the structured XML part governs; and small exceptions persist (invoices ≤ €250, certain Kleinbetragsrechnungen, Fahrausweise) [20]. **REQ-21:** the engine must produce EN-16931-valid output now (receipt-capable counterparties already exist) and target the 2027/2028 issuance deadlines; ensure all §§14/14a mandatory fields are emitted inside the XML, not only in any PDF rendition.

These notes should be revisited if a further BMF-Schreiben revises the UStAE rounding/format guidance, if the e-invoicing deadlines or the €800,000 threshold are amended (a Bürokratieentlastung adjustment has been discussed), or if §12/§19 rates or thresholds change.

---

## Sources

[1] Peppol Error Codes / EN 16931 rules — no tolerance for rounding differences (peppolvalidator.com) - https://peppolvalidator.com/peppol-validation-errors
[2] BT-118 VAT Category Code / BR-S-8 separate disclosure per rate (invoice-converter.com) - https://www.invoice-converter.com/en/resources/xrechnung/bt-118-vat-category-code
[3] § 16 UStG Steuerberechnung — §16 Abs. 6 = Umrechnung fremder Währung auf Euro nach BMF-Durchschnittskursen (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__16.html
[4] § 12 UStG Steuersätze (19 % / 7 %; §12(3) 0 % Photovoltaik) (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__12.html
[5] FAQ XRechnung — EN 16931 prescribes decimals not method; rounding anchored in UStAE (xeinkauf.de) - https://xeinkauf.de/xrechnung/xrechnung/
[6] brick/money — immutable money, brick/math, RationalMoney, explicit rounding (GitHub) - https://github.com/brick/money
[7] Money pattern in PHP: value object {amount minor unit, currency} (DEV) - https://dev.to/rubenrubiob/money-pattern-in-php-the-solution-2o83
[8] moneyphp/money — Fowler money pattern, allocate() (Packagist) - https://packagist.org/packages/moneyphp/money
[9] Peppol BIS Billing 3.0 (Nov 2025 release) — calculation/rounding rules, BT-106/109/110/111/112/114/116/117, line net rounded to 2 dp, "results from calculations involving already rounded amounts are not subject to rounding", BR-CO-10/13/14/15/16, BR-AE - https://docs.peppol.eu/poacc/billing/3.0/bis/
[10] § 14 UStG Pflichtangaben — §14 Abs. 4 Nr. 7 "nach Steuersätzen und einzelnen Steuerbefreiungen aufgeschlüsseltes Entgelt … jede im Voraus vereinbarte Minderung des Entgelts" (gesetze-im-internet.de) - https://www.gesetze-im-internet.de/ustg_1980/__14.html
[11] Was ist bei der umsatzsteuerlichen Rechnungsstellung zu beachten — Finanzämter Baden-Württemberg - https://finanzamt-bw.fv-bwl.de/,Lde/Startseite/Service/Was+ist+bei+der+umsatzsteuerlichen+Rechnungsstellung+zu+beachten_
[12] Steuerschuldnerschaft des Leistungsempfängers §13b UStG — invoice content per §14a Abs. 5 UStG, mandatory note "Steuerschuldnerschaft des Leistungsempfängers" (sevdesk Lexikon) - https://sevdesk.de/lexikon/steuerschuldnerschaft-des-leistungsempfaengers/
[13] Kaufmännische Rundungsdifferenz: Netto- vs Bruttorechnung, horizontale vs vertikale Methode (sevdesk) - https://hilfe.sevdesk.de/de/articles/9423755-die-kaufmannische-rundungsdifferenz-darum-unterscheidet-sich-der-endbetrag-von-brutto-und-nettorechnungen
[14] BT-114 Rundungsbetrag — 2 decimals (BR-DEC-17), BT-115 = BT-112 − BT-113 + BT-114 (BR-CO-16) (invoice-converter.com) - https://www.invoice-converter.com/en/resources/xrechnung/bt-114-rounding-amount
[15] BT-117 Umsatzsteuerkategorie-Steuerbetrag — BT-117 = BT-116 × BT-119/100, 2 dp, BR-CO-17, Σ = BT-110 (invoice-converter.com) - https://www.invoice-converter.com/de/resources/xrechnung/bt-117-vat-category-tax-amount
[16] § 17 UStG Änderung der Bemessungsgrundlage — Skonto: Minderung erst bei Inanspruchnahme; Korrektur im Zeitraum der Änderung; "Das Skonto muss nicht betragsmäßig ausgewiesen werden" (BMF Umsatzsteuer-Handausgabe) - https://usth.bundesfinanzministerium.de/usth/2024/A-Umsatzsteuergesetz/V-Besteuerung/Paragraf-17/inhalt.html
[17] § 17 UStG Berichtigungszeitpunkt bei Minderung des Entgelts — §17 Abs. 1 Satz 8; Vorsteuerkorrektur beim Empfänger (iww.de) - https://www.iww.de/astw/archiv/--17-ustg--berichtigungszeitpunkt-bei-minderung-des-entgelts-f52717
[18] Neuregelungen für Kleinunternehmer ab 2025 — €25k/€100k, Nettoumsätze, Steuerbefreiung, sofortiger Wechsel bei Überschreiten (nwb.de) - https://www.nwb.de/rechnungswesen/neuregelungen-fuer-kleinunternehmer-ab-2025
[19] BMF — Sonderregelung für Kleinunternehmer, UStAE-Anpassung, BMF-Schreiben 2025-03-18 (bundesfinanzministerium.de) - https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-03-18-sonderregelung-kleinunternehmer.pdf?__blob=publicationFile&v=3
[20] BMF FAQ — obligatorische E-Rechnung ab 1.1.2025 (Empfang); Ausstellung 2027 >€800k / 2028 alle; EN 16931, XRechnung/ZUGFeRD ab 2.0.1 (ohne MINIMUM/BASIC-WL); ergänzt durch BMF-Schreiben 15.10.2025 - https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html

---

## Open Questions

- Exact structured encoding of Skonto in BT-20 payment terms (the `#SKONTO#TAGE=..#PROZENT=..#` convention) is widely used by validators but is a KoSIT/forum convention rather than codified in EN 16931 itself — confirm the precise current syntax against the latest XRechnung specification (CIUS) version before implementing the e-invoice exporter.
- Whether the engine should default to the vertikale (per-group, round-once) or horizontale (per-line) rounding method as a user-configurable option, given both are tax-accepted but produce different cent totals and the choice is locked into the immutable document.
- Precise list of which §12(3) UStG 0 % (Nullsatz, e.g. photovoltaic) cases should map to EN 16931 category `Z` vs `E` for German exports — verify against the latest BMF guidance and a current XRechnung/KoSIT validator. (Note: a true Nullsatz / zero-rated supply is `Z`; a tax-*exempt* supply without input-VAT credit is `E` — the §12(3) PV case is a genuine 0 % rate, suggesting `Z`, but confirm the validator's expectation.)
- Confirm the 2027 issuance threshold figure (Vorjahres-Gesamtumsatz > €800,000) and any later legislative adjustments (a Bürokratieentlastung change has been discussed) against the most recent BMF-Schreiben at build time, since the deadline phase-in has been subject to amendment. The 15 Oct 2025 BMF-Schreiben is the current administrative reference; check for newer letters.
- For multi-currency (BT-5 ≠ EUR), §16 Abs. 6 UStG mandates the BMF monthly average rates (Durchschnittskurse) for VAT accounting, with daily rates only on Finanzamt approval and ECB last-day rates for the OSS/import special procedures. Confirm the engine sources the current BMF-published monthly Umsatzsteuer-Umrechnungskurse so BT-111 EUR VAT is computed reproducibly for GoBD.
- Confirm whether `ZUGFeRD 2.0.1`'s exact qualifying profile floor (EN 16931 / Comfort and above; not MINIMUM/BASIC-WL) is unchanged in the latest ZUGFeRD/Factur-X release the exporter will target, since profile naming and qualification have evolved across 2.x point releases.
