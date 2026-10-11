<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccrualController
//
//  HTTP layer for Accruals. Zero business logic — delegated to
//  AccrualService. No edit/update routes — an accrual is posted
//  immediately on creation (see AccrualService header) and is only
//  ever cancelled (while unsettled) or left to be settled by
//  Supplier Bills.
//
//  eligibleForPartner() is a live JSON endpoint the Supplier Bill
//  Expense form calls when the user picks a supplier and an "accrual
//  settlement" line — returns only that supplier's open/partially
//  settled accruals.
//
//  Routes (under auth + active.company):
//    GET    /accounting/accruals                        → index()
//    GET    /accounting/accruals/create                 → create()
//    POST   /accounting/accruals                        → store()
//    PATCH  /accounting/accruals/{accrual}/cancel        → cancel()
//    GET    /accounting/accruals/eligible-for-partner    → eligibleForPartner() (JSON)
//
//  @layer    Controller
//  @depends  AccrualService, Accrual, AccrualRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccrualRequest;
use App\Models\Accrual;
use App\Models\ChartOfAccount;
use App\Models\OverheadPool;
use App\Models\Partner;
use App\Services\Accounting\AccrualService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccrualController extends Controller
{
    public function __construct(private AccrualService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Accruals/Index', [
            'accruals' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id')),
            'filters'  => $request->only('search', 'status', 'partner_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Accruals/Form', [
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'expenseAccounts'  => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
                ->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']),
            'costCenters'      => \App\Models\CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'projects'         => \App\Models\Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en']),
            // Section 7 — optional tag for a real manufacturing
            // overhead cost (rent, electricity, maintenance) that
            // should count toward a specific pool's Actual side at
            // month-end reconciliation.
            'overheadPools'    => OverheadPool::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(AccrualRequest $request)
    {
        $data = $request->validated();
        $accrual = $this->service->create($this->companyId($request), $data, $request->user());
        return redirect()->route('accounting.accruals.index')->with('success', "Accrual [{$accrual->accrual_code}] posted.");
    }

    public function cancel(Request $request, Accrual $accrual)
    {
        $this->service->cancel($accrual, $request->user());
        return back()->with('success', "Accrual [{$accrual->accrual_code}] cancelled.");
    }

    /** Live lookup — open accruals for one supplier, for the Supplier Bill expense-line picker. */
    public function eligibleForPartner(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id');

        $accruals = $this->service->openForPartner($companyId, $partnerId)->map(fn ($a) => [
            'id'                => $a->id,
            'accrual_code'      => $a->accrual_code,
            'description'       => $a->description,
            'accrual_date'      => $a->accrual_date->toDateString(),
            'amount'            => (string) $a->amount,
            'settled_amount'    => (string) $a->settled_amount,
            'remaining_amount'  => $a->remainingAmount(),
        ])->values();

        return response()->json(['accruals' => $accruals]);
    }
}
