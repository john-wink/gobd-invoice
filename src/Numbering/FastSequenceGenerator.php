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
 * Gap-tolerant, high-throughput sequence generator. Each call performs a single
 * atomic increment (`UPDATE ... SET current_value = current_value + 1`) and reads
 * the resulting value inside a SHORT transaction, so the row lock is held only for
 * the increment itself — not for the caller's whole operation. The counter is
 * therefore the bottleneck for only microseconds per call, allowing thousands of
 * finalizations per second on a single (type, series, year) sequence.
 *
 * The trade-off is that the number is committed before the caller finishes, so a
 * later failure leaves a gap. §14 Abs. 4 Nr. 4 UStG requires UNIQUENESS, not
 * gaplessness, and gaps are explicable in the Verfahrensdokumentation — so this is
 * a legitimate strategy for high-volume issuers. Use the default
 * {@see LockingSequenceGenerator} when strict gaplessness is preferred. Select via
 * `config('gobd-invoice.numbering.strategy')`.
 */
final class FastSequenceGenerator implements NumberSequenceGenerator
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

        // Ensure the counter row exists before incrementing it.
        $model::query()->firstOrCreate($keys, ['current_value' => 0]);

        return DB::transaction(function () use ($model, $keys, $documentType, $series, $year, $format): DocumentNumber {
            // The atomic UPDATE locks the row; reading it back within the same
            // short transaction yields exactly this caller's value.
            $model::query()->where($keys)->increment('current_value');
            $numberSequence = $model::query()->where($keys)->firstOrFail();

            return DocumentNumber::fromParts($documentType, $series, $year, $numberSequence->current_value, $format);
        });
    }

    /**
     * Gap-tolerant: the number is allocated and committed up front (outside the
     * caller's finalize transaction), so the lock is released early.
     */
    public function allocatesWithinTransaction(): bool
    {
        return false;
    }
}
