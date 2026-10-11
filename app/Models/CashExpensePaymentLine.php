<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePaymentLine
//
//  One line of a Cash Expense Payment (August 2026 — see migration
//  000181) — fully flexible: its own Expense Account, own Cost
//  Center, own subtotal/VAT/WHT. Structural sibling of SupplierBillLine.
//
//  is_deductible (default TRUE): when FALSE, this line's vat_amount
//  folds into this SAME line's own expense_account_id instead of VAT
//  Input (137) — see CashExpensePaymentService::addLineVat(). WHT is
//  unaffected either way — always computed on subtotal, before VAT.
//
//  @layer    Model
//  @depends  BelongsToCompany, CashExpensePayment, ChartOfAccount, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashExpensePaymentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cash_expense_payment_id', 'description',
        'expense_account_id', 'cost_center_id',
        'subtotal', 'vat_rate', 'is_deductible', 'vat_amount',
        'withholding_tax_rate', 'withholding_tax_amount', 'net_amount',
        'subtotal_functional', 'vat_amount_functional',
        'withholding_tax_amount_functional', 'net_amount_functional',
        'sort_order',
    ];

    protected $casts = [
        'subtotal'                          => 'decimal:2',
        'vat_rate'                           => 'decimal:2',
        'is_deductible'                      => 'boolean',
        'vat_amount'                         => 'decimal:2',
        'withholding_tax_rate'               => 'decimal:2',
        'withholding_tax_amount'             => 'decimal:2',
        'net_amount'                         => 'decimal:2',
        'subtotal_functional'                => 'decimal:2',
        'vat_amount_functional'              => 'decimal:2',
        'withholding_tax_amount_functional'  => 'decimal:2',
        'net_amount_functional'              => 'decimal:2',
    ];

    public function cashExpensePayment(): BelongsTo
    {
        return $this->belongsTo(CashExpensePayment::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** Allocations against Customer Contracts drawn from this specific line — see migration 000183. */
    public function allocations(): HasMany
    {
        return $this->hasMany(CashExpensePaymentAllocation::class, 'cash_expense_payment_line_id');
    }
}
