<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use JohnWink\GobdInvoice\Enums\KeyType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;

/**
 * Optional multi-tenancy, selected by `config('gobd-invoice.tenancy.column')`.
 *
 * Unset (the default), the package is single-tenant and a document number is
 * unique across the whole table. Set to a column name (e.g. `team_id`), every
 * package table carries that column (NOT NULL), each tenant runs its own
 * counters, and a number is unique per tenant: UNIQUE(tenant, number). The
 * uniqueness then lives in a database constraint, not in a format convention
 * such as a mandatory tenant prefix. The tenant of a row never changes.
 */
final class Tenancy
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public static function column(): ?string
    {
        $column = Config::get('gobd-invoice.tenancy.column');

        if ($column === null || $column === '') {
            return null;
        }

        throw_if(! is_string($column) || preg_match(self::IDENTIFIER, $column) !== 1, InvalidArgumentException::class, 'gobd-invoice.tenancy.column must be null or a lower-case column name.');

        return $column;
    }

    public static function isEnabled(): bool
    {
        return self::column() !== null;
    }

    public static function addColumn(Blueprint $blueprint): void
    {
        $column = self::column();

        if ($column !== null) {
            KeyType::configured()->referenceColumn($blueprint, $column)->index();
        }
    }

    /**
     * The tenant of a model row, or null in single-tenant mode.
     */
    public static function of(Model $model): int|string|null
    {
        $column = self::column();

        if ($column === null) {
            return null;
        }

        $tenant = $model->getAttribute($column);

        return is_int($tenant) || is_string($tenant) ? $tenant : null;
    }

    /**
     * The tenant attribute to stamp on a dependent row (line, audit entry,
     * counter), empty in single-tenant mode.
     *
     * @return array<string, int|string>
     */
    public static function attributesFor(int|string|null $tenant): array
    {
        $column = self::column();

        if ($column === null) {
            return [];
        }

        throw_if($tenant === null, GobdInvoiceException::class, "gobd-invoice is multi-tenant (column [{$column}]); the operation needs the tenant of its document.");

        return [$column => $tenant];
    }

    /**
     * Refuse to change the tenant of an existing row.
     */
    public static function guardAgainstTenantChange(Model $model): void
    {
        $column = self::column();

        throw_if($column !== null && $model->exists && $model->isDirty($column), GobdInvoiceException::class, "The tenant ([{$column}]) of a gobd-invoice row is immutable.");
    }
}
