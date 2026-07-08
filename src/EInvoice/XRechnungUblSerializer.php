<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use horstoeko\zugferdublbridge\XmlConverterCiiToUbl;
use JohnWink\GobdInvoice\Contracts\EInvoiceSerializer;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Serializes a finalized document into XRechnung UBL syntax. XRechnung may be
 * required in UBL rather than CII (many public-sector portals and Peppol expect
 * UBL), but this engine produces CII natively — so this serializer converts the
 * CII output to UBL via horstoeko/zugferdublbridge, keeping a single source of
 * truth. The injected serializer must produce XRechnung-CII (the provider wires
 * a {@see ZugferdCiiSerializer} on the `xrechnung` profile).
 *
 * The bridge selects the UBL root document from the EN 16931 type code (BT-3):
 * an invoice becomes a UBL `Invoice`, a Storno (381) a UBL `CreditNote`.
 *
 * See docs/dependencies.md for why the conversion is borrowed rather than
 * hand-written (the fallback is the official KoSIT CII↔UBL XSLT via ext-xsl).
 */
final readonly class XRechnungUblSerializer implements EInvoiceSerializer
{
    public function __construct(private EInvoiceSerializer $eInvoiceSerializer) {}

    public function serialize(Document $document): string
    {
        $cii = $this->eInvoiceSerializer->serialize($document);

        // enableAutomaticMode() picks the UBL root document from the type code
        // (BT-3), so a 381 Storno becomes a CreditNote rather than an Invoice.
        return XmlConverterCiiToUbl::fromString($cii)
            ->enableAutomaticMode()
            ->convert()
            ->saveXmlString();
    }
}
