<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Database-side GoBD guards for PostgreSQL. The model guards only see writes
 * that go through Eloquent; these triggers also refuse raw statements, bulk
 * updates and any second application writing to the same tables:
 *
 * - a festgeschriebenes tax-relevant document keeps its §14 content, cannot be
 *   deleted and never returns to draft;
 * - its lines cannot be added, changed or removed;
 * - the audit log is append-only;
 * - a number counter only moves forward and is never deleted;
 * - TRUNCATE is refused on all four tables;
 * - with tenancy, no row ever changes its tenant and a line always belongs to
 *   the tenant of its document.
 *
 * On other drivers every method is a no-op; the model guards remain the only
 * line there. Table and column names are interpolated into the SQL, so they
 * must be plain lower-case identifiers.
 */
final class PostgresGuards
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    public static function applies(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    public static function protectDocuments(string $documents): void
    {
        if (! self::applies()) {
            return;
        }

        $table = self::identifier($documents);
        $types = self::immutableTypeList();
        $draft = DocumentStatus::Draft->value;
        $tenant = Tenancy::column();
        $tenantUnchanged = $tenant === null ? '' : <<<SQL
            IF NEW.{$tenant} IS DISTINCT FROM OLD.{$tenant} THEN
                RAISE EXCEPTION 'gobd-invoice: the tenant of document % is immutable', OLD.id;
            END IF;
            SQL;
        $contentChanged = implode("\n                    OR ", array_map(
            static fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            Document::immutableColumns(),
        ));

        self::installRowGuard($table, 'BEFORE UPDATE OR DELETE', <<<SQL
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.finalized_at IS NOT NULL AND OLD.type IN ({$types}) THEN
                        RAISE EXCEPTION 'gobd-invoice: document % is finalized and cannot be deleted (GoBD Unveraenderbarkeit)', OLD.number;
                    END IF;

                    RETURN OLD;
                END IF;

                {$tenantUnchanged}

                IF OLD.finalized_at IS NOT NULL AND NEW.status = '{$draft}' THEN
                    RAISE EXCEPTION 'gobd-invoice: document % is finalized and cannot return to draft', OLD.number;
                END IF;

                IF OLD.finalized_at IS NOT NULL AND OLD.type IN ({$types}) AND (
                    {$contentChanged}
                ) THEN
                    RAISE EXCEPTION 'gobd-invoice: document % is finalized and immutable (GoBD Unveraenderbarkeit); correct it with a Storno', OLD.number;
                END IF;

                RETURN NEW;
            END;
            SQL);
    }

    public static function protectLines(string $lines, string $documents): void
    {
        if (! self::applies()) {
            return;
        }

        $table = self::identifier($lines);
        $parent = self::identifier($documents);
        $types = self::immutableTypeList();
        $tenant = Tenancy::column();

        $tenantChecks = $tenant === null ? '' : <<<SQL
            IF TG_OP = 'UPDATE' AND NEW.{$tenant} IS DISTINCT FROM OLD.{$tenant} THEN
                RAISE EXCEPTION 'gobd-invoice: the tenant of a line is immutable';
            END IF;

            IF TG_OP <> 'DELETE' AND NOT EXISTS (
                SELECT 1 FROM {$parent} d WHERE d.id = NEW.document_id AND d.{$tenant} = NEW.{$tenant}
            ) THEN
                RAISE EXCEPTION 'gobd-invoice: a line must belong to the tenant of its document';
            END IF;
            SQL;

        self::installRowGuard($table, 'BEFORE INSERT OR UPDATE OR DELETE', <<<SQL
            BEGIN
                {$tenantChecks}

                IF TG_OP <> 'INSERT' AND EXISTS (
                    SELECT 1 FROM {$parent} d
                    WHERE d.id = OLD.document_id AND d.finalized_at IS NOT NULL AND d.type IN ({$types})
                ) THEN
                    RAISE EXCEPTION 'gobd-invoice: the lines of a finalized document are immutable (GoBD Unveraenderbarkeit)';
                END IF;

                IF TG_OP <> 'DELETE' AND EXISTS (
                    SELECT 1 FROM {$parent} d
                    WHERE d.id = NEW.document_id AND d.finalized_at IS NOT NULL AND d.type IN ({$types})
                ) THEN
                    RAISE EXCEPTION 'gobd-invoice: the lines of a finalized document are immutable (GoBD Unveraenderbarkeit)';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            SQL);
    }

    public static function protectSequences(string $sequences): void
    {
        if (! self::applies()) {
            return;
        }

        $table = self::identifier($sequences);
        $tenant = Tenancy::column();
        $keyColumns = [...($tenant === null ? [] : [$tenant]), 'document_type', 'series', 'year'];

        $keyChanged = implode(' OR ', array_map(
            static fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            $keyColumns,
        ));

        self::installRowGuard($table, 'BEFORE UPDATE OR DELETE', <<<SQL
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'gobd-invoice: a number counter cannot be deleted; its numbers stay issued';
                END IF;

                IF {$keyChanged} THEN
                    RAISE EXCEPTION 'gobd-invoice: the key (tenant, type, series, year) of a number counter is immutable';
                END IF;

                IF NEW.current_value < OLD.current_value THEN
                    RAISE EXCEPTION 'gobd-invoice: a number counter cannot run backwards (% -> %)', OLD.current_value, NEW.current_value;
                END IF;

                RETURN NEW;
            END;
            SQL);
    }

    public static function protectAuditLog(string $auditLog): void
    {
        if (! self::applies()) {
            return;
        }

        self::installRowGuard(self::identifier($auditLog), 'BEFORE UPDATE OR DELETE', <<<'SQL'
            BEGIN
                RAISE EXCEPTION 'gobd-invoice: the audit log is append-only; entries cannot be changed or removed';
            END;
            SQL);
    }

    /**
     * Remove the guards of a table (used by the migrations' down()).
     */
    public static function release(string $table): void
    {
        if (! self::applies()) {
            return;
        }

        $table = self::identifier($table);

        DB::statement("DROP FUNCTION IF EXISTS {$table}_guard() CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS {$table}_refuse_truncate() CASCADE");
    }

    private static function installRowGuard(string $table, string $timing, string $body): void
    {
        DB::statement("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$guard\$\n{$body}\n\$guard\$");
        DB::statement("DROP TRIGGER IF EXISTS {$table}_guard ON {$table}");
        DB::statement("CREATE TRIGGER {$table}_guard {$timing} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard()");

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$table}_refuse_truncate() RETURNS trigger LANGUAGE plpgsql AS \$guard\$
            BEGIN
                RAISE EXCEPTION 'gobd-invoice: % cannot be truncated (GoBD Unveraenderbarkeit)', TG_TABLE_NAME;
            END;
            \$guard\$
            SQL);
        DB::statement("DROP TRIGGER IF EXISTS {$table}_refuse_truncate ON {$table}");
        DB::statement("CREATE TRIGGER {$table}_refuse_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION {$table}_refuse_truncate()");
    }

    private static function immutableTypeList(): string
    {
        $immutableTypes = array_filter(DocumentType::cases(), static fn (DocumentType $documentType): bool => $documentType->isImmutableOnFinalize());

        return implode(', ', array_map(static fn (DocumentType $documentType): string => "'{$documentType->value}'", $immutableTypes));
    }

    private static function identifier(string $name): string
    {
        throw_if(preg_match(self::IDENTIFIER, $name) !== 1, InvalidArgumentException::class, "[{$name}] is not a plain lower-case identifier; the guards interpolate it into SQL.");

        return $name;
    }
}
