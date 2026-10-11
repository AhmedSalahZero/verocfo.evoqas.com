<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  TimeDepositController
//
//  HTTP layer for the four Time Deposit lifecycle actions — Apply
//  Interest, Break, Apply at Maturity, and Renew. Zero business
//  logic — all delegated to TimeDepositService. Separate from
//  BankController since these are lifecycle events on an existing
//  account, not account CRUD.
//
//  Routes (under auth + active.company):
//    POST  /bank-accounts/{bankAccount}/td/interest  → applyInterest()
//    POST  /bank-accounts/{bankAccount}/td/break      → breakTd()
//    POST  /bank-accounts/{bankAccount}/td/mature     → matureTd()
//    POST  /bank-accounts/{bankAccount}/td/renew      → renew()
//
//  All four are JSON endpoints (called from a modal on the Bank
//  Accounts index, same pattern as ContractAssignmentOdController's
//  addAssignment()/removeAssignment()) — not Inertia redirects.
//
//  @layer    Controller
//  @depends  TimeDepositService, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\ApplyTdInterestRequest;
use App\Http\Requests\FinancialInstitutions\CloseTdRequest;
use App\Http\Requests\FinancialInstitutions\RenewTdRequest;
use App\Models\FinancialInstitutions\BankAccount;
use App\Services\FinancialInstitutions\TimeDepositService;
use Illuminate\Http\Request;

class TimeDepositController extends Controller
{
    public function __construct(private TimeDepositService $service) {}

    public function applyInterest(ApplyTdInterestRequest $request, BankAccount $bankAccount)
    {
        try {
            $event = $this->service->applyInterest($bankAccount, $request->validated(), $request->user());
            return response()->json(['event' => $event, 'message' => trim('Interest applied successfully. '.implode(' ', $this->service->takeInterestWarnings()))]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function breakTd(CloseTdRequest $request, BankAccount $bankAccount)
    {
        try {
            $event = $this->service->breakOrMature($bankAccount, 'break', $request->validated(), $request->user());
            return response()->json(['event' => $event, 'message' => trim('Time Deposit broken successfully. '.implode(' ', $this->service->takeInterestWarnings()))]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function matureTd(CloseTdRequest $request, BankAccount $bankAccount)
    {
        try {
            $event = $this->service->breakOrMature($bankAccount, 'maturity', $request->validated(), $request->user());
            return response()->json(['event' => $event, 'message' => trim('Time Deposit matured successfully. '.implode(' ', $this->service->takeInterestWarnings()))]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function renew(RenewTdRequest $request, BankAccount $bankAccount)
    {
        try {
            $event = $this->service->renew($bankAccount, $request->validated(), $request->user());
            return response()->json(['event' => $event, 'message' => 'Time Deposit renewed successfully.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── History (modal "view history" panel) ────────────────────────

    public function history(Request $request, BankAccount $bankAccount)
    {
        return response()->json([
            'events' => $bankAccount->tdEvents()->with(['creditBankAccount:id,account_name', 'createdBy:id,name'])->get(),
        ]);
    }
}
