<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesDashboardNote
//
//  One analyst comment attached to a date range on the Analytics >
//  Sales > Dashboard page. See migration for full context.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDashboardNote extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'date_from',
        'date_to',
        'note',
    ];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to'   => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
