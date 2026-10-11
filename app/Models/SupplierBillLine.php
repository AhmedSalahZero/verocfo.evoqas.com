<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierBillLine
//
//  See migration 000107 for the full design rationale — a line is
//  exactly one of three shapes (goods / fresh expense / accrual
//  settlement), enforced by SupplierBillService, not the schema.
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierBill, GoodsReceiptNoteLine,
//            ChartOfAccount, Accrual, Product, UnitOfMeasure,
//            CostCenter, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'supplier_bill_id', 'goods_receipt_note_line_id',
        'chart_of_account_id', 'accrual_id', 'product_id', 'description',
        'quantity', 'uom_id', 'unit_price', 'line_subtotal',
        'vat_rate', 'vat_amount', 'is_deductible', 'withholding_tax_rate', 'withholding_tax_amount', 'line_total',
        'variance_amount', 'variance_inventory_pct',
        'cost_center_id', 'project_id', 'sales_order_id', 'sort_order',
        'amortization_months', // Prepaid Expense Bill lines only — see migration 000192
    ];

    protected $casts = [
        'quantity'                => 'decimal:4',
        'unit_price'               => 'decimal:2',
        'line_subtotal'            => 'decimal:2',
        'vat_rate'                 => 'decimal:2',
        'vat_amount'               => 'decimal:2',
        'is_deductible'            => 'boolean',
        'withholding_tax_rate'     => 'decimal:2',
        'withholding_tax_amount'   => 'decimal:2',
        'line_total'               => 'decimal:2',
        'variance_amount'          => 'decimal:2',
        'variance_inventory_pct'   => 'decimal:2',
        'amortization_months'      => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function goodsReceiptNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function accrual(): BelongsTo
    {
        return $this->belongsTo(Accrual::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** Every amortization posting/reversal click recorded against this line — see PrepaidAmortizationService. */
    public function amortizationEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrepaidAmortizationEntry::class);
    }

    /** Every Supplier Debit Note line that has reversed part of this bill line — see migration 000198. */
    public function debitNoteLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierDebitNoteLine::class);
    }

    /** Every Supplier Credit Note line that has added to this bill line — see migration 000200. */
    public function creditNoteLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierCreditNoteLine::class);
    }

    // ── Shape helpers — see migration 000107 header ────────────────

    public function isGoodsLine(): bool
    {
        return ! is_null($this->goods_receipt_note_line_id);
    }

    public function isAccrualSettlement(): bool
    {
        return ! is_null($this->accrual_id);
    }

    public function isFreshExpense(): bool
    {
        return ! $this->isGoodsLine() && ! $this->isAccrualSettlement();
    }

    public function hasVariance(): bool
    {
        return bccomp((string) $this->variance_amount, '0', 2) !== 0;
    }
}
