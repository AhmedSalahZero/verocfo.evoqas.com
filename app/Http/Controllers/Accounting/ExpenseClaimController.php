<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaimController
//
//  HTTP layer for Expense Claims. Thin: ExpenseClaimService owns the
//  lifecycle and both journal entries; this maps routes onto it.
//
//  The account picker deliberately offers INCOME STATEMENT (expense)
//  accounts only, the same choice OtherPaymentController::debitAccounts()
//  makes for the employee-reimbursement path — accounts 522 / 532 /
//  543 (Employee Reimbursements) sit at the top as the natural picks,
//  but nothing forces them. Migration 000234 records why there is no
//  default at all.
//
//  Separate verbs for submit / approve / reject / cancel / pay /
//  set-to-draft rather than one status endpoint, so the module
//  permission middleware can tell them apart — approving a claim and
//  paying one are very different authorities.
//
//  @layer    Controller
//  @depends  ExpenseClaimService, ExpenseClaimRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\GuardsTenantBoundModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ExpenseClaimRequest;
use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\ExpenseClaim;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Partner;
use App\Services\Accounting\ExpenseClaimService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExpenseClaimController extends Controller
{
    use GuardsTenantBoundModels;

    public function __construct(private ExpenseClaimService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/ExpenseClaims/Index', [
            'claims'    => $this->service->paginate($companyId, $request->only(
                'search', 'status', 'partner_id', 'date_from', 'date_to'
            )),
            'employees' => $this->employees($companyId),
            'statuses'  => ExpenseClaim::ALL_STATUSES,
            'filters'   => $request->only('search', 'status', 'partner_id', 'date_from', 'date_to'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/ExpenseClaims/Form', [
            'claim' => null,
        ] + $this->formLookups($this->companyId($request)));
    }

    public function edit(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        return Inertia::render('Accounting/ExpenseClaims/Form', [
            'claim' => $claim->load(['lines.expenseAccount:id,code,name_en', 'partner:id,name_en']),
        ] + $this->formLookups($this->companyId($request)));
    }

    public function show(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        return Inertia::render('Accounting/ExpenseClaims/Show', [
            'claim' => $claim->load([
                'lines.expenseAccount:id,code,name_en',
                'lines.costCenter:id,name_en',
                'partner:id,name_en,name_ar',
                'currency:id,code',
                'journalEntry.lines.chartOfAccount:id,code,name_en',
                'paymentJournalEntry.lines.chartOfAccount:id,code,name_en',
                'approvedBy:id,name',
                'rejectedBy:id,name',
                'paidBy:id,name',
            ]),
            'bankAccounts' => $this->bankAccounts($this->companyId($request)),
            'safeAccounts' => $this->cashAccounts($this->companyId($request)),
        ]);
    }

    public function store(ExpenseClaimRequest $request)
    {
        try {
            $claim = $this->service->create($this->companyId($request), $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['partner_id' => $e->getMessage()]);
        }

        return redirect()->route('hr.expense-claims.show', $claim)->with('success', 'Expense claim created.');
    }

    public function update(ExpenseClaimRequest $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        try {
            $this->service->update($claim, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['partner_id' => $e->getMessage()]);
        }

        return redirect()->route('hr.expense-claims.show', $claim)->with('success', 'Expense claim updated.');
    }

    public function destroy(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        try {
            $this->service->delete($claim, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('hr.expense-claims.index')->with('success', 'Expense claim deleted.');
    }

    // ── Transitions ───────────────────────────────────────────────

    public function submit(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        return $this->transition(
            fn () => $this->service->submit($claim, $request->user()),
            'Expense claim submitted for approval.'
        );
    }

    public function approve(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        $date = $request->validate([
            'approval_date' => ['nullable', 'date'],
        ])['approval_date'] ?? null;

        return $this->transition(
            fn () => $this->service->approve($claim, $request->user(), $date),
            'Expense claim approved and posted.'
        );
    }

    public function reject(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        $reason = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ])['rejection_reason'] ?? null;

        return $this->transition(
            fn () => $this->service->reject($claim, $request->user(), $reason),
            'Expense claim rejected.'
        );
    }

    public function cancel(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        return $this->transition(
            fn () => $this->service->cancel($claim, $request->user()),
            'Expense claim cancelled.'
        );
    }

    public function setToDraft(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        return $this->transition(
            fn () => $this->service->setToDraft($claim, $request->user()),
            'Expense claim reopened to draft — its posting has been reversed.'
        );
    }

    public function markPaid(Request $request, ExpenseClaim $claim)
    {
        $this->guardTenant($request, $claim);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'payment_date'            => ['nullable', 'date'],
            'payment_source_type'     => ['required', Rule::in([
                ExpenseClaim::SOURCE_BANK_ACCOUNT,
                ExpenseClaim::SOURCE_CASH_SAFE,
            ])],
            'payment_bank_account_id' => [
                'required_if:payment_source_type,'.ExpenseClaim::SOURCE_BANK_ACCOUNT,
                'nullable',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'payment_safe_account_id' => [
                'required_if:payment_source_type,'.ExpenseClaim::SOURCE_CASH_SAFE,
                'nullable',
                Rule::exists('chart_of_accounts', 'id')->where(
                    fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ),
            ],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->transition(
            fn () => $this->service->markPaid($claim, $data, $request->user()),
            'Expense claim paid.'
        );
    }

    // ── Internals ─────────────────────────────────────────────────

    private function transition(callable $action, string $message)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }

    private function formLookups(int $companyId): array
    {
        return [
            'employees'       => $this->employees($companyId),
            'expenseAccounts' => $this->expenseAccounts($companyId),
            'costCenters'     => CostCenter::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'name_en']),
            'currencies' => Currency::withoutGlobalScopes()
                ->whereNull('company_id')
                ->orWhere('company_id', $companyId)
                ->orderBy('code')
                ->get(['id', 'code', 'name_en']),
        ];
    }

    private function employees(int $companyId)
    {
        return Partner::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereJsonContains('types', Partner::TYPE_EMPLOYEE)
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    /**
     * Income Statement expense accounts, with the three Employee
     * Reimbursement accounts first — they are the natural pick, not a
     * forced one. Same treatment Other Payment's reimbursement path
     * gives its own picker.
     */
    private function expenseAccounts(int $companyId)
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->where('class', 'expenses')
            ->orderByRaw("CASE WHEN code IN ('522','532','543') THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar']);
    }

    private function bankAccounts(int $companyId)
    {
        return BankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_number']);
    }

    /** Cash safes — the company's own postable leaves under 131 Cash. */
    private function cashAccounts(int $companyId)
    {
        $cashParent = ChartOfAccount::findByCode($companyId, '131');

        if (! $cashParent) {
            return collect();
        }

        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->active() // Audit M2: must match the service-side safe check
            ->where('parent_id', $cashParent->id)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }
}
