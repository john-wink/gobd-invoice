<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Validation;

use JohnWink\GobdInvoice\Contracts\DocumentContentValidator;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\Party;

/**
 * The default {@see DocumentContentValidator}: enforces the §14 Abs. 4 UStG
 * mandatory fields on tax-relevant documents, with the §33 UStDV
 * Kleinbetragsrechnung relaxation (gross ≤ €250 drops the recipient and the
 * supplier tax id). Non-invoice types (Angebot, Mahnung, …) and the auto-
 * generated Storno reversal (whose content mirrors its finalized original) are
 * skipped.
 *
 * Scope of this check: Nr. 1 (both parties' name + address), Nr. 2 (supplier
 * Steuernummer/USt-IdNr), Nr. 5 (quantity + description per line) and Nr. 6
 * (time of supply). Nr. 3/4/7/8 are produced by finalize itself (issue date,
 * number, per-rate breakdown). See docs/research/02-legal-invoice-content.md.
 */
final class MandatoryContentValidator implements DocumentContentValidator
{
    /** §33 UStDV Kleinbetragsrechnung ceiling: €250 gross, in minor units. */
    private const int KLEINBETRAG_LIMIT_MINOR = 25000;

    public function validate(Document $document): void
    {
        if (! $document->type->isTaxRelevant()) {
            return;
        }

        // A Storno is an auto-generated reversal that mirrors an already-finalized
        // original (its §14 content is inherited, not authored here). Gating it on
        // §14 would make a defective original impossible to cancel — but
        // Storno statt Löschen must always be possible, so it is not re-validated.
        if ($document->type === DocumentType::Storno) {
            return;
        }

        $violations = [];
        $isKleinbetrag = $this->isKleinbetrag($document);

        $seller = is_array($document->seller) ? Party::fromArray($document->seller) : null;
        $buyer = is_array($document->buyer) ? Party::fromArray($document->buyer) : null;

        // Nr. 1 — supplier name + full address (always required).
        if (! $seller instanceof Party || ! $seller->hasCompleteAddress()) {
            $violations[] = 'seller_name_address';
        }

        // Nr. 2 — supplier Steuernummer/USt-IdNr (not required for a Kleinbetrag).
        if (! $isKleinbetrag && (! $seller instanceof Party || ! $seller->hasTaxId())) {
            $violations[] = 'seller_tax_id';
        }

        // Nr. 1 — recipient name + full address (not required for a Kleinbetrag).
        if (! $isKleinbetrag && (! $buyer instanceof Party || ! $buyer->hasCompleteAddress())) {
            $violations[] = 'buyer_name_address';
        }

        // Nr. 5 — quantity + customary description per line.
        if ($document->lines->isEmpty()) {
            $violations[] = 'lines_missing';
        }

        foreach ($document->lines as $documentLine) {
            if (mb_trim($documentLine->description) === '') {
                $violations[] = 'line_description';
            }

            if (mb_trim($documentLine->quantity) === '') {
                $violations[] = 'line_quantity';
            }
        }

        // Nr. 6 — time of supply: a Leistungszeitpunkt (single date, may equal the
        // issue date) OR a Leistungszeitraum (period start+end) satisfies it.
        $hasPeriod = $document->service_period_start !== null && $document->service_period_end !== null;
        if ($document->service_date === null && ! $hasPeriod) {
            $violations[] = 'service_date';
        }

        if ($violations !== []) {
            throw DocumentContentException::withViolations($document->number, array_values(array_unique($violations)));
        }
    }

    private function isKleinbetrag(Document $document): bool
    {
        // §33 UStDV needs a total of ≤ €250 (magnitude — a negative-total credit
        // document is never a Kleinbetrag), AND not §6a (intra-community, category
        // K) or §13b (reverse charge, category AE), which always need the full §14
        // set. LIMITATION: §3c distance sales are also excluded by §33 but carry
        // no distinct marker in the model (they are standard-rated), so they are
        // NOT auto-detected here — a host issuing EU distance sales must supply
        // the full §14 data. See docs/research/02 (Open Questions).
        $gross = $document->gross_total;

        if ($gross === null || abs($gross) > self::KLEINBETRAG_LIMIT_MINOR) {
            return false;
        }

        return $document->lines->every(
            static fn (DocumentLine $documentLine): bool => ! in_array($documentLine->tax_category, ['AE', 'K'], true),
        );
    }
}
