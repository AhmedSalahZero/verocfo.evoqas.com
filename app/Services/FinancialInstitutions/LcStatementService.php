<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcStatementService
//
//  Financial Institutions > Statement > LC Statement. Same pattern
//  and reasoning as LgStatementService (see that file's header) —
//  a movement log read directly from letter_of_credit_issuances +
//  lc_issuance_history, never the GL. One issuance row per LC plus
//  one row per lifecycle event (mark_paid, settlement).
//
//  @layer    Service
//  @depends  LetterOfCreditIssuance, LcIssuanceHistory
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\FinancialInstitutions\LcIssuanceHistory;
use App\Models\FinancialInstitutions\LetterOfCreditIssuance;
use Illuminate\Support\Collection;

class LcStatementService
{
    public function movements(int $companyId, array $filters): Collection
    {
        $issuances = LetterOfCreditIssuance::where('company_id', $companyId)
            ->with(['supplier:id,name_en,name_ar', 'bank:id,name_en,name_ar'])
            ->when($filters['bank_id'] ?? null, fn ($q, $v) => $q->where('bank_id', $v))
            ->when($filters['supplier_partner_id'] ?? null, fn ($q, $v) => $q->where('supplier_partner_id', $v))
            ->when($filters['lc_type'] ?? null, fn ($q, $v) => $q->where('lc_type', $v))
            ->get();

        if ($issuances->isEmpty()) {
            return collect();
        }

        $issuanceIds = $issuances->pluck('id');

        $history = LcIssuanceHistory::whereIn('lc_issuance_id', $issuanceIds)
            ->when(! ($filters['include_reversed'] ?? false), fn ($q) => $q->whereNull('undone_at'))
            ->get()
            ->groupBy('lc_issuance_id');

        $rows = collect();

        foreach ($issuances as $lc) {
            $rows->push($this->issuanceRow($lc));

            foreach ($history->get($lc->id, collect()) as $event) {
                $rows->push($this->historyRow($lc, $event));
            }
        }

        if ($filters['start_date'] ?? null) {
            $rows = $rows->filter(fn ($r) => $r['date'] >= $filters['start_date']);
        }
        if ($filters['end_date'] ?? null) {
            $rows = $rows->filter(fn ($r) => $r['date'] <= $filters['end_date']);
        }

        return $rows->sortByDesc('date')->values();
    }

    // ── Row builders ─────────────────────────────────────────────

    private function issuanceRow(LetterOfCreditIssuance $lc): array
    {
        return [
            'date'            => $lc->issuance_date->toDateString(),
            'lc_code'         => $lc->lc_code,
            'lc_type'         => $lc->lc_type,
            'supplier_name'   => $lc->supplier?->name_en,
            'bank_name'       => $lc->bank?->name_en,
            'event_type'      => 'issuance',
            'amount'          => (string) $lc->lc_amount,
            'cash_cover'      => (string) $lc->cash_cover_amount,
            'commission_rate' => (string) $lc->commission_rate_pct,
            'due_date'        => $lc->due_date->toDateString(),
            'status'          => $lc->status,
            'is_reversed'     => false,
            'comment'         => sprintf(
                'LC Issuance — %s — %s',
                $lc->supplier?->name_en ?? '—',
                $lc->transaction_name ?: ($lc->lc_code ?? 'no reference')
            ),
        ];
    }

    private function historyRow(LetterOfCreditIssuance $lc, LcIssuanceHistory $event): array
    {
        [$amount, $comment] = match ($event->event_type) {
            'mark_paid' => [
                (string) $event->amount_paid_to_supplier,
                sprintf('LC Marked Paid — %s paid to supplier', Money::display($event->amount_paid_to_supplier)),
            ],
            'settlement' => [
                (string) $event->principal_amount,
                sprintf(
                    'LC Settlement — %s principal%s',
                    Money::display($event->principal_amount),
                    Money::isPositive($event->interest_amount ?? 0)
                        ? sprintf(' + %s interest', Money::display($event->interest_amount))
                        : ''
                ),
            ],
            default => ['0.00', ucfirst($event->event_type)],
        };

        if ($event->isUndone()) {
            $comment .= ' — REVERSED';
        }

        return [
            'date'            => $event->event_date->toDateString(),
            'lc_code'         => $lc->lc_code,
            'lc_type'         => $lc->lc_type,
            'supplier_name'   => $lc->supplier?->name_en,
            'bank_name'       => $lc->bank?->name_en,
            'event_type'      => $event->event_type,
            'amount'          => $amount,
            'cash_cover'      => (string) $lc->cash_cover_amount,
            'commission_rate' => (string) $lc->commission_rate_pct,
            'due_date'        => $lc->due_date->toDateString(),
            'status'          => $lc->status,
            'is_reversed'     => $event->isUndone(),
            'comment'         => $comment,
        ];
    }

    /** Lookup lists for the picker page. */
    public function bankOptions(int $companyId): array
    {
        return LetterOfCreditIssuance::where('letter_of_credit_issuances.company_id', $companyId)
            ->join('banks', 'banks.id', '=', 'letter_of_credit_issuances.bank_id')
            ->distinct()
            ->get(['banks.id', 'banks.name_en'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name_en])
            ->values()->all();
    }

    public function supplierOptions(int $companyId): array
    {
        return LetterOfCreditIssuance::where('letter_of_credit_issuances.company_id', $companyId)
            ->join('partners', 'partners.id', '=', 'letter_of_credit_issuances.supplier_partner_id')
            ->distinct()
            ->get(['partners.id', 'partners.name_en'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_en])
            ->values()->all();
    }

    public function lcTypeOptions(int $companyId): array
    {
        return LetterOfCreditIssuance::where('company_id', $companyId)
            ->distinct()
            ->pluck('lc_type')
            ->filter()
            ->values()
            ->all();
    }
}
