<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  User Model
//
//  Three user types in Vero CFO:
//    super_admin   → platform owner, no company_id
//    company_admin → full access within their company
//    user          → access controlled by UserModulePermission
//
//  Permission resolution:
//    canOnModule()  → checks if user has any access to a module
//    canOnFeature() → checks a specific feature flag
//
//  Both methods return true immediately for super_admin and
//  company_admin — they bypass the permission table entirely.
//
//  job_level is organizational seniority — does NOT affect access.
//
//  @layer    Model
//  @table    users
//  @depends  App\Models\Company, App\Models\UserModulePermission
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name', 'name_ar', 'email', 'password',
        'company_id', 'role',
        'job_title_en', 'job_title_ar', 'job_level',
        'lang_pref', 'timezone',
        'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'password'          => 'hashed',
    ];

    // ── Role Constants ────────────────────────────────────────────

    const ROLE_SUPER_ADMIN   = 'super_admin';
    const ROLE_COMPANY_ADMIN = 'company_admin';
    const ROLE_USER          = 'user';

    // ── Job Level Constants ───────────────────────────────────────

    const LEVEL_SHAREHOLDER = 'shareholder';
    const LEVEL_DIRECTOR    = 'director';
    const LEVEL_MANAGER     = 'manager';
    const LEVEL_SENIOR      = 'senior';
    const LEVEL_JUNIOR      = 'junior';
    const LEVEL_STAFF       = 'staff';

    // ── Role Helpers ──────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === self::ROLE_COMPANY_ADMIN;
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    // ── Module-level permission check ─────────────────────────────
    // Returns true if user has the given action on ANY feature of the
    // module. Used by ModulePermission middleware for module-level gate.
    //
    // Audit C5 (Sept 2026) — "view" is IMPLIED by create / edit /
    // reverse: anyone allowed to create, edit or reverse a document
    // must be able to open its screen. Without this, now that every
    // screen checks "view", a user granted only "create" would be
    // locked out of the very form they are allowed to use.
    //
    // Uses the already-loaded modulePermissions relation when present
    // (HandleInertiaRequests loads it once per request) — avoids one
    // database query per check.

    public function canOnModule(string $action, string $module): bool
    {
        if ($this->isSuperAdmin() || $this->isCompanyAdmin()) {
            return true;
        }

        return $this->permissionRows()
            ->where('module', $module)
            ->contains(fn ($row) => $this->rowAllows($row, $action));
    }

    // ── Feature-level permission check ───────────────────────────
    // Single source of truth for feature-level checks.
    // Used by feature middleware and HandleInertiaRequests.
    //
    // Actions: view | create | edit | post | reverse | approve
    // "view" is implied by any of the others (see above).
    // post / approve added by Audit H3 (Sept 2026), migration 000297.

    public function canOnFeature(string $action, string $module, string $feature): bool
    {
        if ($this->isSuperAdmin() || $this->isCompanyAdmin()) {
            return true;
        }

        $permission = $this->permissionRows()
            ->where('module', $module)
            ->where('feature', $feature)
            ->first();

        return $permission ? $this->rowAllows($permission, $action) : false;
    }

    private function rowAllows($row, string $action): bool
    {
        return match ($action) {
            'view'    => (bool) ($row->can_view || $row->can_create || $row->can_edit || $row->can_reverse || $row->can_post || $row->can_approve),
            'create'  => (bool) $row->can_create,
            'edit'    => (bool) $row->can_edit,
            'post'    => (bool) $row->can_post,
            'reverse' => (bool) $row->can_reverse,
            'approve' => (bool) $row->can_approve,
            default   => false,
        };
    }

    private function permissionRows()
    {
        // A grant belongs to a company as well as a user, so only the
        // grants made in THIS user's own company ever count. The
        // company scope on UserModulePermission follows whichever
        // company is "current" at that moment, which is not always the
        // user's own (e.g. a support session, a queued job), so the
        // company is checked explicitly here rather than trusted to it.
        if ($this->relationLoaded('modulePermissions')) {
            return $this->modulePermissions
                ->filter(fn ($row) => (int) $row->company_id === (int) $this->company_id)
                ->values();
        }

        return UserModulePermission::withoutGlobalScopes()
            ->where('user_id', $this->id)
            ->where('company_id', $this->company_id)
            ->get();
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * المحادثات اللي المستخدم طرف فيها — دردشة أو تذكرة دعم .
     *
     * * شوف App\Models\Conversation : النوعين في جدول واحد لأن
     * * الاتنين "خيط رسايل بين ناس" .
     *
     * * last_read_at على الـ pivot هي علامة القراءة اللي بيتبني عليها
     * * عدّاد غير المقروء .
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function modulePermissions(): HasMany
    {
        return $this->hasMany(UserModulePermission::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function unreadNotifications(): HasMany
    {
        return $this->hasMany(Notification::class)->where('is_read', false);
    }
}
