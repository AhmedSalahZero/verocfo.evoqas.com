<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaimLine
//
//  One receipt on an Expense Claim — one taxi fare, one hotel night,
//  one box of stationery.
//
//  expense_account_id is picked by the person entering the claim, not
//  derived from anything. That is the precedent migration 000234 set
//  explicitly for employee reimbursements: there is no fixed account,
//  the user chooses each time. Accounts 522 / 532 / 543 (Employee
//  Reimbursements — Sales, Marketing, G&A) are the natural picks but
//  nothing forces them.
//
//  expense_date is the date on the receipt. It does NOT date the
//  journal entry — the accrual is dated by approval, when the
//  obligation arises. It is kept so an expense policy question about
//  a stale receipt can actually be asked.
//
//  amount is in the CLAIM's currency. The functional figure is
//  derived at posting time from the claim's exchange rate rather than
//  stored here, so the two can never drift apart.
//
//  @layer    Model
//  @depends  BelongsToCompany, SerializesDateOnly, ExpenseClaim,
//            ChartOfAccount, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseClaimLine extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'expense_claim_id', 'expense_account_id', 'cost_center_id',
        'expense_date', 'description', 'amount', 'sort_order',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function expenseClaim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
