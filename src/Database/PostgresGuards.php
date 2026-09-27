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
 * - a festgeschriebenes tax-relevant document keeps its §14 content, its
 *   retention and its host link, cannot be deleted and never returns to draft;
 * - a status only moves along DocumentStatus::allowedTransitions(), and a
 *   cancelled document keeps its status for good;
 * - a festgeschriebener Storno and the cancelled document it references commit
 *   together, and a Storno without that reference cannot be festgeschrieben;
 * - its lines cannot be added, changed or removed, and a line of a document
 *   the session cannot see (row level security) is treated the same way;
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

        self::protectStatus($table);
        self::pairStornoAndOriginal($table, $types);
    }

    /**
     * A line changes only while its document is a draft (or of a type that
     * stays editable) and the session can see that document. The document is
     * read as a requirement, never as a finding: under row level security a
     * document the session cannot see counts as festgeschrieben, so the guard
     * fails closed like the Storno guard. A line without a document cannot be
     * written either.
     *
     * The document is read FOR SHARE: a line write waits for a Festschreibung
     * that holds the document row and is then judged by the committed row, so
     * it is refused. A plain read would still see the draft and let it pass.
     * The share lock stays until the line write commits, and a Festschreibung
     * waits for it in turn.
     */
    public static function protectLines(string $lines, string $documents): void
    {
        if (! self::applies()) {
            return;
        }

        $table = self::identifier($lines);
        $parent = self::identifier($documents);
        $types = self::immutableTypeList();
        $tenant = Tenancy::column();
        $immutable = 'gobd-invoice: the lines of a finalized document are immutable (GoBD Unveraenderbarkeit); a line changes only while this session can see its document as a draft';

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

                IF TG_OP <> 'INSERT' THEN
                    PERFORM 1 FROM {$parent} d
                    WHERE d.id = OLD.document_id AND (d.finalized_at IS NULL OR d.type NOT IN ({$types}))
                    FOR SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION '{$immutable}';
                    END IF;
                END IF;

                IF TG_OP <> 'DELETE' THEN
                    PERFORM 1 FROM {$parent} d
                    WHERE d.id = NEW.document_id AND (d.finalized_at IS NULL OR d.type NOT IN ({$types}))
                    FOR SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION '{$immutable}';
                    END IF;
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

        foreach (['guard', 'status_guard', 'storno_guard', 'refuse_truncate'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$table}_{$function}() CASCADE");
        }
    }

    /**
     * Every status the statement sets must be reachable by
     * DocumentStatus::allowedTransitions(); a cancelled document keeps its
     * status for good, so a second cancellation fails even when it would not
     * change the value.
     */
    private static function protectStatus(string $table): void
    {
        $cancelled = DocumentStatus::Cancelled->value;
        $transitions = self::allowedTransitionList();

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$table}_status_guard() RETURNS trigger LANGUAGE plpgsql AS \$guard\$
            BEGIN
                IF OLD.status = '{$cancelled}' THEN
                    RAISE EXCEPTION 'gobd-invoice: document % is cancelled; its status is final', OLD.number;
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND (OLD.status || '>' || NEW.status) NOT IN ({$transitions}) THEN
                    RAISE EXCEPTION 'gobd-invoice: document % cannot change its status from % to %', OLD.number, OLD.status, NEW.status;
                END IF;

                RETURN NEW;
            END;
            \$guard\$
            SQL);
        DB::statement("DROP TRIGGER IF EXISTS {$table}_status_guard ON {$table}");
        DB::statement("CREATE TRIGGER {$table}_status_guard BEFORE UPDATE OF status ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_status_guard()");
    }

    /**
     * A Storno and the document it cancels commit together: a festgeschriebener
     * Storno references a festgeschriebenes document of its tenant that is
     * cancelled, and a festgeschriebenes tax-relevant document is cancelled
     * only by such a Storno. Checked at commit (deferred), so the two rows may
     * be written in either order within one transaction.
     *
     * The checked row is the version the trigger hands in (NEW), never a
     * re-read: under row level security a session whose tenant context is
     * gone by the commit would not see its own row. Every status, type and
     * finalization a violation needs arrives as the NEW of some queued event,
     * because those values are final once festgeschrieben. The partner row is
     * still read, but only as a requirement that must exist: a row the
     * session cannot see counts as missing, so the check fails closed. No
     * SECURITY DEFINER: under FORCE ROW LEVEL SECURITY the table owner sees
     * no more than the session, and the package cannot hand out BYPASSRLS.
     */
    private static function pairStornoAndOriginal(string $table, string $types): void
    {
        $storno = DocumentType::Storno->value;
        $cancelled = DocumentStatus::Cancelled->value;
        $tenant = Tenancy::column();
        $sameTenant = $tenant === null ? '' : "AND related.{$tenant} = NEW.{$tenant}";

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$table}_storno_guard() RETURNS trigger LANGUAGE plpgsql AS \$guard\$
            BEGIN
                IF NEW.finalized_at IS NULL THEN
                    RETURN NULL;
                END IF;

                IF NEW.type = '{$storno}' AND NOT EXISTS (
                    SELECT 1 FROM {$table} related
                    WHERE related.id = NEW.source_document_id
                        AND related.finalized_at IS NOT NULL
                        AND related.status = '{$cancelled}'
                        {$sameTenant}
                ) THEN
                    RAISE EXCEPTION 'gobd-invoice: Storno % must reference a finalized, cancelled document of its tenant that this session can see', NEW.number;
                END IF;

                IF NEW.status = '{$cancelled}' AND NEW.type IN ({$types}) AND NOT EXISTS (
                    SELECT 1 FROM {$table} related
                    WHERE related.source_document_id = NEW.id
                        AND related.type = '{$storno}'
                        AND related.finalized_at IS NOT NULL
                        {$sameTenant}
                ) THEN
                    RAISE EXCEPTION 'gobd-invoice: document % can only be cancelled by a finalized Storno that references it and that this session can see', NEW.number;
                END IF;

                RETURN NULL;
            END;
            \$guard\$
            SQL);
        DB::statement("DROP TRIGGER IF EXISTS {$table}_storno_guard ON {$table}");
        DB::statement(<<<SQL
            CREATE CONSTRAINT TRIGGER {$table}_storno_guard AFTER INSERT OR UPDATE ON {$table}
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
            WHEN (NEW.type = '{$storno}' OR NEW.status = '{$cancelled}')
            EXECUTE FUNCTION {$table}_storno_guard()
            SQL);
    }

    private static function allowedTransitionList(): string
    {
        $transitions = [];

        foreach (DocumentStatus::cases() as $documentStatus) {
            foreach ($documentStatus->allowedTransitions() as $target) {
                $transitions[] = "'{$documentStatus->value}>{$target->value}'";
            }
        }

        return implode(', ', $transitions);
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
