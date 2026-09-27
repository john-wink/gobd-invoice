<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The key type of the package tables, selected by
 * `config('gobd-invoice.database.key_type')`: auto-incrementing big integers
 * (the default) or UUIDv7 strings for hosts that key everything by UUID. It
 * applies to the primary keys, the document references and the host
 * `documentable` morph. An unknown value fails loud — a silently wrong key
 * type would only surface once the schema is in production.
 */
enum KeyType: string
{
    case Bigint = 'bigint';
    case Uuid = 'uuid';

    public static function configured(): self
    {
        $value = Config::string('gobd-invoice.database.key_type', self::Bigint->value);

        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("gobd-invoice.database.key_type must be 'bigint' or 'uuid', got [{$value}].");
    }

    /**
     * The Eloquent cast of a column that stores a key of this type.
     */
    public function cast(): string
    {
        return match ($this) {
            self::Bigint => 'integer',
            self::Uuid => 'string',
        };
    }

    public function primaryKey(Blueprint $blueprint): void
    {
        match ($this) {
            self::Bigint => $blueprint->id(),
            self::Uuid => $blueprint->uuid('id')->primary(),
        };
    }

    public function referenceColumn(Blueprint $blueprint, string $column): ColumnDefinition
    {
        return match ($this) {
            self::Bigint => $blueprint->unsignedBigInteger($column),
            self::Uuid => $blueprint->uuid($column),
        };
    }

    public function nullableMorphs(Blueprint $blueprint, string $name): void
    {
        match ($this) {
            self::Bigint => $blueprint->nullableMorphs($name),
            self::Uuid => $blueprint->nullableUuidMorphs($name),
        };
    }

    public function newKey(): ?string
    {
        return match ($this) {
            self::Bigint => null,
            self::Uuid => (string) Str::uuid7(),
        };
    }

    /**
     * Whether a host-supplied value is a valid key of this type.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Bigint => is_int($value) || (is_string($value) && ctype_digit($value)),
            self::Uuid => is_string($value) && Str::isUuid($value),
        };
    }

    /**
     * Normalize a host-supplied key, failing loud on a value of the wrong type.
     */
    public function normalize(mixed $value, string $label): int|string
    {
        if (! $this->accepts($value)) {
            $shown = is_scalar($value) ? (string) $value : get_debug_type($value);

            throw new InvalidArgumentException("{$label} must be a {$this->value} key, got [{$shown}].");
        }

        /** @var int|string $value */
        return $this === self::Bigint ? (int) $value : (string) $value;
    }
}
