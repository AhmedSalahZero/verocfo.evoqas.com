<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  AuditLog
//
//  Append-only audit trail for every financial write in Vero CFO.
//  Records user, action, before/after state, and IP address.
//
//  Usage (from any service):
//    AuditLog::record($actor, 'bank.created', $bank, null, $bank->toArray());
//    AuditLog::record($actor, 'facility.updated', $facility, $before, $after);
//
//  The static record() helper resolves company_id and impersonation
//  automatically — services never need to pass these manually.
//
//  @layer    Model
//  @depends  BelongsToCompany, User
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToCompany;

class AuditLog extends Model
{
    use BelongsToCompany;

    protected $table = 'audit_log';

    // Audit log is append-only — never updated or deleted
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'impersonated_by',
        'action',
        'auditable_type',
        'auditable_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    // Audit L-16: several older services write rows with AuditLog::create()
    // directly instead of AuditLog::record(). Those rows had no IP address,
    // no browser details and no impersonation marker. Every row now gets
    // them filled in here, whichever way it is written — values a caller
    // set itself are never overwritten.
    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if ($log->ip_address === null) {
                $log->ip_address = request()->ip();
            }
            if ($log->user_agent === null) {
                $log->user_agent = mb_substr((string) request()->userAgent(), 0, 255) ?: null;
            }
            if ($log->impersonated_by === null && $log->user_id !== null) {
                $user = auth()->user();
                if ($user && $user->id === $log->user_id && $user->isSuperAdmin() && session()->has('impersonate_company_id')) {
                    $log->impersonated_by = $user->id;
                }
            }
            if ($log->created_at === null) {
                $log->created_at = now();
            }
        });
    }

    // ── Static helper — called by every service ───────────────────

    /**
     * Record an audit entry.
     *
     * Usage:
     *   AuditLog::record($actor, 'bank.created', $bank, null, $bank->toArray());
     *   AuditLog::record($actor, 'facility.updated', $facility, $before, $after);
     *
     * @param  \App\Models\User   $actor   The user performing the action
     * @param  string             $action  Dot-notation action name
     * @param  \Illuminate\Database\Eloquent\Model $subject  The record being acted on
     * @param  array|null         $before  State before the action (null for creates)
     * @param  array|null         $after   State after the action (null for deletes)
     */
    public static function record(
        User $actor,
        string $action,
        Model $subject,
        ?array $before,
        ?array $after
    ): void {
        // Resolve company_id — Super Admin impersonation uses session
        $companyId = $actor->isSuperAdmin()
            ? session('impersonate_company_id')
            : $actor->company_id;

        // Resolve impersonating admin (if any)
        $impersonatedBy = $actor->isSuperAdmin() && session()->has('impersonate_company_id')
            ? $actor->id
            : null;

        static::create([
            'company_id'      => $companyId,
            'user_id'         => $actor->id,
            'impersonated_by' => $impersonatedBy,
            'action'          => $action,
            'auditable_type'  => get_class($subject),
            'auditable_id'    => $subject->getKey(),
            'before'          => $before,
            'after'           => $after,
            'ip_address'      => request()->ip(),
            'user_agent'      => request()->userAgent(),
            'created_at'      => now(),
        ]);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function impersonatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_by');
    }
}