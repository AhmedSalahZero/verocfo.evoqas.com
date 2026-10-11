<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  NotificationService
//
//  Reads and updates the bell-icon notifications for one user inside
//  the company they are currently working in (Audit M43).
//
//  Before this, the three notification routes were hard-coded to
//  return "no notifications" — while the system was already writing
//  real ones (for example "Closed month reopened" in FiscalYearService
//  and the lease-cheque alerts). Those were saved and never shown.
//
//  Rules:
//    - A user only ever sees their OWN notifications (user_id).
//    - Only those of the company they are working in; the
//      BelongsToCompany scope already applies this, and the company id
//      is also passed explicitly so the guarantee does not depend on
//      request state.
//    - Marking as read touches only that user's unread rows.
//
//  Notifications are system messages, not financial data, so changes
//  here are deliberately not audit-logged.
//
//  @layer    Service
//  @depends  Notification
// ══════════════════════════════════════════════════════════════════

use App\Models\Notification;
use Illuminate\Support\Collection;

class NotificationService
{
    /** How many recent notifications the bell panel shows. */
    private const PANEL_LIMIT = 20;

    /** @return Collection<int, array<string, mixed>> */
    public function recent(int $companyId, int $userId): Collection
    {
        return $this->mine($companyId, $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PANEL_LIMIT)
            ->get()
            ->map(fn (Notification $n) => [
                'id'           => $n->id,
                'type'         => $n->type,
                'severity'     => $n->severity,
                'title'        => $n->title,
                'message'      => $n->message,
                'action_url'   => $n->action_url,
                'action_label' => $n->action_label,
                'is_read'      => (bool) $n->is_read,
                'created_at'   => optional($n->created_at)->diffForHumans(),
            ]);
    }

    public function unreadCount(int $companyId, int $userId): int
    {
        return $this->mine($companyId, $userId)->where('is_read', false)->count();
    }

    /** @return int how many notifications were marked read */
    public function markAllRead(int $companyId, int $userId): int
    {
        return $this->mine($companyId, $userId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    private function mine(int $companyId, int $userId)
    {
        return Notification::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId);
    }
}
