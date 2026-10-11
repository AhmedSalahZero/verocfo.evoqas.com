<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToCompany;

class Notification extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'severity',
        'title',
        'message',
        'action_url',
        'action_label',
        'source',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    // ── Type Constants ────────────────────────────────────────────────────────

    const TYPE_INFO    = 'info';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR   = 'error';
    const TYPE_SUCCESS = 'success';

    // ── Severity Constants ────────────────────────────────────────────────────

    const SEVERITY_LOW    = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH   = 'high';

    // ── Relationships ─────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function markAsRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}
