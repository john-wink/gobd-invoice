<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

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
    case Schlussrechnung = 'schlussrechnung';
    case Storno = 'storno';
    case Gutschrift = 'gutschrift';
    case Mahnung = 'mahnung';

    /**
     * Whether the document carries VAT relevance and therefore triggers the
     * §14 UStG content rules and GoBD immutability on finalization.
     */
    public function isTaxRelevant(): bool
    {
        return match ($this) {
            self::Rechnung,
            self::Abschlagsrechnung,
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
     * the §14c double-VAT trap. Currently the {@see self::Abschlagsrechnung} is
     * the only modelled advance type; a distinct Anzahlungs-/Vorauszahlungs-
     * rechnung (advance-payment, §13 Abs. 1 Nr. 1a) is a planned future case.
     */
    public function isAdvanceInvoice(): bool
    {
        return $this === self::Abschlagsrechnung;
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
            self::Schlussrechnung,
            self::Storno,
            self::Gutschrift => true,
            default => false,
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
     * Whether the literal label "Gutschrift" is reserved for self-billing only
     * (§14 Abs. 2 UStG). A correction of your own invoice must NOT be labelled
     * Gutschrift — use {@see self::Storno}.
     */
    public function reservesGutschriftLabel(): bool
    {
        return $this === self::Gutschrift;
    }
}
