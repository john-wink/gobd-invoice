<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Models\Document;

/**
 * Embeds a finalized document's EN 16931 CII XML into a supplied base PDF,
 * producing a hybrid ZUGFeRD / Factur-X PDF/A-3 (a human-readable PDF with the
 * machine-readable invoice attached). The visual PDF is rendered by the host;
 * this package owns only the compliant embedding.
 */
interface EInvoicePdfBuilder
{
    /**
     * @param  string  $basePdf  the rendered visual invoice PDF (bytes)
     * @return string the ZUGFeRD/Factur-X PDF/A-3 (bytes)
     */
    public function build(Document $document, string $basePdf): string;
}
