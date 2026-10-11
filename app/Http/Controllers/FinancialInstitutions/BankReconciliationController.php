<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliationController
//
//  Audit H-25 — Financial Institutions > Bank Reconciliation. The HTTP
//  layer only: every rule (matching, the report, the "difference must
//  be 0.00 to complete" check) lives in BankReconciliationService.
//  A DomainException from the service is shown to the user as a plain
//  error message; nothing else is caught.
//
//  Routes (under auth + active.company):
//    GET    /bank-reconciliation                         index   list + start form
//    POST   /bank-reconciliation                         store   start a reconciliation
//    GET    /bank-reconciliation/{id}                    show    the reconcile screen
//    PUT    /bank-reconciliation/{id}                    update  fix date / closing balance
//    DELETE /bank-reconciliation/{id}                    destroy delete a draft
//    POST   …/statement-lines, …/import                  add / import bank statement lines
//    DELETE …/statement-lines/{line}                     remove an unmatched line
//    POST   …/auto-match, …/match, …/clear               matching
//    POST   …/unclear, …/unmatch                         undo matching
//    POST   …/complete    PATCH …/reopen                 finish / reopen
//    GET    …/export                                     Excel report
//
//  @layer    Controller
//  @depends  BankReconciliationService, BankReconciliation
// ══════════════════════════════════════════════════════════════════

use App\Exports\BankReconciliationExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\BankReconciliationMatchRequest;
use App\Http\Requests\FinancialInstitutions\BankStatementLineRequest;
use App\Http\Requests\FinancialInstitutions\ImportBankStatementRequest;
use App\Http\Requests\FinancialInstitutions\StartBankReconciliationRequest;
use App\Models\FinancialInstitutions\BankReconciliation;
use App\Services\FinancialInstitutions\BankReconciliationService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciliationService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Reconciliation/Index', [
            'reconciliations' => $this->service->paginate($companyId, $request->only('bank_account_id', 'status')),
            'bankAccounts'    => $this->service->bankAccountOptions($companyId),
            'filters'         => $request->only('bank_account_id', 'status'),
        ]);
    }

    public function store(StartBankReconciliationRequest $request)
    {
        try {
            $rec = $this->service->start($this->companyId($request), $request->validated(), $request->user());

            return redirect()->route('fi.bank-reconciliation.show', $rec->id)->with('success', 'Reconciliation started.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(Request $request, BankReconciliation $reconciliation)
    {
        return Inertia::render('FinancialInstitutions/Reconciliation/Reconcile', $this->service->workspace($this->companyId($request), $reconciliation));
    }

    public function update(StartBankReconciliationRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->updateHeader($this->companyId($request), $reconciliation, $request->validated(), $request->user()), 'Saved.');
    }

    public function destroy(Request $request, BankReconciliation $reconciliation)
    {
        try {
            $this->service->delete($this->companyId($request), $reconciliation, $request->user());

            return redirect()->route('fi.bank-reconciliation.index')->with('success', 'Reconciliation deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Bank statement lines ─────────────────────────────────────

    public function storeStatementLine(BankStatementLineRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->addStatementLine($this->companyId($request), $reconciliation, $request->validated(), $request->user()), 'Statement line added.');
    }

    public function importStatement(ImportBankStatementRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(function () use ($request, $reconciliation) {
            $r = $this->service->importStatement($this->companyId($request), $reconciliation, $request->file('file'), $request->user());

            return "Imported {$r['imported']} line(s); {$r['skipped']} already existed and were skipped."
                .($r['errors'] ? ' Rows that could not be read: '.implode(' | ', array_slice($r['errors'], 0, 3)) : '');
        }, null);
    }

    public function destroyStatementLine(Request $request, BankReconciliation $reconciliation, int $line)
    {
        return $this->attempt(fn () => $this->service->deleteStatementLine($this->companyId($request), $reconciliation, $line, $request->user()), 'Statement line removed.');
    }

    // ── Matching ─────────────────────────────────────────────────

    public function autoMatch(BankReconciliationMatchRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(function () use ($request, $reconciliation) {
            $window = (int) ($request->validated()['window_days'] ?? BankReconciliationService::DEFAULT_MATCH_WINDOW_DAYS);
            $n = $this->service->autoMatch($this->companyId($request), $reconciliation, $window, $request->user());

            return $n > 0 ? "{$n} statement line(s) matched automatically." : 'No further matches found — match the rest by hand.';
        }, null);
    }

    public function match(BankReconciliationMatchRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->match($this->companyId($request), $reconciliation, (int) $request->validated()['statement_line_id'], $request->validated()['book_line_ids'] ?? [], $request->user()), 'Matched.');
    }

    public function clear(BankReconciliationMatchRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->clear($this->companyId($request), $reconciliation, $request->validated()['book_line_ids'] ?? [], $request->user()), 'Ticked as cleared.');
    }

    public function unclear(BankReconciliationMatchRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->unclear($this->companyId($request), $reconciliation, $request->validated()['book_line_ids'] ?? [], $request->user()), 'Unticked.');
    }

    public function unmatch(BankReconciliationMatchRequest $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->unmatchStatementLine($this->companyId($request), $reconciliation, (int) $request->validated()['statement_line_id'], $request->user()), 'Match removed.');
    }

    // ── Complete / reopen / export ───────────────────────────────

    public function complete(Request $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->complete($this->companyId($request), $reconciliation, $request->user()), 'Reconciliation completed — the report is now frozen.');
    }

    public function reopen(Request $request, BankReconciliation $reconciliation)
    {
        return $this->attempt(fn () => $this->service->reopen($this->companyId($request), $reconciliation, $request->user()), 'Reconciliation reopened.');
    }

    public function exportExcel(Request $request, BankReconciliation $reconciliation)
    {
        $detail = $this->service->reportDetail($this->companyId($request), $reconciliation);

        return Excel::download(new BankReconciliationExport($reconciliation, $detail), 'bank-reconciliation-'.$reconciliation->id.'.xlsx');
    }

    // ── Private ──────────────────────────────────────────────────

    /**
     * Run a service call; show its DomainException as an error message.
     * $success may be a fixed message, or null when the call returns the message itself.
     */
    private function attempt(Closure $work, ?string $success)
    {
        try {
            $result = $work();

            return back()->with('success', $success ?? (is_string($result) ? $result : 'Done.'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
