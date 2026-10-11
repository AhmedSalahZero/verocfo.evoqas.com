<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LandingCostController
//
//  HTTP layer for Landing Cost. Zero business logic — delegated to
//  LandingCostService. See VeroCFO_Landing_Cost_Design_v1_0.md for
//  the full feature design.
//
//  Routes (under auth + active.company):
//    GET    /accounting/landing-costs                       → index()
//    GET    /accounting/landing-costs/create                → create()
//    POST   /accounting/landing-costs                       → store()
//    GET    /accounting/landing-costs/{landingCost}/edit     → edit()
//    PUT    /accounting/landing-costs/{landingCost}          → update()
//    DELETE /accounting/landing-costs/{landingCost}          → destroy()
//    PATCH  /accounting/landing-costs/{landingCost}/post     → post()
//    PATCH  /accounting/landing-costs/{landingCost}/set-to-draft → setToDraft()
//    POST   /accounting/landing-costs/{landingCost}/pay      → pay()
//    GET    /accounting/landing-costs/bank-accounts          → bankAccounts() (JSON, for the Pay modal)
//
//  Attachment to a Goods Receipt Note is NOT handled here — see
//  GoodsReceiptNoteController::eligibleLandingCosts() and
//  GoodsReceiptNoteService::post()'s $landingCostAttachments param.
//
//  @layer    Controller
//  @depends  LandingCostService, LandingCost, LandingCostRequest, LandingCostPayRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LandingCostPayRequest;
use App\Http\Requests\Accounting\LandingCostRequest;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\LandingCost;
use App\Models\Partner;
use App\Services\Accounting\LandingCostService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LandingCostController extends Controller
{
    public function __construct(private LandingCostService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/LandingCosts/Index', [
            'landingCosts' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'      => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/LandingCosts/Form', [
            'landingCost'          => null,
            'landingCostSuppliers' => $this->landingCostSuppliers(),
            'clearingAccounts'     => $this->clearingAccounts($this->companyId($request)),
            'currencies'           => app(\App\Services\CurrencyService::class)->activeCurrenciesOrdered($this->companyId($request)),
        ]);
    }

    public function store(LandingCostRequest $request)
    {
        try {
            $landingCost = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.landing-costs.index')->with('success', "Landing Cost [{$landingCost->landing_cost_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, LandingCost $landingCost)
    {
        return Inertia::render('Accounting/LandingCosts/Form', [
            'landingCost'          => $landingCost->load(['partner:id,name_en', 'chartOfAccount:id,code,name_en']),
            'landingCostSuppliers' => $this->landingCostSuppliers(),
            'clearingAccounts'     => $this->clearingAccounts($this->companyId($request)),
            'currencies'           => app(\App\Services\CurrencyService::class)->activeCurrenciesOrdered($this->companyId($request)),
        ]);
    }

    public function update(LandingCostRequest $request, LandingCost $landingCost)
    {
        try {
            $this->service->update($landingCost, $request->validated(), $request->user());
            return redirect()->route('accounting.landing-costs.index')->with('success', 'Landing Cost updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, LandingCost $landingCost)
    {
        try {
            $this->service->delete($landingCost, $request->user());
            return redirect()->route('accounting.landing-costs.index')->with('success', 'Draft Landing Cost deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, LandingCost $landingCost)
    {
        try {
            $lc = $this->service->post($landingCost, $request->user());
            return back()->with('success', "Landing Cost [{$lc->landing_cost_code}] posted — liability recorded.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Reopens a posted-but-not-yet-paid-or-attached Landing Cost — see LandingCostService::setToDraft(). */
    public function setToDraft(Request $request, LandingCost $landingCost)
    {
        try {
            $this->service->setToDraft($landingCost, $request->user());
            return back()->with('success', "Landing Cost [{$landingCost->landing_cost_code}] reopened to draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pay(LandingCostPayRequest $request, LandingCost $landingCost)
    {
        try {
            $lc = $this->service->pay($landingCost, $request->integer('bank_account_id'), $request->input('paid_date'), $request->user());
            return back()->with('success', "Landing Cost [{$lc->landing_cost_code}] marked as paid.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function unpay(Request $request, LandingCost $landingCost)
    {
        try {
            $lc = $this->service->unpay($landingCost, $request->user());
            return back()->with('success', "Payment of Landing Cost [{$lc->landing_cost_code}] undone.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — Current bank accounts, for the Pay modal. Same picker shape as Other Payment's own bank account list. */
    public function bankAccounts(Request $request)
    {
        $accounts = BankAccount::current()->active()->with('bank:id,name_en')
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_number', 'bank_id', 'coa_account_id'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->bank?->name_en} — {$a->account_name} ({$a->account_number})"]);

        return response()->json(['bank_accounts' => $accounts]);
    }

    // ── Private — shared lookups ──────────────────────────────────

    private function landingCostSuppliers()
    {
        return Partner::ofType('supplier')->where('is_landing_cost_supplier', true)->active()
            ->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'landing_cost_account_id']);
    }

    /** Every child of 146 (Landing Cost Clearing) — the user's own named sub-accounts. */
    private function clearingAccounts(int $companyId)
    {
        $parent = \App\Models\ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('code', '146')->first();

        if (! $parent) {
            return collect();
        }

        return \App\Models\ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('parent_id', $parent->id)->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']);
    }
}
