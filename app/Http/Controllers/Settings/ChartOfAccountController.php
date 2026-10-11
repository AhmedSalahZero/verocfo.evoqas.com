<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccountController
//
//  HTTP layer for Chart of Accounts management.
//  Zero business logic — all delegated to ChartOfAccountService.
//
//  Routes (under auth + active.company middleware):
//    GET    /settings/coa                     → index()
//    GET    /settings/coa/create              → create()
//    POST   /settings/coa                     → store()
//    GET    /settings/coa/{account}/edit      → edit()
//    PUT    /settings/coa/{account}           → update()
//    PATCH  /settings/coa/{account}/toggle    → toggleActive()
//    GET    /settings/coa/postable            → postable() [JSON for dropdowns]
//
//  @layer    Controller
//  @depends  App\Services\ChartOfAccountService
//            App\Models\ChartOfAccount, Currency
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Services\ChartOfAccountService;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountController extends Controller
{
    public function __construct(
        private ChartOfAccountService $service,
        private CurrencyService       $currencyService,
    ) {}

    // ── Index — full COA tree ─────────────────────────────────────

    public function index(Request $request): Response
    {
        $tree = $this->service->treeGrouped($this->companyId($request));

        return Inertia::render('Settings/ChartOfAccounts/Index', [
            'tree'    => $tree,
            'filters' => $request->only('search', 'section', 'class'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $parentId  = $request->query('parent_id') ? (int) $request->query('parent_id') : null;
        $parents   = $this->parentOptions($companyId);

        // Find the pre-selected parent so Vue can display it immediately
        // without relying on ID type-matching in the frontend
        $selectedParent = $parentId
            ? $parents->firstWhere('id', $parentId)
            : null;

        return Inertia::render('Settings/ChartOfAccounts/Form', [
            'account'         => null,
            'selected_parent' => $selectedParent,
            'currencies'      => $this->currencyService->activeForDropdown($companyId),
            'parents'         => $parents,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(ChartOfAccountRequest $request)
    {
        $account = $this->service->create(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );

        return redirect()->route('settings.coa.index')
            ->with('success', "Account [{$account->code}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(Request $request, ChartOfAccount $coa): Response
    {
        abort_if($coa->is_system, 403, 'System accounts cannot be edited.');

        return Inertia::render('Settings/ChartOfAccounts/Form', [
            'account'         => $coa->load('currency', 'parent'),
            'selected_parent' => null,
            'currencies'      => $this->currencyService->activeForDropdown($this->companyId($request)),
            'parents'         => $this->parentOptions($this->companyId($request)),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(ChartOfAccountRequest $request, ChartOfAccount $coa)
    {
        $this->service->update($coa, $request->validated(), $request->user());

        return redirect()->route('settings.coa.index')
            ->with('success', "Account [{$coa->code}] updated successfully.");
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Request $request, ChartOfAccount $coa)
    {
        $this->service->toggleActive($coa, $request->user());

        return back()->with('success', 'Account status updated.');
    }

    // ── Postable accounts for transaction dropdowns (JSON) ────────

    public function postable(Request $request)
    {
        return response()->json(
            $this->service->postableForDropdown(
                $this->companyId($request),
                $request->query('class')
            )
        );
    }

    // ── Private helpers ───────────────────────────────────────────

    private function parentOptions(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        // Fix (July 2026): Sales Expenses (52), Marketing (53), and
        // G&A (54) have no Level 3 accounts under them at all — so
        // they need to appear here too, or a user could never pick
        // them as a parent even after the validation fix in
        // ChartOfAccountService::validateUserAccount(). Every other
        // Level 2 group still goes through its normal Level 3/4
        // accounts as before.
        // Fix (July 2026): 135001/135002/135003/135004 are excluded —
        // these are now managed exclusively per Product Category (see
        // ChartOfAccountService::validateUserAccount() for the full
        // reasoning). Excluded here too so a user never even sees
        // them as a pickable parent in the first place, not just
        // blocked after submitting.
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where(function ($q) {
                $q->whereIn('level', [3, 4])
                  ->orWhere(function ($q2) {
                      $q2->where('level', 2)->whereIn('code', ['52', '53', '54']);
                  });
            })
            ->whereNotIn('code', ['135001', '135002', '135003', '135004'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'level', 'section', 'class', 'normal_balance', 'allow_currency']);
    }
}