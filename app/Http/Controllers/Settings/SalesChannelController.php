<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  SalesChannelController
//
//  Provides two endpoints:
//
//  GET  /settings/sales-channels        → index()
//    Returns all channels for this company (system + custom)
//    as JSON. Used to populate the selector on the Partner form
//    and Sales Order form.
//
//  POST /settings/sales-channels        → store()
//    Quick-add: user creates a new custom channel from the
//    inline modal on the Partner form. Returns the new channel
//    as JSON so the Vue component can add it to the list and
//    auto-select it immediately.
//
//  No full CRUD needed here — channel management is intentionally
//  lightweight. Deletion is not exposed (channels used in
//  transactions must be preserved for analysis integrity).
//
//  v1.1 (July 2026 audit) — index()/store() now delegate to
//  SalesChannelService; store() uses SalesChannelQuickCreateRequest
//  instead of inline validation. Both were over the controller
//  line-length limit and touching the database directly.
//
//  @layer    Controller
//  @depends  SalesChannelService, SalesChannelQuickCreateRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SalesChannelQuickCreateRequest;
use App\Services\SalesChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesChannelController extends Controller
{
    public function __construct(private SalesChannelService $service) {}

    // ── Index — for dropdowns ─────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->forDropdown($this->companyId($request)));
    }

    // ── Store — quick-add from inline modal ───────────────────────

    public function store(SalesChannelQuickCreateRequest $request): JsonResponse
    {
        try {
            $channel = $this->service->quickCreate($this->companyId($request), $request->validated());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($channel, 201);
    }
}
