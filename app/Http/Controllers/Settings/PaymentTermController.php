<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  PaymentTermController
//
//  HTTP layer for Payment Terms settings page.
//  Zero business logic — all delegated to PaymentTermService.
//
//  Routes (under auth + active.company middleware):
//    GET    /settings/payment-terms                → index()
//    GET    /settings/payment-terms/create         → create()
//    POST   /settings/payment-terms                → store()
//    GET    /settings/payment-terms/{term}/edit    → edit()
//    PUT    /settings/payment-terms/{term}         → update()
//    PATCH  /settings/payment-terms/{term}/toggle  → toggleActive()
//
//  company_id always resolved via $this->companyId($request).
//  All methods under 15 lines per Structure Bible Rule 3.
//
//  @layer    Controller
//  @depends  App\Services\PaymentTermService
//            App\Http\Requests\Settings\PaymentTermRequest
//            App\Models\PaymentTerm
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PaymentTermRequest;
use App\Models\PaymentTerm;
use App\Services\PaymentTermService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentTermController extends Controller
{
    public function __construct(private PaymentTermService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $terms = $this->service->paginate(
            $this->companyId($request),
            $request->only('search')
        );

        return Inertia::render('Settings/PaymentTerms/Index', [
            'terms'   => $terms,
            'filters' => $request->only('search'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(): Response
    {
        return Inertia::render('Settings/PaymentTerms/Form', [
            'term' => null,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(PaymentTermRequest $request)
    {
        $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );

        return redirect()->route('settings.payment-terms.index')
            ->with('success', __('Payment term created successfully.'));
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(PaymentTerm $paymentTerm): Response
    {
        abort_if($paymentTerm->is_system, 403, 'System terms cannot be edited.');

        return Inertia::render('Settings/PaymentTerms/Form', [
            'term' => $paymentTerm->load('lines'),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(PaymentTermRequest $request, PaymentTerm $paymentTerm)
    {
        $this->service->update($paymentTerm, $request->validated(), $request->user());

        return redirect()->route('settings.payment-terms.index')
            ->with('success', __('Payment term updated successfully.'));
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Request $request, PaymentTerm $paymentTerm)
    {
        $this->service->toggleActive($paymentTerm, $request->user());

        return back()->with('success', __('Payment term status updated.'));
    }
}
