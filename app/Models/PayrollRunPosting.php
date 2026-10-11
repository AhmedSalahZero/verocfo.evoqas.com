<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunPosting
//
//  One row per expense category actually posted for a PayrollRun —
//  see migration 000228 header. A company with no Marketing
//  employees this month simply has no 'marketing' row here, not a
//  zero-amount entry. 'manufacturing' clears 237 Direct Labor
//  Clearing (plugging 514 Manufacturing Labor Variance) rather than
//  debiting a salary expense account of its own — see
//  EmployeePayrollProfile header.
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRun, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunPosting extends Model
{
    use BelongsToCompany;

    const CATEGORY_MANUFACTURING   = 'manufacturing';
    const CATEGORY_SALES           = 'sales';
    const CATEGORY_MARKETING       = 'marketing';
    const CATEGORY_GENERAL_ADMIN   = 'general_admin';
    const CATEGORY_COST_OF_SERVICE = 'cost_of_service';

    protected $fillable = [
        'company_id', 'payroll_run_id', 'category', 'journal_entry_id',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
