<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JournalEntry
//
//  The header row for every posting to the General Ledger — every
//  auto-posted business transaction and every future manual entry
//  (Phase 4). Written exclusively through JournalEntryService::post()
//  / reverse() — see that service for the full design rationale.
//
//  Append-only: a posted entry is never edited. status transitions
//  only draft-less posted → reversed, via a second, linked entry.
//
//  @layer    Model
//  @depends  BelongsToCompany, JournalEntryLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED   = 'posted';
    const STATUS_REVERSED = 'reversed';

    const SOURCE_MANUAL = 'manual';

    // ── journal_type — set automatically by JournalEntryService::post(),
    // never chosen directly by a caller. September 2026, per Mahmoud.
    const TYPE_AUTOMATED = 'automated';
    const TYPE_MANUAL    = 'manual';

    // OB-001 fix (2026-09) — the source_type the Opening Balances
    // module posts under. Named here rather than typed as a string at
    // the call site because JournalEntryService::reverse() has to
    // recognise it: an opening entry is the one entry in the system
    // that may never be reversed.
    const SOURCE_OPENING_BALANCE = 'opening_balance';

    protected $fillable = [
        'company_id', 'entry_number', 'entry_date', 'accounting_period_id',
        'source_type', 'source_id', 'journal_type', 'narration', 'status',
        'reverses_journal_entry_id', 'reversed_by_journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_journal_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_journal_entry_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOfSource(Builder $query, string $type, ?int $id = null): Builder
    {
        return $query->where('source_type', $type)->when($id, fn ($q) => $q->where('source_id', $id));
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('journal_type', self::TYPE_MANUAL);
    }

    public function scopeAutomated(Builder $query): Builder
    {
        return $query->where('journal_type', self::TYPE_AUTOMATED);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function isManual(): bool
    {
        return $this->journal_type === self::TYPE_MANUAL;
    }

    public function totalDebit(): string
    {
        return (string) $this->lines->sum('debit');
    }
}
