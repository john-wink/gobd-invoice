<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

use LogicException;

/**
 * The German business-document taxonomy this engine models.
 *
 * Legal characteristics per case are documented in
 * docs/research/05-document-types-and-lifecycle.md.
 */
enum DocumentType: string
{
    case Rechnung = 'rechnung';
    case Angebot = 'angebot';
    case Kostenvoranschlag = 'kostenvoranschlag';
    case Beleg = 'beleg';
    case Leistungsnachweis = 'leistungsnachweis';
    case Teilzahlung = 'teilzahlung';
    case Abschlagsrechnung = 'abschlagsrechnung';
    case Anzahlungsrechnung = 'anzahlungsrechnung';
    case Schlussrechnung = 'schlussrechnung';
    case Storno = 'storno';
    case Gutschrift = 'gutschrift';
    case Mahnung = 'mahnung';

    /**
     * The string values of the advance-invoice types, for querying siblings.
     *
     * @return list<string>
     */
    public static function advanceInvoiceValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isAdvanceInvoice()),
        ));
    }

    /**
     * Whether the document carries VAT relevance and therefore triggers the
     * §14 UStG content rules and GoBD immutability on finalization.
     */
    public function isTaxRelevant(): bool
    {
        return match ($this) {
            self::Rechnung,
            self::Abschlagsrechnung,
            self::Anzahlungsrechnung,
            self::Schlussrechnung,
            self::Storno,
            self::Gutschrift => true,
            self::Angebot,
            self::Kostenvoranschlag,
            self::Beleg,
            self::Leistungsnachweis,
            self::Teilzahlung,
            self::Mahnung => false,
        };
    }

    /**
     * Once finalized, a tax-relevant document must be immutable
     * (Unveränderbarkeit, §146 Abs. 4 AO / GoBD).
     */
    public function isImmutableOnFinalize(): bool
    {
        return $this->isTaxRelevant();
    }

    /**
     * Whether this is a partial/advance invoice whose already-invoiced net AND
     * VAT a later Schlussrechnung must deduct (§14 Abs. 5 Satz 2 UStG) to avoid
     * the §14c double-VAT trap: the progress invoice ({@see self::Abschlagsrechnung})
     * and the advance-payment invoice ({@see self::Anzahlungsrechnung}, §13 Abs. 1
     * Nr. 1a UStG).
     */
    public function isAdvanceInvoice(): bool
    {
        return $this === self::Abschlagsrechnung || $this === self::Anzahlungsrechnung;
    }

    /**
     * Whether the document may be exported as a structured EN 16931 e-invoice.
     * A Mahnung, Angebot or Kostenvoranschlag is not an invoice and cannot.
     */
    public function canEmitEInvoice(): bool
    {
        return match ($this) {
            self::Rechnung,
            self::Abschlagsrechnung,
            self::Anzahlungsrechnung,
            self::Schlussrechnung,
            self::Storno,
            self::Gutschrift => true,
            default => false,
        };
    }

    /**
     * The EN 16931 invoice type code (BT-3, code list UNCL1001) for the
     * structured e-invoice: 380 commercial invoice, 381 credit note (a Storno
     * reverses via a full credit), 386 prepayment invoice (Anzahlungsrechnung),
     * 389 self-billed invoice (Gutschrift, §14 Abs. 2 UStG). Only defined for
     * types that {@see self::canEmitEInvoice()}.
     */
    public function en16931TypeCode(): string
    {
        return match ($this) {
            self::Rechnung,
            self::Abschlagsrechnung,
            self::Schlussrechnung => '380',
            self::Storno => '381',
            self::Anzahlungsrechnung => '386',
            self::Gutschrift => '389',
            default => throw new LogicException("Document type {$this->value} has no EN 16931 invoice type code."),
        };
    }

    /**
     * The default numbering series key for this type.
     */
    public function defaultSeries(): string
    {
        return $this->value;
    }

    /**
     * Whether a pre-invoice document (Angebot, Kostenvoranschlag,
     * Leistungsnachweis) may be converted into the given invoice type, copying
     * its line items forward and keeping a `source_document_id` audit link (offer
     * → contract → invoice). Corrections/cancellations are NOT conversions — use
     * a linked Storno instead. See docs/research/05-document-types-and-lifecycle.md.
     */
    public function canConvertTo(self $target): bool
    {
        return match ($this) {
            self::Angebot,
            self::Kostenvoranschlag,
            self::Leistungsnachweis => in_array($target, [self::Rechnung, self::Abschlagsrechnung, self::Schlussrechnung], true),
            default => false,
        };
    }

    /**
     * Whether the literal label "Gutschrift" is reserved for self-billing only
     * (§14 Abs. 2 UStG). A correction of your own invoice must NOT be labelled
     * Gutschrift — use {@see self::Storno}.
     */
    public function reservesGutschriftLabel(): bool
    {
        return $this === self::Gutschrift;
    }
}
