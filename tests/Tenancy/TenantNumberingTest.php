<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\Models\NumberSequence;
use JohnWink\GobdInvoice\Numbering\FastSequenceGenerator;
use JohnWink\GobdInvoice\Numbering\LockingSequenceGenerator;

it('runs an independent counter per tenant, so two teams may issue the same number', function (): void {
    $teamA = newTenant();
    $teamB = newTenant();

    $firstOfA = GobdInvoice::finalize(tenantDraft($teamA));
    $secondOfA = GobdInvoice::finalize(tenantDraft($teamA));
    $firstOfB = GobdInvoice::finalize(tenantDraft($teamB));

    expect([$firstOfA->sequence, $secondOfA->sequence, $firstOfB->sequence])->toBe([1, 2, 1])
        ->and($firstOfB->number)->toBe($firstOfA->number)
        ->and($firstOfA->team_id)->toBe($teamA)
        ->and($firstOfB->team_id)->toBe($teamB);
});

it('enforces UNIQUE(team_id, number) in the database', function (): void {
    $team = newTenant();
    $finalized = GobdInvoice::finalize(tenantDraft($team));
    $duplicate = tenantDraft($team);

    expect(fn () => DB::table('gobd_documents')->where('id', $duplicate->id)->update(['number' => $finalized->number]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps one counter row per (team_id, document_type, series, year) in the database', function (): void {
    $team = newTenant();
    GobdInvoice::finalize(tenantDraft($team));

    $counter = NumberSequence::query()->where('team_id', $team)->sole();

    expect(fn () => DB::table('gobd_number_sequences')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $team,
        'document_type' => $counter->document_type,
        'series' => $counter->series,
        'year' => $counter->year,
        'current_value' => 0,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to draft a document without a tenant', function (): void {
    expect(fn () => GobdInvoice::draft(DocumentType::Rechnung, [], lineSet()))
        ->toThrow(GobdInvoiceException::class);
});

it('refuses to allocate a number without a tenant', function (string $generator): void {
    expect(fn () => (new $generator)->next(DocumentType::Rechnung, 'rechnung', 2026))
        ->toThrow(GobdInvoiceException::class);
})->with([LockingSequenceGenerator::class, FastSequenceGenerator::class]);

it('stamps the document tenant on its lines, its audit entries and its counter', function (): void {
    $team = newTenant();
    $document = GobdInvoice::finalize(tenantDraft($team));

    expect($document->lines->pluck('team_id')->unique()->all())->toBe([$team])
        ->and(AuditLogEntry::query()->where('document_id', $document->id)->pluck('team_id')->unique()->all())->toBe([$team])
        ->and(NumberSequence::query()->pluck('team_id')->all())->toBe([$team]);
});

it('numbers a Storno in the tenant of the cancelled invoice', function (): void {
    $teamA = newTenant();
    GobdInvoice::finalize(tenantDraft(newTenant(), DocumentType::Storno));
    $invoice = GobdInvoice::finalize(tenantDraft($teamA));

    $storno = GobdInvoice::cancel($invoice, 'Kunde hat storniert');

    expect($storno->team_id)->toBe($teamA)
        ->and($storno->sequence)->toBe(1)
        ->and($storno->source_document_id)->toBe($invoice->id);
});

it('carries the tenant into a converted draft', function (): void {
    $team = newTenant();
    $angebot = tenantDraft($team, DocumentType::Angebot);

    expect(GobdInvoice::convert($angebot, DocumentType::Rechnung)->team_id)->toBe($team);
});

it('never lets a document move to another tenant', function (): void {
    $document = tenantDraft(newTenant());
    $document->team_id = newTenant();

    expect(fn () => $document->save())->toThrow(GobdInvoiceException::class);
});

it('never deducts an advance invoice of another tenant', function (): void {
    $advance = GobdInvoice::finalize(tenantDraft(newTenant(), DocumentType::Abschlagsrechnung));

    expect(fn () => tenantDraft(newTenant(), DocumentType::Schlussrechnung, ['deducts' => [$advance->id]]))
        ->toThrow(GobdInvoiceException::class);
});

it('deducts an advance invoice of the same tenant by its UUID', function (): void {
    $team = newTenant();
    $advance = GobdInvoice::finalize(tenantDraft($team, DocumentType::Abschlagsrechnung));

    $final = GobdInvoice::finalize(tenantDraft($team, DocumentType::Schlussrechnung, ['deducts' => [$advance->id]], lineSet('30.00')));

    expect($final->advances_net_total)->toBe(1000)
        ->and(GobdInvoice::verify($final))->toBeTrue();
});

it('keys every table by UUIDv7', function (): void {
    $document = GobdInvoice::finalize(tenantDraft(newTenant()));

    expect(Str::isUuid($document->id))->toBeTrue()
        ->and(Str::isUuid((string) $document->lines->first()?->id))->toBeTrue()
        ->and(Str::isUuid((string) AuditLogEntry::query()->where('document_id', $document->id)->value('id')))->toBeTrue()
        ->and(Str::isUuid((string) NumberSequence::query()->value('id')))->toBeTrue()
        ->and(Document::query()->find($document->id)?->is($document))->toBeTrue();
});

it('links a UUID host model as documentable', function (): void {
    $order = (string) Str::uuid7();

    $document = tenantDraft(newTenant(), DocumentType::Rechnung, ['documentable_type' => 'order', 'documentable_id' => $order]);

    expect($document->fresh()?->documentable_id)->toBe($order);
});
