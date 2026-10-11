<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeePayrollProfileController
//
//  HTTP layer for employee salary setup — the "Employees" tab of
//  Payroll. Powers the list of employees with their salary/category/
//  EOSB setup, and the create/edit form.
//
//  @layer    Controller
//  @depends  EmployeePayrollProfileService, EmployeePayrollProfileRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\EmployeeClassificationRevisionRequest;
use App\Http\Requests\Accounting\EmployeeManufacturingRoleRevisionRequest;
use App\Http\Requests\Accounting\EmployeePayrollProfileRequest;
use App\Http\Requests\Accounting\EmployeeSalaryRevisionRequest;
use App\Models\CompanyActiveCurrency;
use App\Models\EmployeePayrollProfile;
use App\Models\OverheadPool;
use App\Models\Partner;
use App\Services\Accounting\EmployeePayrollProfileService;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeePayrollProfileController extends Controller
{
    public function __construct(
        private EmployeePayrollProfileService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Payroll/EmployeeProfileIndex', [
            'profiles' => $this->service->paginate($this->companyId($request), $request->only('expense_category')),
            'filters'  => $request->only('expense_category'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Payroll/EmployeeProfileForm', [
            'employees'  => Partner::where('company_id', $companyId)->ofType(Partner::TYPE_EMPLOYEE)
                ->whereDoesntHave('payrollProfile')
                ->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId'  => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'overheadPools'         => OverheadPool::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name', 'allocation_base']),
        ]);
    }

    public function store(EmployeePayrollProfileRequest $request)
    {
        $profile = $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('hr.employees.index')->with('success', "Payroll profile created for {$profile->partner->name_en}.");
    }

    public function edit(Request $request, EmployeePayrollProfile $profile)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Payroll/EmployeeProfileForm', [
            'profile'               => $profile->load(
                'partner', 'currency', 'overheadPool',
                'salaryRevisions.createdBy:id,name',
                'classificationRevisions.createdBy:id,name',
                'manufacturingRoleRevisions.createdBy:id,name',
                'manufacturingRoleRevisions.overheadPool:id,name',
            ),
            'currencies'            => $this->currencyService->activeCurrenciesOrdered($companyId),
            'functionalCurrencyId'  => CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id'),
            'overheadPools'         => OverheadPool::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name', 'allocation_base']),
        ]);
    }

    public function update(EmployeePayrollProfileRequest $request, EmployeePayrollProfile $profile)
    {
        $this->service->update($profile, $request->validated(), $request->user());

        return redirect()->route('hr.employees.index')->with('success', 'Payroll profile updated.');
    }

    public function addSalaryRevision(EmployeeSalaryRevisionRequest $request, EmployeePayrollProfile $profile)
    {
        $this->service->addSalaryRevision($profile, $request->validated(), $request->user());

        return back()->with('success', 'Salary updated.');
    }

    public function addClassificationRevision(EmployeeClassificationRevisionRequest $request, EmployeePayrollProfile $profile)
    {
        $this->service->addClassificationRevision($profile, $request->validated(), $request->user());

        return back()->with('success', 'Classification updated.');
    }

    public function addManufacturingRoleRevision(EmployeeManufacturingRoleRevisionRequest $request, EmployeePayrollProfile $profile)
    {
        $this->service->addManufacturingRoleRevision($profile, $request->validated(), $request->user());

        return back()->with('success', 'Manufacturing role updated.');
    }

    public function toggleActive(Request $request, EmployeePayrollProfile $profile)
    {
        $this->service->toggleActive($profile, $request->user());

        return back()->with('success', 'Payroll profile updated.');
    }

    public function backdateStart(\Illuminate\Http\Request $request, EmployeePayrollProfile $profile)
    {
        $request->validate(['start_date' => ['required', 'date']]);
        try {
            $this->service->backdateStartDate($profile, $request->string('start_date')->toString(), $request->user());
            return back()->with('success', 'Start date moved earlier — earlier payroll periods now use the starting salary.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
