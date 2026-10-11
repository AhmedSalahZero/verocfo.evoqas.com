<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlSettlement
//
//  One real payment (full or partial) against an MtlScheduleLine.
//  See migration 000079 header — a single installment can have many
//  of these, unlike Leasing's all-or-nothing cheques.
//
//  @layer    Model
//  @depends  BelongsToCompany, MtlScheduleLine, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class MtlSettlement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'mtl_settlements';

    protected $fillable = [
        'company_id', 'mtl_schedule_line_id', 'settlement_date',
        'settlement_amount', 'current_account_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'settlement_date'    => 'date',
        'settlement_amount'  => 'decimal:2',
    ];

    public function scheduleLine(): BelongsTo   { return $this->belongsTo(MtlScheduleLine::class, 'mtl_schedule_line_id'); }
    public function currentAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'current_account_id'); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
}
