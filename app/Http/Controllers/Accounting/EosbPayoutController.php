<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EosbPayoutController   (audit M-36)
//
//  HTTP layer for paying out / reversing an end-of-service benefit.
//  All logic is in EosbPayoutService.
//
//  @layer    Controller
//  @depends  EosbPayoutService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\EosbPayout;
use App\Services\Accounting\EosbPayoutService;
use Illuminate\Http\Request;

class EosbPayoutController extends Controller
{
    public function __construct(private EosbPayoutService $service) {}

    public function store(Request $request)
    {
        $d = $request->validate([
            'partner_id' => ['required', 'integer'], 'payout_date' => ['required', 'date'],
            'payable_amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->service->pay($this->companyId($request), (int) $d['partner_id'], $d['payout_date'], (string) $d['payable_amount'], $d['notes'] ?? null, $request->user());

        return back()->with('success', 'End-of-service payout recorded.');
    }

    public function reverse(Request $request, EosbPayout $payout)
    {
        $this->service->reverse($payout, $request->user());

        return back()->with('success', 'End-of-service payout reversed.');
    }
}
