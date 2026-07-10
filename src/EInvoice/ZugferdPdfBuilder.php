<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use JohnWink\GobdInvoice\Contracts\EInvoicePdfBuilder;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Produces a hybrid ZUGFeRD / Factur-X PDF/A-3 by embedding the finalized
 * document's CII XML into a supplied base PDF, via horstoeko/zugferd. Uses the
 * same CII mapping as {@see ZugferdCiiSerializer} (the injected serializer must
 * be on a CII profile, e.g. en16931 or xrechnung).
 */
final readonly class ZugferdPdfBuilder implements EInvoicePdfBuilder
{
    public function __construct(private ZugferdCiiSerializer $zugferdCiiSerializer) {}

    public function build(Document $document, string $basePdf): string
    {
        $document->loadMissing('lines');

        return ZugferdDocumentPdfBuilder::fromPdfString($this->zugferdCiiSerializer->buildDocument($document), $basePdf)
            ->generateDocument()
            ->downloadString();
    }
}
