<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PartnerEmployeeHistory
//
//  Append-only log of department, job_title, and cost_center_id
//  changes on Employee-type partners.
//
//  A new row is written by PartnerService whenever any of these
//  three fields changes on an Employee partner. Records are never
//  updated or deleted — this is an immutable audit trail.
//
//  effective_date is user-entered (defaults to today) and can be
//  backdated for historical imports.
//
//  AUDIT M1 FIX (Sept 2026): now uses BelongsToCompany — history
//  rows are always filtered to the current company.
//
//  @layer    Model
//  @depends  Partner, User, BelongsToCompany
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerEmployeeHistory extends Model
{
    use \App\Models\Traits\SerializesDateOnly;
    use BelongsToCompany;

    // Table name explicitly set — the migration created it as
    // "partner_employee_history" (singular), which does not match
    // Laravel's default plural guess ("partner_employee_histories").
    protected $table = 'partner_employee_history';

    // Append-only — no updated_at needed
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'partner_id',
        'field',
        'old_value',
        'new_value',
        'effective_date',
        'changed_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    // ── Tracked Fields ────────────────────────────────────────────

    const TRACKED_FIELDS = ['department', 'job_title', 'cost_center_id'];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
