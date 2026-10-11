<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PaymentTerm
//
//  Represents a named collection/payment term master record.
//  Used on both Customer side (collection_term_id) and
//  Supplier side (payment_term_id) on the partners table.
//
//  System terms (is_system = true) are seeded by PaymentTermSeeder
//  and are available to ALL companies (company_id = NULL).
//  Company-defined custom terms have company_id set.
//
//  The actual installment schedule is in PaymentTermLine children.
//  All lines on one term must sum to 100% — enforced in service.
//
//  Scopes:
//    forCompany(int $companyId) → system terms + company's own terms
//    active()                   → is_active = true only
//    system()                   → is_system = true only
//
//  AUDIT M1 FIX (Sept 2026): now uses BelongsToCompany, so a
//  company only ever sees system terms + its OWN custom terms — in
//  lists, dropdowns and when a term is opened from the web address
//  (/settings/payment-terms/{id}/edit). Before this, Company A could
//  open, edit or deactivate Company B's custom terms.
//
//  @layer    Model
//  @depends  PaymentTermLine, BelongsToCompany
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class PaymentTerm extends Model
{
    use BelongsToCompany;

    // Shared system rows (company_id = NULL) are visible to every
    // company — see CompanyScope (Audit M3). Do not add this constant
    // to ordinary tenant models.
    public const SHARES_SYSTEM_ROWS = true;

    protected $fillable = [
        'company_id',
        'code',
        'name_en',
        'name_ar',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentTermLine::class)
                    ->orderBy('sort_order');
    }

    // ── Scopes ────────────────────────────────────────────────────

    /**
     * Returns system-level terms AND this company's custom terms.
     * This is the correct scope to use in all dropdowns.
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')
              ->orWhere('company_id', $companyId);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Returns the display name based on locale preference.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name_ar ?? $this->name_en;
    }

    /**
     * Validates that all lines sum to exactly 100%.
     * Used by PaymentTermService before saving.
     */
    public function linesSum(): string
    {
        return $this->lines->reduce(
            fn ($carry, $line) => bcadd($carry, (string) $line->percentage, 2),
            '0.00'
        );
    }
}
