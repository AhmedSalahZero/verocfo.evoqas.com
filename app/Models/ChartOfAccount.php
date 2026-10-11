<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccount
//
//  Self-referencing model representing the 5-level IFRS-aligned
//  chart of accounts hierarchy for Vero CFO.
//
//  Levels:
//    1 = Class       (system-locked, company_id = NULL)
//    2 = Group       (system-locked, company_id = NULL)
//    3 = Account     (system-locked, company_id = NULL)
//    4 = Sub-Account (user-defined, company_id = company)
//    5 = Detail      (user-defined, optional, company_id = company)
//
//  Posting rule: only is_postable = TRUE accounts accept journal
//  entry lines. Levels 1–3 are summary only.
//
//  System accounts (is_system = TRUE, company_id = NULL) are
//  seeded by ChartOfAccountSeeder and shared across all companies.
//  They cannot be renamed, moved, or deleted.
//
//  Currency-enabled accounts (allow_currency = TRUE) are Cash
//  and Bank account groups. Their sub-accounts must have a
//  currency_id assigned before they can be used in transactions.
//
//  Auto-code generation:
//    nextCode(int $parentId, int $companyId) → reads parent code +
//    next available 3-digit suffix, counted separately for each
//    company → e.g. parent 131 → each company's first sub-account
//    is 131001, regardless of how many other companies already have
//    a 131001 of their own.
//
//    Fix (July 2026): previously took only $parentId, with no company
//    passed in at all — so every company sharing the same hardcoded
//    parent (which is every company, since parents like 131 and 551
//    are system-wide) was drawing from ONE shared numbering list
//    instead of each starting its own count at 001. Company A's
//    first sub-account got 131001; Company B's first sub-account
//    then got 131002 instead of its own 131001. Fixed by counting
//    only each company's own accounts.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use BelongsToCompany;

    // Shared system rows (company_id = NULL) are visible to every
    // company — see CompanyScope (Audit M3). Do not add this constant
    // to ordinary tenant models.
    public const SHARES_SYSTEM_ROWS = true;

    // ── Class constants ───────────────────────────────────────────

    const CLASS_ASSETS      = 'assets';
    const CLASS_LIABILITIES = 'liabilities';
    const CLASS_EQUITY      = 'equity';
    const CLASS_REVENUE     = 'revenue';
    const CLASS_EXPENSES    = 'expenses';

    const SECTION_BALANCE_SHEET      = 'balance_sheet';
    const SECTION_INCOME_STATEMENT   = 'income_statement';

    const BALANCE_DEBIT  = 'debit';
    const BALANCE_CREDIT = 'credit';

    const CLASS_NORMAL_BALANCE = [
        self::CLASS_ASSETS      => self::BALANCE_DEBIT,
        self::CLASS_EXPENSES    => self::BALANCE_DEBIT,
        self::CLASS_LIABILITIES => self::BALANCE_CREDIT,
        self::CLASS_EQUITY      => self::BALANCE_CREDIT,
        self::CLASS_REVENUE     => self::BALANCE_CREDIT,
    ];

    const CLASS_SECTION = [
        self::CLASS_ASSETS      => self::SECTION_BALANCE_SHEET,
        self::CLASS_LIABILITIES => self::SECTION_BALANCE_SHEET,
        self::CLASS_EQUITY      => self::SECTION_BALANCE_SHEET,
        self::CLASS_REVENUE     => self::SECTION_INCOME_STATEMENT,
        self::CLASS_EXPENSES    => self::SECTION_INCOME_STATEMENT,
    ];

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name_en',
        'name_ar',
        'level',
        'section',
        'class',
        'account_type',
        'normal_balance',
        'is_system',
        'is_postable',
        'blocks_manual_posting', // Audit H3 — control account
        'allow_currency',
        'currency_id',
        'requires_cost_center',
        'is_active',
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'is_system'            => 'boolean',
        'is_postable'          => 'boolean',
        'blocks_manual_posting' => 'boolean',
        'allow_currency'       => 'boolean',
        'requires_cost_center' => 'boolean',
        'is_active'            => 'boolean',
        'level'                => 'integer',
    ];

    // ── Lookup by code ────────────────────────────────────────────

    /**
     * Resolves a system or company account by its exact code,
     * correctly reaching shared system accounts (company_id = NULL)
     * per the platform's documented "Known Recurring Bug" rule.
     *
     * Added alongside the Journal Entry engine (July 2026) — every
     * auto-posting service (GoodsReceiptNoteService and everything
     * after it) resolves fixed accounts like 233 GRNI through this,
     * instead of each service reinventing its own
     * where('company_id', ...)->where('code', ...) lookup. See
     * JournalEntryService header for why that pattern is unsafe.
     */
    public static function findByCode(int $companyId, string $code): ?self
    {
        return static::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', $code)
            ->first();
    }

    // ── Auto-code generation ──────────────────────────────────────

    /**
     * Returns the next available code for a child account, counted
     * separately for each company.
     *
     * Example: parent code = "131", this company's existing children:
     *   131001, 131002 → returns 131003
     * Another company with its OWN 131001 doesn't affect this at all
     * — each company counts from its own starting point.
     *
     * Example: parent code = "131001" (Level 4)
     *   existing children: 131001001
     *   returns: 131001002
     */
    public static function nextCode(int $parentId, int $companyId): string
    {
        $parent = static::withoutGlobalScopes()->findOrFail($parentId);

        // Known Recurring Bug rule: SYSTEM accounts carry company_id
        // = NULL, so they must be reached with forCompany(), never
        // with a plain where('company_id', …).
        //
        // This used to filter on company_id alone. Every seeded child
        // of the parent — 111001 Land, 111002 Buildings, and the rest
        // — was therefore invisible to it, so the first account a
        // company added under 111 was handed 111001 again, colliding
        // head-on with Land. The unique index could not catch it
        // either: it is unique(company_id, code), and in SQL a NULL
        // company_id never equals 107, so the two rows are "different"
        // as far as the database is concerned. Both halves are fixed —
        // this one hands out a free number, and assertCodeIsFree()
        // in ChartOfAccountService refuses a taken one whatever the
        // route in.
        $last = static::withoutGlobalScopes()
            ->where('parent_id', $parentId)
            ->forCompany($companyId)
            ->orderByRaw('CAST(RIGHT(code, 3) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) {
            return $parent->code . '001';
        }

        $suffix = (int) substr($last, -3);
        return $parent->code . str_pad($suffix + 1, 3, '0', STR_PAD_LEFT);
    }

    // ── Scopes ────────────────────────────────────────────────────

    /**
     * Returns system accounts (company_id = NULL) AND this company's
     * custom accounts. Used for all COA queries.
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')
              ->orWhere('company_id', $companyId);
        });
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfClass(Builder $query, string $class): Builder
    {
        return $query->where('class', $class);
    }

    public function scopeOfSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }

    public function scopeAtLevel(Builder $query, int $level): Builder
    {
        return $query->where('level', $level);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id')
                    ->orderBy('code');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isBalanceSheet(): bool
    {
        return $this->section === self::SECTION_BALANCE_SHEET;
    }

    public function isIncomeStatement(): bool
    {
        return $this->section === self::SECTION_INCOME_STATEMENT;
    }

    public function isDebit(): bool
    {
        return $this->normal_balance === self::BALANCE_DEBIT;
    }

    public function isCredit(): bool
    {
        return $this->normal_balance === self::BALANCE_CREDIT;
    }

    /**
     * Returns indented display name for dropdowns.
     * e.g. "    ↳ 131001 — Petty Cash EGP"
     */
    public function getIndentedNameAttribute(): string
    {
        $indent = str_repeat('    ', $this->level - 1);
        return "{$indent}{$this->code} — {$this->name_en}";
    }
}
