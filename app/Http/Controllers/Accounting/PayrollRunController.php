<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunController
//
//  HTTP layer for the monthly Payroll Run — create, add/remove
//  addition/deduction lines, enter manual tax/Social Insurance/
//  advance-recovery amounts, post, and Set to Draft. See
//  PayrollRunService header for the full accounting design.
//
//  No update() on the run itself — a run's period/company are fixed
//  at creation; correcting it means editing the individual lines.
//
//  @layer    Controller
//  @depends  PayrollRunService, PayrollRunRequest, PayrollRunLineItemRequest,
//            PayrollRunManualAmountsRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PayrollRunLineItemRequest;
use App\Http\Requests\Accounting\PayrollRunManualAmountsRequest;
use App\Http\Requests\Accounting\PayrollRunRequest;
use App\Models\DeductionReason;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunLineItem;
use App\Services\Accounting\PayrollRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollRunController extends Controller
{
    public function __construct(private PayrollRunService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Payroll/PayrollRunIndex', [
            'runs'    => $this->service->paginate($this->companyId($request), $request->only('status')),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/Payroll/PayrollRunCreate', ['employees' => $this->service->employeeChoices($this->companyId($request))]);
    }

    public function store(PayrollRunRequest $request)
    {
        $data = $request->validated();
        $run = $this->service->create($this->companyId($request), (int) $data['period_year'], (int) $data['period_month'], $data['run_date'], $request->user(), [
            'run_type' => $data['run_type'] ?? 'regular', 'partner_ids' => $data['partner_ids'] ?? [], 'label' => $data['label'] ?? null,
        ]);

        return redirect()->route('hr.payroll.show', $run->id)->with('success', 'Payroll Run created.');
    }

    public function show(Request $request, PayrollRun $run)
    {
        return Inertia::render('Accounting/Payroll/PayrollRunShow', [
            'run'              => $run->load('lines.partner', 'lines.lineItems.deductionReason', 'postings.journalEntry.lines.chartOfAccount', 'payments'),
            'bankAccounts'     => $this->service->payableBankAccounts($this->companyId($request)),
            'deductionReasons' => DeductionReason::where('company_id', $this->companyId($request))->active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'behavior']),
        ]);
    }

    public function addLineItem(PayrollRunLineItemRequest $request, PayrollRunLine $line)
    {
        $this->service->addLineItem($line, $request->validated(), $request->user());

        return back()->with('success', 'Line item added.');
    }

    public function removeLineItem(Request $request, PayrollRunLineItem $item)
    {
        $this->service->removeLineItem($item, $request->user());

        return back()->with('success', 'Line item removed.');
    }

    public function setManualAmounts(PayrollRunManualAmountsRequest $request, PayrollRunLine $line)
    {
        $data = $request->validated();

        $this->service->setManualAmounts(
            $line,
            (string) $data['tax_amount'],
            (string) $data['social_insurance_employee_amount'],
            (string) $data['social_insurance_employer_amount'],
            (string) ($data['advance_deduction_amount'] ?? '0'),
            $request->user(),
        );

        return back()->with('success', 'Amounts updated.');
    }

    public function post(Request $request, PayrollRun $run)
    {
        $this->service->post($run, $request->user());

        return back()->with('success', 'Payroll Run posted.');
    }

    public function setToDraft(Request $request, PayrollRun $run)
    {
        $this->service->setToDraft($run, $request->user());

        return back()->with('success', 'Payroll Run reopened to draft.');
    }

    /** M-36: pay the run's net salaries from a bank account. */
    public function pay(Request $request, PayrollRun $run)
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer'], 'payment_date' => ['required', 'date']]);
        $this->service->payNetPay($run, (int) $data['bank_account_id'], $data['payment_date'], $request->user());

        return back()->with('success', 'Net pay paid.');
    }

    public function reversePayment(Request $request, PayrollRun $run)
    {
        $this->service->reverseNetPayment($run, $request->user());

        return back()->with('success', 'Net pay payment reversed.');
    }

    /** M-36: printable payslip for one employee line. */
    public function payslip(Request $request, PayrollRunLine $line)
    {
        return view('payroll.payslip', $this->service->payslipData($line));
    }

    /** Live lookup — outstanding advance balance for one employee, used by the manual-amounts modal. */
    public function outstandingAdvance(Request $request, PayrollRunLine $line)
    {
        return response()->json([
            'outstanding' => $this->service->outstandingAdvanceBalance($this->companyId($request), $line->partner_id),
        ]);
    }
}
