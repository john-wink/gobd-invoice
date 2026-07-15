<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use JohnWink\GobdInvoice\Contracts\EInvoiceSerializer;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\Party;

/**
 * Serializes a finalized document into a ZUGFeRD / Factur-X CII (UN/CEFACT
 * Cross-Industry Invoice) XML payload via horstoeko/zugferd. This wraps the
 * library behind {@see EInvoiceSerializer} so the domain model never depends on
 * horstoeko's API nor on the ZUGFeRD 2.x / XRechnung 3.x → 4.x transitions
 * (docs/research/03-e-invoicing.md §8.2).
 *
 * The MINIMUM and BASIC WL profiles are intentionally refused: they carry no
 * line detail and are booking aids only — not a valid invoice under §14 UStG
 * (BMF, in force 2025). EN 16931 (COMFORT) is the default.
 *
 * Monetary values live internally as integer minor units + BCMath; they are
 * converted to `float` only here, at the library boundary, and only from an
 * exact two-decimal string, so no precision is lost.
 */
final readonly class ZugferdCiiSerializer implements EInvoiceSerializer
{
    /**
     * Supported profile aliases → horstoeko profile id. MINIMUM / BASIC WL are
     * deliberately absent (see {@see self::assertProfileAllowed()}).
     *
     * @var array<string, int>
     */
    private const array PROFILE_MAP = [
        'basic' => ZugferdProfiles::PROFILE_BASIC,
        'en16931' => ZugferdProfiles::PROFILE_EN16931,
        'comfort' => ZugferdProfiles::PROFILE_EN16931,
        'extended' => ZugferdProfiles::PROFILE_EXTENDED,
        'xrechnung' => ZugferdProfiles::PROFILE_XRECHNUNG_3,
    ];

    /**
     * Profile aliases that are not a legal invoice and must never be emitted.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_PROFILES = ['minimum', 'basicwl', 'basic-wl', 'basic wl'];

    /**
     * Common German unit words → UN/ECE Recommendation 20 / 21 codes (BT-130).
     * A value that is already a 1–3 char code is passed through; anything else
     * falls back to C62 ("one", the EN 16931 default for a unitless item).
     *
     * @var array<string, string>
     */
    private const array UNIT_MAP = [
        'stück' => 'H87',
        'stk' => 'H87',
        'stk.' => 'H87',
        'st' => 'H87',
        'piece' => 'H87',
        'pcs' => 'H87',
        'stunde' => 'HUR',
        'stunden' => 'HUR',
        'std' => 'HUR',
        'h' => 'HUR',
        'tag' => 'DAY',
        'tage' => 'DAY',
        'day' => 'DAY',
        'monat' => 'MON',
        'pauschal' => 'C62',
        'kg' => 'KGM',
        'g' => 'GRM',
        't' => 'TNE',
        'm' => 'MTR',
        'km' => 'KMT',
        'm2' => 'MTK',
        'qm' => 'MTK',
        'm3' => 'MTQ',
        'l' => 'LTR',
    ];

    public function __construct(private string $profile) {}

    public function serialize(Document $document): string
    {
        return $this->buildDocument($document)->getContent();
    }

    /**
     * Build the horstoeko document (the EN 16931 mapping) without serializing it,
     * so the PDF/A-3 builder can embed the same document into a PDF.
     */
    public function buildDocument(Document $document): ZugferdDocumentBuilder
    {
        throw_if($document->status !== DocumentStatus::Finalized, GobdInvoiceException::class, 'Only a finalized document can be exported as an EN 16931 e-invoice.');

        if (! $document->type->canEmitEInvoice()) {
            throw new GobdInvoiceException("Document type [{$document->type->value}] cannot be exported as an EN 16931 e-invoice.");
        }

        $issueDate = $document->issue_date;
        throw_if($issueDate === null, GobdInvoiceException::class, 'An EN 16931 e-invoice requires an issue date (BT-2).');

        $zugferdDocumentBuilder = ZugferdDocumentBuilder::createNew($this->resolveProfile());

        $zugferdDocumentBuilder->setDocumentInformation(
            (string) $document->number,
            $document->type->en16931TypeCode(),
            $issueDate,
            $document->currency,
        );

        // §14 time of supply → EN 16931: a single Leistungszeitpunkt maps to the
        // actual delivery date (BT-72); a Leistungszeitraum maps to the invoicing
        // period (BT-73/74).
        if ($document->service_date !== null) {
            $zugferdDocumentBuilder->setDocumentSupplyChainEvent($document->service_date);
        } elseif ($document->service_period_start !== null && $document->service_period_end !== null) {
            $zugferdDocumentBuilder->setDocumentBillingPeriod($document->service_period_start, $document->service_period_end, null);
        }

        $buyerReference = $this->stringMeta($document, 'buyer_reference');
        if ($buyerReference !== null) {
            $zugferdDocumentBuilder->setDocumentBuyerReference($buyerReference);
        }

        foreach ($this->documentNotes($document) as $note) {
            $zugferdDocumentBuilder->addDocumentNote($note);
        }

        $this->applyParties($zugferdDocumentBuilder, $document);
        $this->applyLines($zugferdDocumentBuilder, $document);
        $this->applyTaxBreakdown($zugferdDocumentBuilder, $document);
        $this->applyPaymentTerms($zugferdDocumentBuilder, $document);
        $this->applyTotals($zugferdDocumentBuilder, $document);
        $this->applyForeignCurrency($zugferdDocumentBuilder, $document);

        return $zugferdDocumentBuilder;
    }

    private function resolveProfile(): int
    {
        $normalized = mb_strtolower(mb_trim($this->profile));

        $this->assertProfileAllowed($normalized);

        return self::PROFILE_MAP[$normalized]
            ?? throw new GobdInvoiceException("Unsupported ZUGFeRD profile [{$this->profile}]. Supported: ".implode(', ', array_keys(self::PROFILE_MAP)).'.');
    }

    private function assertProfileAllowed(string $normalized): void
    {
        if (in_array($normalized, self::FORBIDDEN_PROFILES, true)) {
            throw new GobdInvoiceException("The ZUGFeRD [{$this->profile}] profile carries no line detail and is not a valid invoice under §14 UStG; use en16931 (COMFORT) or higher.");
        }
    }

    private function applyParties(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        $party = Party::fromArray($document->seller ?? []);
        $buyer = Party::fromArray($document->buyer ?? []);

        throw_if($party->name === '' || $buyer->name === '', GobdInvoiceException::class, 'An EN 16931 e-invoice requires a named seller and buyer (§14 Abs. 4 UStG).');

        $zugferdDocumentBuilder->setDocumentSeller($party->name);
        $zugferdDocumentBuilder->setDocumentSellerAddress(
            $party->addressLine,
            null,
            null,
            $party->postalCode,
            $party->city,
            $party->country,
        );
        if ($party->vatId !== null) {
            $zugferdDocumentBuilder->addDocumentSellerTaxRegistration('VA', $party->vatId);
        }
        if ($party->taxNumber !== null) {
            $zugferdDocumentBuilder->addDocumentSellerTaxRegistration('FC', $party->taxNumber);
        }

        $zugferdDocumentBuilder->setDocumentBuyer($buyer->name);
        $zugferdDocumentBuilder->setDocumentBuyerAddress(
            $buyer->addressLine,
            null,
            null,
            $buyer->postalCode,
            $buyer->city,
            $buyer->country,
        );
        if ($buyer->vatId !== null) {
            $zugferdDocumentBuilder->addDocumentBuyerTaxRegistration('VA', $buyer->vatId);
        } else {
            throw_if($this->requiresBuyerVatId($document), GobdInvoiceException::class, 'A reverse-charge (AE) or intra-community (K) e-invoice requires the buyer VAT identifier (BT-48, BR-AE-02 / BR-IC-02).');
        }

        // BT-43: buyer contact email (BG-9), when known.
        if ($buyer->email !== null && mb_trim($buyer->email) !== '') {
            $zugferdDocumentBuilder->setDocumentBuyerContact(null, null, null, null, $buyer->email);
        }
    }

    private function applyLines(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        $sign = $this->outputSign($document);

        foreach ($document->lines as $line) {
            /** @var DocumentLine $line */
            $quantity = (float) $line->quantity;
            // A zero-quantity line would make the price base quantity 0 and break
            // the EN 16931 line-total division (BT-131 = BT-146 / BT-149 × BT-129).
            $basisQuantity = $quantity !== 0.0 ? $quantity : 1.0;
            $unitCode = $this->unitCode($line->unit);
            // A Storno stores negated amounts; a credit note (381) must carry
            // positive amounts (the credit is expressed by the type code, BR-27).
            $lineNet = $this->toAmount($sign * $line->line_net_minor);

            $zugferdDocumentBuilder->addNewPosition((string) $line->position);
            $zugferdDocumentBuilder->setDocumentPositionProductDetails($line->description);
            // BT-146/BT-149: express the net price for the full billed quantity as
            // the base quantity so BT-131 reconciles exactly (no division rounding).
            $zugferdDocumentBuilder->setDocumentPositionNetPrice($lineNet, $basisQuantity, $unitCode);
            $zugferdDocumentBuilder->setDocumentPositionQuantity($quantity, $unitCode);
            $zugferdDocumentBuilder->addDocumentPositionTax(
                $line->tax_category,
                'VAT',
                (float) $line->tax_rate,
            );
            $zugferdDocumentBuilder->setDocumentPositionLineSummation($lineNet);
        }
    }

    private function applyTaxBreakdown(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        $sign = $this->outputSign($document);
        $override = $this->stringMeta($document, 'exemption_note');

        foreach ($document->tax_breakdown ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $category = $this->stringFrom($group, 'category', TaxCategory::Standard->value);
            $rate = isset($group['rate']) && is_numeric($group['rate']) ? (float) $group['rate'] : 0.0;

            $zugferdDocumentBuilder->addDocumentTax(
                $category,
                'VAT',
                $this->toAmount($sign * $this->intFrom($group, 'net')),
                $this->toAmount($sign * $this->intFrom($group, 'vat')),
                $rate,
                $this->exemptionReasonFor($category, $override),
            );
        }
    }

    private function applyPaymentTerms(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        $terms = is_array($document->payment_terms) ? $document->payment_terms : [];

        $note = isset($terms['note']) && is_scalar($terms['note']) ? (string) $terms['note'] : null;
        $netDays = isset($terms['net_days']) && is_numeric($terms['net_days']) ? (int) $terms['net_days'] : null;

        // BT-9 payment due date: always emit it when known. EN 16931 BR-CO-25
        // requires a due date (or payment terms) whenever an amount is due; the
        // due_date accessor falls back to the issue date (payment on receipt) when
        // no net-days term is set, so any invoice stays conformant.
        $dueDate = $document->due_date;
        $description = $note ?? ($netDays !== null ? "Zahlbar innerhalb von {$netDays} Tagen." : null);

        if ($description === null && $dueDate === null) {
            return;
        }

        $zugferdDocumentBuilder->addDocumentPaymentTerm($description, $dueDate);
    }

    private function applyTotals(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        // A credit note (381) carries positive amounts; the domain stores a Storno
        // negated, so flip every monetary value by the same sign (BR-27 + all the
        // BR-CO reconciliations hold under a uniform sign flip).
        $sign = $this->outputSign($document);

        // BT-113 (prepaid) = paid + already-invoiced advances (net + VAT), so
        // BR-CO-16 holds: BT-115 (amount due) = BT-112 − BT-113 + BT-114.
        $prepaidMinor = ($document->paid_total ?? 0) + ($document->advances_net_total ?? 0) + ($document->advances_vat_total ?? 0);

        $zugferdDocumentBuilder->setDocumentSummation(
            $this->toAmount($sign * ($document->gross_total ?? 0)),
            $this->toAmount($sign * ($document->amount_due ?? 0)),
            $this->toAmount($sign * ($document->line_net_total ?? 0)),
            $this->toAmount($sign * ($document->charge_total ?? 0)),
            $this->toAmount($sign * ($document->allowance_total ?? 0)),
            $this->toAmount($sign * ($document->net_total ?? 0)),
            $this->toAmount($sign * ($document->vat_total ?? 0)),
            $this->toAmount($sign * ($document->rounding_total ?? 0)),
            $this->toAmount($sign * $prepaidMinor),
        );
    }

    /**
     * When the invoice currency (BT-5) is not the VAT accounting currency (EUR,
     * BT-6), EN 16931 requires the total VAT expressed in that accounting currency
     * (BT-111). The domain persists it as `vat_accounting_total` at the §16 Abs. 6
     * UStG rate; emit it plus BT-6 via horstoeko's foreign-currency support. Called
     * after {@see self::applyTotals()} so the header summation already exists.
     */
    private function applyForeignCurrency(ZugferdDocumentBuilder $zugferdDocumentBuilder, Document $document): void
    {
        $vatAccountingMinor = $document->vat_accounting_total;
        if ($vatAccountingMinor === null) {
            return;
        }

        $rate = $document->accounting_rate;
        $accountingCurrency = is_array($rate) && isset($rate['quote_currency']) && is_scalar($rate['quote_currency'])
            ? (string) $rate['quote_currency']
            : 'EUR';
        $exchangeRate = is_array($rate) && isset($rate['rate']) && is_numeric($rate['rate'])
            ? (float) $rate['rate']
            : null;

        $zugferdDocumentBuilder->setForeignCurrency(
            $accountingCurrency,
            $this->toAmount($this->outputSign($document) * $vatAccountingMinor),
            $exchangeRate,
        );
    }

    /**
     * The BT-120 VAT exemption reason text for a non-standard category. Standard
     * and zero-rated groups need none (BR-S / BR-Z). A host-supplied
     * `meta.exemption_note` (e.g. the §19 Kleinunternehmer statement or a specific
     * §4 UStG basis) wins over the category default.
     */
    private function exemptionReasonFor(string $category, ?string $override): ?string
    {
        $taxCategory = TaxCategory::tryFrom($category);

        if (in_array($taxCategory, [null, TaxCategory::Standard, TaxCategory::ZeroRated], true)) {
            return null;
        }

        if ($override !== null) {
            return $override;
        }

        $noteKey = $taxCategory->noteTranslationKey();
        if ($noteKey !== null) {
            return (string) trans($noteKey);
        }

        return (string) trans("gobd-invoice::gobd-invoice.tax_categories.{$category}");
    }

    /**
     * @return list<string>
     */
    private function documentNotes(Document $document): array
    {
        $notes = $document->meta['notes'] ?? null;

        if (! is_array($notes)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $note): string => is_scalar($note) ? (string) $note : '', $notes),
            static fn (string $note): bool => $note !== '',
        ));
    }

    private function stringMeta(Document $document, string $key): ?string
    {
        $value = $document->meta[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function unitCode(?string $unit): string
    {
        $trimmed = $unit !== null ? mb_trim($unit) : '';

        if ($trimmed === '') {
            return 'C62';
        }

        // Map known words first so uppercase abbreviations (KG, STK, STD) resolve
        // to their real UN/ECE code instead of being passed through verbatim.
        $mapped = self::UNIT_MAP[mb_strtolower($trimmed)] ?? null;
        if ($mapped !== null) {
            return $mapped;
        }

        // Otherwise treat a code-shaped token (leading letter, e.g. C62/HUR/MTK)
        // as an already-valid UN/ECE Rec 20/21 code; fall back to "one" (C62).
        if (preg_match('/^[A-Z][A-Z0-9]{1,2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return 'C62';
    }

    /**
     * The sign applied to every monetary value so a credit note is emitted with
     * positive amounts. A Storno is stored negated; EN 16931 conveys the credit
     * via the type code (381), not the sign (BR-27).
     */
    private function outputSign(Document $document): int
    {
        return ($document->gross_total ?? 0) < 0 ? -1 : 1;
    }

    /**
     * Reverse-charge (AE) and intra-community (K) supplies require the buyer VAT
     * identifier (BT-48, BR-AE-02 / BR-IC-02).
     */
    private function requiresBuyerVatId(Document $document): bool
    {
        foreach ($document->tax_breakdown ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $category = TaxCategory::tryFrom($this->stringFrom($group, 'category', ''));
            if (in_array($category, [TaxCategory::ReverseCharge, TaxCategory::IntraCommunity], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $group
     */
    private function intFrom(array $group, string $key): int
    {
        $value = $group[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $group
     */
    private function stringFrom(array $group, string $key, string $default): string
    {
        $value = $group[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    private function toAmount(?int $minor): float
    {
        return (float) bcdiv((string) ($minor ?? 0), '100', 2);
    }
}
