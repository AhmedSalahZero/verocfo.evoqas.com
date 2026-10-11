<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePaymentAllocation
//
//  One row = a slice of a Cash Expense Payment tagged to a customer
//  Project/Contract, for PROJECT REPORTING ONLY — see migration
//  000179 for full design rationale. Never touches the journal entry.
//
//  cash_expense_payment_line_id (migration 000183, added when Cash
//  Expense Payment became multi-line) — WHICH of the payment's lines
//  this allocation was drawn from. Nullable: a single-line payment
//  doesn't force an explicit pick, and pre-multi-line rows stay valid.
//
//  @layer    Model
//  @depends  BelongsToCompany, CashExpensePayment, CashExpensePaymentLine, Project
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashExpensePaymentAllocation extends Model
{
    use BelongsToCompany;

    // Eloquent's default guess from the class name would be
    // 'cash_expense_payment_allocations' — the real table (migration
    // 000179) is 'cash_expense_payment_project_allocations'. Without
    // this, every query against this model fails with "table doesn't
    // exist" (confirmed the hard way).
    protected $table = 'cash_expense_payment_project_allocations';

    protected $fillable = [
        'company_id', 'cash_expense_payment_id', 'cash_expense_payment_line_id', 'project_id',
        'allocated_amount', 'allocated_amount_functional',
    ];

    protected $casts = [
        'allocated_amount'            => 'decimal:2',
        'allocated_amount_functional' => 'decimal:2',
    ];

    public function cashExpensePayment(): BelongsTo
    {
        return $this->belongsTo(CashExpensePayment::class);
    }

    public function cashExpensePaymentLine(): BelongsTo
    {
        return $this->belongsTo(CashExpensePaymentLine::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
