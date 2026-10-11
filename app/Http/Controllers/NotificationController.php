<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  NotificationController
//
//  The three JSON endpoints behind the bell icon in the top bar
//  (Audit M43). They used to be inline closures in routes/web.php that
//  always answered "nothing". All logic lives in NotificationService.
//
//  Routes (under auth + active.company):
//    GET  /notifications           → index()
//    GET  /notifications/unread    → unreadCount()
//    POST /notifications/read-all  → markAllRead()
//
//  @layer    Controller
//  @depends  NotificationService
// ══════════════════════════════════════════════════════════════════

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        return response()->json([
            'notifications' => $this->service->recent($companyId, $request->user()->id),
            'unread_count'  => $this->service->unreadCount($companyId, $request->user()->id),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->service->unreadCount($this->companyId($request), $request->user()->id),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->service->markAllRead($this->companyId($request), $request->user()->id);

        return response()->json(['success' => true]);
    }
}
