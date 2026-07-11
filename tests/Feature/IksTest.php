<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\ActorResolver;
use JohnWink\GobdInvoice\Enums\DocumentStatus;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Exceptions\IksViolationException;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\GobdInvoiceManager;
use JohnWink\GobdInvoice\Models\AuditLogEntry;
use JohnWink\GobdInvoice\Models\Document;

/**
 * A resolver whose actor is a mutable static, so a test can set "who is acting"
 * before invoking the manager. The manager reads it live via {@see resolve()}.
 */
final class TestActorResolver implements ActorResolver
{
    public static ?string $actor = null;

    public function resolve(): ?string
    {
        return self::$actor;
    }
}

function rebuildManager(): void
{
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
}

function actAs(?string $actor): void
{
    TestActorResolver::$actor = $actor;
    // Rebuild so the next operation resolves a manager bound to the current actor.
    rebuildManager();
}

function useFourEyes(): void
{
    config()->set('gobd-invoice.iks.segregation', 'four_eyes');
    rebuildManager(); // the segregation policy is chosen at manager construction
}

function iksDraft(): Document
{
    return draftWithParties(DocumentType::Rechnung, [
        ['description' => 'Leistung', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.0'],
    ], ['issue_date' => '2026-02-01']);
}

beforeEach(function (): void {
    TestActorResolver::$actor = null;
    config()->set('gobd-invoice.iks.segregation', 'permissive');
    app()->bind(ActorResolver::class, TestActorResolver::class);
    app()->forgetInstance(GobdInvoiceManager::class);
    GobdInvoice::clearResolvedInstance(GobdInvoiceManager::class);
});

it('records the acting actor as created_by and on the audit trail', function (): void {
    actAs('alice');

    $invoice = GobdInvoice::finalize(iksDraft());

    $finalizedEntry = AuditLogEntry::query()
        ->where('document_id', $invoice->id)
        ->where('event', 'finalized')
        ->first();

    expect($invoice->created_by)->toBe('alice')
        ->and($finalizedEntry?->actor)->toBe('alice');
});

it('permits the same actor to draft and finalize by default (permissive)', function (): void {
    actAs('alice');

    $invoice = GobdInvoice::finalize(iksDraft());

    expect($invoice->status)->toBe(DocumentStatus::Finalized);
});

it('blocks the creator from finalizing their own draft under four-eyes', function (): void {
    useFourEyes();
    actAs('alice');

    $draft = iksDraft(); // created_by = alice

    // The same actor may not finalize their own draft (Vier-Augen-Prinzip).
    expect(fn (): Document => GobdInvoice::finalize($draft))->toThrow(IksViolationException::class);
});

it('does not block an anonymous action under four-eyes', function (): void {
    useFourEyes();
    actAs(null); // no identifiable actor

    $invoice = GobdInvoice::finalize(iksDraft());

    expect($invoice->created_by)->toBeNull()
        ->and($invoice->status)->toBe(DocumentStatus::Finalized);
});

it('completes a cancel under four-eyes without the internal Storno tripping the gate', function (): void {
    useFourEyes();

    actAs('alice');
    $draft = iksDraft();

    // A second person finalizes (allowed), then cancels (allowed). The Storno
    // that cancel() festschreibt internally is drafted AND finalized by Bob, so a
    // naive gate would wrongly veto it — this proves it does not.
    actAs('bob');
    $invoice = GobdInvoice::finalize($draft);
    $storno = GobdInvoice::cancel($invoice, 'Widerruf');

    expect($storno->type)->toBe(DocumentType::Storno)
        ->and($storno->status)->toBe(DocumentStatus::Finalized);
});
