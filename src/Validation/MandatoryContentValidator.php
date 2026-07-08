<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Validation;

use JohnWink\GobdInvoice\Contracts\DocumentContentValidator;
use JohnWink\GobdInvoice\Exceptions\DocumentContentException;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\Party;

/**
 * The default {@see DocumentContentValidator}: enforces the §14 Abs. 4 UStG
 * mandatory fields on tax-relevant documents, with the §33 UStDV
 * Kleinbetragsrechnung relaxation (gross ≤ €250 drops the recipient and the
 * supplier tax id). Non-invoice types (Angebot, Mahnung, …) are skipped.
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
            if (trim($documentLine->description) === '') {
                $violations[] = 'line_description';
            }

            if (trim($documentLine->quantity) === '') {
                $violations[] = 'line_quantity';
            }
        }

        // Nr. 6 — time of supply (Leistungszeitpunkt); may equal the issue date.
        if ($document->service_date === null) {
            $violations[] = 'service_date';
        }

        if ($violations !== []) {
            throw DocumentContentException::withViolations($document->number, array_values(array_unique($violations)));
        }
    }

    private function isKleinbetrag(Document $document): bool
    {
        // §33 UStDV needs gross ≤ €250 AND none of §3c/§6a/§13b (distance sale,
        // intra-community, reverse charge) — those always need the full §14 set.
        $gross = $document->gross_total;

        if ($gross === null || $gross > self::KLEINBETRAG_LIMIT_MINOR) {
            return false;
        }

        return $document->lines->every(
            static fn (DocumentLine $documentLine): bool => ! in_array($documentLine->tax_category, ['AE', 'K'], true),
        );
    }
}
