<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Models\Document;

/**
 * Serializes a finalized document into a structured EN 16931 e-invoice payload
 * (e.g. ZUGFeRD/Factur-X CII, XRechnung UBL/CII). The domain model is the source
 * of truth; serializers are a downstream, swappable concern. See
 * docs/research/03-e-invoicing.md.
 */
interface EInvoiceSerializer
{
    /**
     * @return string the structured XML payload
     */
    public function serialize(Document $document): string;
}
