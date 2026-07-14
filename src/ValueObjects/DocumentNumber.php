<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use JohnWink\GobdInvoice\Enums\DocumentType;
use Stringable;

/**
 * An immutable, formatted document number. §14 Abs. 4 Nr. 4 UStG requires a
 * unique, systematic invoice number; this value object carries the structured
 * parts plus the canonical printed string. See docs/research/02 and
 * docs/research/08 (B7/B8).
 */
final readonly class DocumentNumber implements Stringable
{
    public function __construct(
        public DocumentType $type,
        public string $series,
        public int $year,
        public int $sequence,
        public string $formatted,
    ) {}

    public function __toString(): string
    {
        return $this->formatted;
    }

    /**
     * Build a number from its parts using a format template such as
     * "{type}-{year}-{seq:5}". Supported tokens: {type}, {series}, {year},
     * {seq} and {seq:N} (zero-padded to N digits).
     */
    public static function fromParts(
        DocumentType $documentType,
        string $series,
        int $year,
        int $sequence,
        string $format = '{type}-{year}-{seq:5}',
    ): self {
        $replacements = [
            '{type}' => $documentType->value,
            '{series}' => $series,
            '{year}' => (string) $year,
            '{seq}' => (string) $sequence,
        ];

        if (preg_match('/\{seq:(\d+)\}/', $format, $matches) === 1) {
            $replacements['{seq:'.$matches[1].'}'] = mb_str_pad(
                (string) $sequence,
                (int) $matches[1],
                '0',
                STR_PAD_LEFT,
            );
        }

        return new self($documentType, $series, $year, $sequence, strtr($format, $replacements));
    }
}
