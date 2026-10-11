<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilitySettlementController
//
//  HTTP layer for the Facility Settlement Engine (Building Bible
//  Rule 34) screens. Deliberately ONE controller shared across all
//  four OD-family facility types (Clean OD, Commercial Paper OD,
//  Contract-Assignment OD, Fully Secured OD) — same reasoning as
//  Rule 34.1's shared engine itself: never four copies of the same
//  screen. Reached from each facility type's own Index/Edit page via
//  a "Settlements" link, not its own top-level menu item.
//
//  Zero business logic here — everything delegates to
//  FacilitySettlementEngine.
//
//  Routes (under auth + active.company):
//    GET    /facilities/{facility}/settlements                        → index()
//    POST   /facilities/{facility}/charges                             → postCharge()
//
//  Deliberately NO standalone "reverse a settlement" endpoint here.
//  Mahmoud confirmed (2026-09): in VeroCFO, no transaction is ever
//  corrected outside the clean reopen/cancel path on the transaction
//  that created it — because unlike CashVero (pure treasury, no GL),
//  VeroCFO's accounting engine means a settlement reversed on its own
//  would leave the GL and this sub-ledger disagreeing with no audit
//  trail explaining why. Undoing a settlement is only ever a side
//  effect of reopening/cancelling the Customer Payment or Internal
//  Transfer that created it (see CustomerPaymentService::
//  reverseEverything(), InternalMoneyTransferService::cancel()) —
//  never a button on this screen.
//
//  @layer    Controller
//  @depends  FacilitySettlementEngine, CreditFacility, FacilityWithdrawal,
//            FacilityLiabilityCharge, FacilitySettlement, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialInstitutions\PostFacilityLiabilityChargeRequest;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FacilitySettlementController extends Controller
{
    public function __construct(
        private FacilitySettlementEngine $settlementEngine,
    ) {}

    public function index(Request $request, CreditFacility $facility)
    {
        abort_unless($facility->isOdType(), 404);

        $companyId = $this->companyId($request);

        return Inertia::render('FinancialInstitutions/Banks/Facilities/FacilitySettlements', [
            'facility' => $facility->load(['bank:id,name_en,name_ar', 'currency:id,code']),
            'openCharges' => FacilityLiabilityCharge::where('credit_facility_id', $facility->id)
                ->settleable()->get(),
            'openWithdrawals' => FacilityWithdrawal::where('credit_facility_id', $facility->id)
                ->settleable()->get(),
            'settlements' => FacilitySettlement::where('credit_facility_id', $facility->id)
                ->with('allocations', 'bankAccount:id,account_number', 'createdBy:id,name')
                ->orderByDesc('settlement_date')
                ->paginate(15)
                ->withQueryString(),
            'bankAccounts' => BankAccount::where('company_id', $companyId)
                ->where('is_active', true)
                ->get(['id', 'account_number', 'bank_id']),
        ]);
    }

    public function postCharge(PostFacilityLiabilityChargeRequest $request, CreditFacility $facility)
    {
        abort_unless($facility->isOdType(), 404);

        try {
            $this->settlementEngine->postLiabilityCharge($facility, $request->validated(), $request->user());
            return back()->with('success', 'Charge posted successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }
}
