# External dependencies

_Analysis date: 2026-07-08. Metrics measured against the versions installed in
this repository (see the table). Re-measure when a dependency is bumped._

This package is deliberately **framework-only and dependency-light**. Every
runtime dependency here is a conscious decision, not an accident of convenience.
For each non-trivial dependency this document records:

1. **What it does** and where we use it.
2. **Why we depend on it** rather than writing it ourselves.
3. **Footprint** — code size, transitive dependencies, license, maintenance.
4. **Insulation** — how the rest of the package is shielded from it.
5. **Build-it-ourselves assessment** — the honest effort and risk of replacing
   it with our own implementation, and a recommendation.

The guiding principle (see [`docs/research/03-e-invoicing.md`](research/03-e-invoicing.md)
§8.2 and [`docs/research/08-package-architecture.md`](research/08-package-architecture.md))
is **"wrap, don't extend"**: the domain model never references a third-party
type directly; anything external sits behind one of our own contracts so it can
be swapped without touching the core.

## Runtime dependencies

| Package | Installed | License | Runtime deps | Role |
|---|---|---|---|---|
| `illuminate/contracts` | `^13.0` | MIT | — | Laravel integration surface (contracts only) |
| `spatie/laravel-package-tools` | `^1.92` | MIT | Laravel | Service-provider / config / migration boilerplate |
| `ext-bcmath` | `*` | PHP | — | Exact integer-minor-unit money math |
| `horstoeko/zugferd` | `v1.0.123` (`^1.0`) | MIT | jms/serializer, xsd2php-runtime, symfony/{validator,process,finder,yaml}, setasign/{fpdf,fpdi}, smalot/pdfparser, horstoeko/{mimedb,stringmanagement} | EN 16931 CII invoice engine (build / read / validate / PDF-A/3) |
| `horstoeko/zugferdublbridge` | `v1.0.16` (`^1.0`) | MIT | **none** (`php >=7.3` only) | CII ↔ UBL syntax conversion |

`illuminate/contracts`, `spatie/laravel-package-tools` and `ext-bcmath` are
uncontroversial (framework glue and the standard exact-math extension) and are
not analysed further. The two `horstoeko/*` packages are the domain-heavy
dependencies and are the subject of this document.

---

## horstoeko/zugferd

**What it does.** The complete German/European e-invoice engine: it models the
full UN/CEFACT Cross-Industry-Invoice (CII, EN 16931 syntax binding) data model
and can build, read, validate and PDF/A-3-embed ZUGFeRD / Factur-X / XRechnung
documents across every profile (MINIMUM … EXTENDED, XRechnung 2.x/3.x).

**Where we use it.** Only the **builder path**, wrapped behind
[`EInvoiceSerializer`](../src/Contracts/EInvoiceSerializer.php):
[`ZugferdCiiSerializer`](../src/EInvoice/ZugferdCiiSerializer.php) drives
`ZugferdDocumentBuilder` and returns CII XML via `getContent()`. The reader,
validator and PDF paths are available for later M5 slices but not yet used.

**Why we depend on it.** The CII XML is a machine-generated binding of a very
large official XSD schema (D16B). A conformant, tolerance-free EN 16931 document
is unforgiving: any structural or ordering error is a fatal Schematron failure
and, in production, a rejected invoice and a failed audit. This is commodity,
specification-defined infrastructure — exactly what should be depended upon, not
re-implemented.

### Footprint

| Metric | Value |
|---|---|
| PHP source files | 342 |
| Total source LOC | ~104,000 |
| Code-generated CII entity classes | 267 (~28,000 LOC), generated from the official XSDs via `goetas-webservices/xsd2php` |
| License | MIT |
| Minimum PHP | 7.3 (we run 8.4/8.5) |
| Notable transitive deps | `jms/serializer` (XML (de)serialization), `symfony/{validator,process,finder,yaml}`, `setasign/{fpdf,fpdi}` + `smalot/pdfparser` (PDF/A-3) |

**Transitive-weight caveat.** Because we use only the CII builder, the PDF stack
(`setasign/fpdf`, `setasign/fpdi`, `smalot/pdfparser`) and the KoSIT/`symfony/process`
Java-shelling validator are pulled in but currently unused. They become relevant
in the PDF/A-3-embedding and validation slices; until then they are dead weight
in the dependency tree (not in our runtime path).

### Insulation

Nothing outside `src/EInvoice/` references a `horstoeko\*` type. The domain,
the manager and the facade speak only in terms of `Document` and the
`EInvoiceSerializer` contract. Swapping the library (or the whole approach)
touches exactly one directory. This also insulates us from the ZUGFeRD
2.3 → 2.4 → 2.5 and XRechnung 3.x → 4.x transitions.

### Build-it-ourselves assessment

**Effort: very high (multiple person-months). Recommendation: KEEP.**

Re-implementing this means owning, at minimum:

- A binding of the UN/CEFACT CII XSD (D16B) to PHP — either hand-written for the
  ~250 business terms we care about, or code-generated (i.e. re-inventing the
  `xsd2php` toolchain horstoeko already uses).
- Deterministic, order-correct XML (de)serialization (the job `jms/serializer`
  does today) — element ordering alone is a common source of Schematron failures.
- Per-profile rule enforcement (which BT/BG are allowed/required per profile).
- **Ongoing** tracking of schema and regulatory changes (new ZUGFeRD/Factur-X and
  XRechnung versions ship roughly yearly and are legally dated).

The correctness bar is high and the surface is broad and externally defined; a
bug here silently produces invoices that are rejected downstream. The ROI of
rebuilding is strongly negative. Our insulation layer already gives us the main
benefit of independence (cheap future swap) without the cost of ownership.

**If we ever had to reduce reliance**, the pragmatic path is *narrowing*, not
rebuilding: keep horstoeko for CII (de)serialization and only lift the profile
selection / mapping (already ours) — never the schema binding.

---

## horstoeko/zugferdublbridge

**What it does.** Converts a CII document to UBL syntax and back
(`XmlConverterCiiToUbl` / `XmlConverterUblToCii`). XRechnung may be required in
**either** CII or UBL syntax depending on the recipient (many public-sector
portals and Peppol expect UBL); our engine produces CII natively, so this bridge
is what lets us also emit UBL from the same source of truth.

**Where we will use it.** In the planned XRechnung-UBL slice, behind a new
UBL `EInvoiceSerializer` implementation that pipes our CII output through
`XmlConverterCiiToUbl::fromString($cii)->convert()->saveXmlString()`. As with
zugferd, no `horstoeko\*` type will leak outside `src/EInvoice/`.

### Footprint

| Metric | Value |
|---|---|
| PHP source files | 11 |
| Total source LOC | ~4,500 |
| License | MIT |
| Minimum PHP | 7.3 |
| Runtime deps | **none** — pure `DOMDocument` transformation |

This is a small, self-contained, zero-dependency package. It adds negligible
weight to the dependency tree.

### Build-it-ourselves assessment

**Effort: medium (~4–5k LOC of mapping) + ongoing. Recommendation: KEEP, with a
documented fallback.**

The CII ↔ UBL mapping is well-specified (CEN/TC 434 + the KoSIT mapping), but it
is broad: every business term must be relocated from its CII XPath to its UBL
XPath (and back), including the fiddly cases (allowances/charges, tax subtotals,
payment means, attachments). horstoeko encodes this as hand-written DOM traversal.

Two realistic self-hosting options if we wanted to drop the dependency:

1. **Official KoSIT XSLT via `ext-xsl`.** KoSIT publishes authoritative
   CII→UBL (and UBL→CII) stylesheets. We could ship those assets and run them
   through PHP's `XSLTProcessor`. Pros: authoritative, less PHP to own. Cons:
   adds an XSLT asset to vendor and maintain, requires `ext-xsl`, and the
   reverse direction is less complete.
2. **Our own DOM transformer.** Feasible (it is "only" ~4.5k LOC and the mapping
   is stable), but it is pure liability for zero functional gain over a
   zero-dependency MIT library that already does it.

Because the package is tiny, dependency-free and MIT, the cost of keeping it is
near zero and the cost of rebuilding is real. **Keep it.** Should it ever become
unmaintained, option (1) (KoSIT XSLT) is the low-risk exit.

---

## Summary

| Dependency | Keep? | If we had to replace it |
|---|---|---|
| `horstoeko/zugferd` | ✅ Keep | Very high effort; never rebuild the schema binding — at most lift the (already-ours) mapping layer |
| `horstoeko/zugferdublbridge` | ✅ Keep | Medium effort; fallback is the official KoSIT CII↔UBL XSLT via `ext-xsl` |
| `illuminate/contracts`, `spatie/laravel-package-tools` | ✅ Keep | Framework glue; not applicable |
| `ext-bcmath` | ✅ Keep | `brick/math` (pure PHP) is a drop-in fallback if the extension is unavailable |

**Bottom line.** Both `horstoeko/*` packages are MIT-licensed, actively
maintained, and sit behind our `EInvoiceSerializer` contract. `zugferd` is
high-value commodity infrastructure we should not rebuild; `zugferdublbridge` is
a tiny zero-dependency convenience with a clean XSLT-based exit. The solid
foundation we want comes from **owning the domain model and the mapping** (which
we do) while **borrowing the schema plumbing** (which we should).
