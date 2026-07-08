<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * A party to an invoice — the supplier (leistender Unternehmer) or the recipient
 * (Leistungsempfänger). §14 Abs. 4 Nr. 1 UStG requires the full name and full
 * address of both; Nr. 2 requires the supplier's Steuernummer or USt-IdNr.
 *
 * Fields are individually optional so a draft can be filled incrementally; the
 * §14 completeness checks live in the content validator and run at finalization
 * (fail closed). See docs/research/02-legal-invoice-content.md.
 */
final readonly class Party
{
    public function __construct(
        public string $name,
        public ?string $addressLine = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $taxNumber = null,
        public ?string $vatId = null,
    ) {}

    /**
     * Rebuild from a stored/host array, reading string keys defensively.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key])
            ? (string) $data[$key]
            : null;

        return new self(
            $string('name') ?? '',
            $string('address_line'),
            $string('postal_code'),
            $string('city'),
            $string('country'),
            $string('tax_number'),
            $string('vat_id'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address_line' => $this->addressLine,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'tax_number' => $this->taxNumber,
            'vat_id' => $this->vatId,
        ];
    }

    /** §14 Abs. 4 Nr. 1: a complete name + address (Postfach permitted as the line). */
    public function hasCompleteAddress(): bool
    {
        return $this->isFilled($this->name)
            && $this->isFilled($this->addressLine)
            && $this->isFilled($this->postalCode)
            && $this->isFilled($this->city);
    }

    /** §14 Abs. 4 Nr. 2: a Steuernummer or USt-IdNr (either satisfies the XOR). */
    public function hasTaxId(): bool
    {
        if ($this->isFilled($this->taxNumber)) {
            return true;
        }

        return $this->isFilled($this->vatId);
    }

    private function isFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
