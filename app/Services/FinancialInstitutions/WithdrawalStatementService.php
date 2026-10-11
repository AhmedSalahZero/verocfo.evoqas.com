<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  WithdrawalStatementService
//
//  Financial Institutions > Statement > Withdrawal Statement.
//
//  Unlike LgStatementService/LcStatementService, this needs no
//  synthesizing — facility_withdrawals already carries everything
//  Mahmoud asked for (withdrawal date, due date, amount, settled
//  amount, remaining amount, status, its own bank_account_id per
//  Bible Rule 31). This service just filters/eager-loads it and
//  attaches each withdrawal's own settlement history (via
//  FacilitySettlementAllocation, Rule 34.7) so a treasurer can see
//  not just the current remaining balance but *when* it was settled
//  and by which FacilitySettlement.
//
//  @layer    Service
//  @depends  FacilityWithdrawal, FacilitySettlementAllocation, CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\FacilitySettlementAllocation;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use Illuminate\Support\Collection;

class WithdrawalStatementService
{
    public function withdrawals(int $companyId, array $filters): Collection
    {
        $withdrawals = FacilityWithdrawal::where('company_id', $companyId)
            ->with([
                'creditFacility:id,bank_id,facility_reference,facility_type',
                'creditFacility.bank:id,name_en',
                'bankAccount:id,bank_id,account_number',
                'bankAccount.bank:id,name_en',
            ])
            ->when($filters['credit_facility_id'] ?? null, fn ($q, $v) => $q->where('credit_facility_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'overdue' ? $q->overdue() : $q->where('status', $v))
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->where('withdrawal_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->where('withdrawal_date', '<=', $v))
            ->orderByDesc('withdrawal_date')
            ->get();

        if ($filters['bank_id'] ?? null) {
            $bankId = (int) $filters['bank_id'];
            $withdrawals = $withdrawals->filter(fn ($w) => $this->resolveBankId($w) === $bankId)->values();
        }

        $allocations = FacilitySettlementAllocation::where('facility_settlement_allocations.company_id', $companyId)
            ->where('allocatable_type', 'facility_withdrawal')
            ->whereIn('allocatable_id', $withdrawals->pluck('id'))
            ->join('facility_settlements', 'facility_settlements.id', '=', 'facility_settlement_allocations.facility_settlement_id')
            ->get([
                'facility_settlement_allocations.allocatable_id',
                'facility_settlement_allocations.amount',
                'facility_settlements.settlement_date',
                'facility_settlements.status',
            ])
            ->groupBy('allocatable_id');

        return $withdrawals->map(function ($w) use ($allocations) {
            return [
                'id'                 => $w->id,
                'withdrawal_date'    => $w->withdrawal_date->toDateString(),
                'due_date'           => $w->due_date->toDateString(),
                'bank_name'          => $w->bankAccount?->bank?->name_en ?? $w->creditFacility?->bank?->name_en,
                'account_number'     => $w->bankAccount?->account_number,
                'facility_reference' => $w->creditFacility?->facility_reference,
                'facility_type'      => $w->creditFacility?->facility_type,
                'amount'             => (string) $w->amount,
                'settled_amount'     => (string) $w->settled_amount,
                'remaining_amount'   => (string) $w->remaining_amount,
                'status'             => $w->status,
                'is_overdue'         => $w->isOverdue(),
                'description'        => $w->description,
                'settlements'        => $allocations->get($w->id, collect())->map(fn ($a) => [
                    'settlement_date' => optional($a->settlement_date)->toDateString(),
                    'amount'          => (string) $a->amount,
                    'status'          => $a->status,
                ])->values(),
            ];
        })->values();
    }

    private function resolveBankId($withdrawal): ?int
    {
        return $withdrawal->bankAccount?->bank_id ?? $withdrawal->creditFacility?->bank_id;
    }

    /** Lookup lists for the picker page. Bank is company-scoped
     *  (BelongsToCompany) so the global scope already filters this —
     *  no NULL-company exception needed here, unlike the shared
     *  system records covered by the Known Recurring Bug. */
    public function bankOptions(int $companyId): array
    {
        return \App\Models\FinancialInstitutions\Bank::where('company_id', $companyId)
            ->get(['id', 'name_en'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name_en])
            ->values()->all();
    }

    public function facilityOptions(int $companyId): array
    {
        return \App\Models\FinancialInstitutions\CreditFacility::where('company_id', $companyId)
            ->odFamily()
            ->with('bank:id,name_en')
            ->get(['id', 'bank_id', 'facility_reference', 'facility_type'])
            ->map(fn ($f) => [
                'id'   => $f->id,
                'name' => trim(($f->bank?->name_en ?? '').' — '.($f->facility_reference ?? $f->facility_type)),
            ])
            ->values()->all();
    }
}
