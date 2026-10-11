<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

// ══════════════════════════════════════════════════════════════════
//  ObSession — Opening Balance Session
//  One record per company. Once is_posted = true, all OB data locked.
//  @layer Model
// ══════════════════════════════════════════════════════════════════

class ObSession extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'ob_sessions';

    protected $fillable = [
        'company_id', 'as_of_date', 'is_posted', 'posted_at', 'posted_by',
        'total_debits', 'total_credits', 'trial_balance_ok',
    ];

    protected $casts = [
        'as_of_date'        => 'date',
        'posted_at'         => 'datetime',
        'is_posted'         => 'boolean',
        'trial_balance_ok'  => 'boolean',
        'total_debits'      => 'decimal:4',
        'total_credits'     => 'decimal:4',
    ];

    public function isPosted(): bool { return $this->is_posted; }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
