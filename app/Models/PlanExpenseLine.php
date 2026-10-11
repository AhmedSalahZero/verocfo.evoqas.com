<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanExpenseLine
//
//  One operating expense assumption inside a plan. category maps
//  directly onto real COA Level 2 groups (52/53/54/55). See
//  migration 000247 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, Plan
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanExpenseLine extends Model
{
    use BelongsToCompany;

    public const CATEGORY_SALES          = 'sales';
    public const CATEGORY_MARKETING      = 'marketing';
    public const CATEGORY_GENERAL_ADMIN  = 'general_admin';
    public const CATEGORY_FINANCE        = 'finance';

    public const TYPE_PCT_REVENUE     = 'pct_revenue';
    public const TYPE_FIXED_RECURRING = 'fixed_recurring';
    public const TYPE_ONE_TIME        = 'one_time';

    // category → real Chart of Accounts Level 2 group, confirmed
    // mapping (spec Section 3.2). Informational here; the actual
    // account lookup happens by code via ChartOfAccount::findByCode()
    // wherever a plan line needs to reference the real COA, same
    // fresh-lookup pattern as Building Bible Rule 35.4 — never a
    // stored/cached account ID.
    public const CATEGORY_COA_GROUP = [
        self::CATEGORY_SALES         => '52',
        self::CATEGORY_MARKETING     => '53',
        self::CATEGORY_GENERAL_ADMIN => '54',
        self::CATEGORY_FINANCE       => '55',
    ];

    protected $fillable = [
        'company_id',
        'plan_id',
        'expense_name',
        'category',
        'expense_type',
        'amount',
        'amortization_months',
        'annual_increase_pct',
        'start_month',
        'end_month',
    ];

    protected $casts = [
        'amount'               => 'decimal:2',
        'amortization_months'  => 'integer',
        'annual_increase_pct'  => 'decimal:4',
        'start_month'          => 'integer',
        'end_month'            => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
