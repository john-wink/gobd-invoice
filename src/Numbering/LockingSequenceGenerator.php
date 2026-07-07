<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Numbering;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Models\NumberSequence;
use JohnWink\GobdInvoice\ValueObjects\DocumentNumber;

/**
 * Race-safe sequence generator. The sequence row is created idempotently
 * (Laravel's firstOrCreate retries on a unique-constraint race) and then locked
 * with `lockForUpdate()` inside a transaction before being incremented, so two
 * concurrent requests can never receive the same number.
 *
 * NOTE: `lockForUpdate()` emits a real row lock on MySQL/MariaDB and PostgreSQL;
 * on SQLite it is a no-op clause but whole-database write serialization keeps
 * the result correct. Prove the guarantee on MySQL/Postgres in CI, not only on
 * the in-memory SQLite suite. See docs/research/08-package-architecture.md (B8).
 */
final class LockingSequenceGenerator implements NumberSequenceGenerator
{
    public function next(DocumentType $documentType, string $series, int $year): DocumentNumber
    {
        /** @var class-string<NumberSequence> $model */
        $model = config('gobd-invoice.models.sequence', NumberSequence::class);

        $format = Config::string('gobd-invoice.numbering.format', '{type}-{year}-{seq:5}');

        $keys = [
            'document_type' => $documentType->value,
            'series' => $series,
            'year' => $year,
        ];

        // Ensure the counter row exists before locking it.
        $model::query()->firstOrCreate($keys, ['current_value' => 0]);

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

    /**
     * Gapless: the increment must live inside the caller's finalize transaction so
     * a rolled-back finalize also rolls back the consumed number.
     */
    public function allocatesWithinTransaction(): bool
    {
        return true;
    }
}
