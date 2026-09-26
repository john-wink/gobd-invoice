<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Numbering;

use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Models\NumberSequence;
use JohnWink\GobdInvoice\ValueObjects\DocumentNumber;

/**
 * The race-safe, gapless increment used by {@see LockingSequenceGenerator} and
 * available for host subclasses. The counter row is created idempotently and
 * then locked with `lockForUpdate()` inside a transaction before it is
 * incremented, so two concurrent requests can never receive the same number.
 *
 * The counter KEY and printed FORMAT are resolved through
 * {@see ResolvesSequenceKeyAndFormat} — override those (not this method) to
 * customize scoping or formatting.
 *
 * `lockForUpdate()` emits a real row lock (SELECT … FOR UPDATE) on PostgreSQL
 * and MySQL/MariaDB; on SQLite it is a no-op clause and whole-database write
 * serialization keeps the result correct. The guarantee is proven against
 * PostgreSQL with parallel OS processes (tests/Feature/ConcurrentNumberingTest).
 */
trait LocksAndIncrementsSequence
{
    use ResolvesSequenceKeyAndFormat;

    public function next(DocumentType $documentType, string $series, int $year, int|string|null $tenant = null): DocumentNumber
    {
        /** @var class-string<NumberSequence> $model */
        $model = config('gobd-invoice.models.sequence', NumberSequence::class);

        $keys = $this->sequenceKeys($documentType, $series, $year, $tenant);
        $format = $this->formatFor($documentType, $series, $year, $tenant);

        // Ensure the counter row exists before locking it. A concurrent first
        // insert loses on the unique index and reads the winner's row instead
        // (createOrFirst runs the insert in a savepoint inside a transaction).
        $this->ensureCounterExists($model, $keys);

        return DB::transaction(function () use ($model, $keys, $documentType, $series, $year, $format): DocumentNumber {
            $sequence = $model::query()
                ->where($keys)
                ->lockForUpdate()
                ->firstOrFail();

            $next = $sequence->current_value + 1;
            $sequence->current_value = $next;
            $sequence->save();

            return DocumentNumber::fromParts($documentType, $series, $year, $next, $format);
        });
    }
}
