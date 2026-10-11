<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgStatementService
//
//  Financial Institutions > Statement > LG / Cash Cover Statement.
//
//  Confirmed with Mahmoud (Sept 2026): this is NOT a GL statement —
//  it never touches journal entries or debit/credit balances. It is
//  a MOVEMENT LOG built directly from the LG's own real fields
//  (lg_issuances) plus its lifecycle events (lg_issuance_history):
//  every LG issued, renewed, decreased, cancelled, or liquidated,
//  each row carrying a plain-English comment describing what that
//  movement actually is — "LG Issuance — Beneficiary X — Contract Y"
//  or "LG Cancellation — returned to bank unused", etc. This mirrors
//  what LedgerStatementService does for real GL accounts, but reads
//  the LG's own table instead of the ledger, per Mahmoud's explicit
//  instruction that this report "has nothing to do with debits and
//  credits."
//
//  One issuance produces exactly one "issuance" row (from the parent
//  lg_issuances row itself — issuance is never in the history table,
//  only what happens to it afterward is) plus zero or more history
//  rows (renewal / decrease / cancel / liquidate), each already an
//  independent, dated, append-only record.
//
//  Deliberately NOT paginated at the query level — an LG portfolio is
//  small enough (dozens to low hundreds of rows per company) that
//  building the full movement list in memory and letting the Vue page
//  group/filter/paginate client-side is simpler and correct. If a
//  company's LG book ever grows large enough for this to matter,
//  revisit then — not a premature optimization worth the complexity
//  now.
//
//  @layer    Service
//  @depends  LgIssuance, LgIssuanceHistory
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Models\FinancialInstitutions\LgIssuanceHistory;
use Illuminate\Support\Collection;

class LgStatementService
{
    /**
     * Returns the full movement feed for the given filters, newest
     * first. Each row is a plain array (not an Eloquent model) so the
     * Vue page and the Excel export can consume it identically.
     */
    public function movements(int $companyId, array $filters): Collection
    {
        $issuances = LgIssuance::where('company_id', $companyId)
            ->with(['beneficiary:id,name_en,name_ar', 'bank:id,name_en,name_ar'])
            ->when($filters['bank_id'] ?? null, fn ($q, $v) => $q->where('bank_id', $v))
            ->when($filters['beneficiary_partner_id'] ?? null, fn ($q, $v) => $q->where('beneficiary_partner_id', $v))
            ->when($filters['lg_type'] ?? null, fn ($q, $v) => $q->where('lg_type', $v))
            ->get();

        if ($issuances->isEmpty()) {
            return collect();
        }

        $issuanceIds = $issuances->pluck('id');

        $history = LgIssuanceHistory::whereIn('lg_issuance_id', $issuanceIds)
            ->when(! ($filters['include_reversed'] ?? false), fn ($q) => $q->whereNull('undone_at'))
            ->get()
            ->groupBy('lg_issuance_id');

        $rows = collect();

        foreach ($issuances as $lg) {
            $rows->push($this->issuanceRow($lg));

            foreach ($history->get($lg->id, collect()) as $event) {
                $rows->push($this->historyRow($lg, $event));
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

    private function issuanceRow(LgIssuance $lg): array
    {
        return [
            'date'             => $lg->issuance_date->toDateString(),
            'lg_code'          => $lg->lg_code,
            'lg_type'          => $lg->lg_type,
            'beneficiary_name' => $lg->beneficiary?->name_en,
            'bank_name'        => $lg->bank?->name_en,
            'event_type'       => 'issuance',
            'amount'           => (string) $lg->lg_amount,
            'cash_cover'       => (string) $lg->cash_cover_amount,
            'commission_rate'  => (string) $lg->commission_rate_pct,
            'renewal_date'     => $lg->end_date->toDateString(),
            'status'           => $lg->status,
            'is_reversed'      => false,
            'comment'          => sprintf(
                'LG Issuance — %s — %s',
                $lg->beneficiary?->name_en ?? '—',
                $lg->transaction_name ?: ($lg->lg_code ?? 'no reference')
            ),
        ];
    }

    private function historyRow(LgIssuance $lg, LgIssuanceHistory $event): array
    {
        [$amount, $comment] = match ($event->event_type) {
            'renewal' => [
                (string) $event->renewal_fee_amount,
                sprintf('LG Renewal — extended to %s', optional($event->new_end_date)->toDateString() ?? '—'),
            ],
            'decrease' => [
                (string) $event->decrease_amount,
                sprintf('LG Decrease — reduced by %s', Money::display($event->decrease_amount)),
            ],
            'cancel' => [
                (string) $event->old_current_amount,
                'LG Cancellation — returned to bank unused',
            ],
            'liquidate' => [
                (string) $event->old_current_amount,
                Money::isPositive($event->shortfall_amount ?? 0)
                    ? sprintf('LG Liquidation — beneficiary claim settled (shortfall %s beyond cash cover)', Money::display($event->shortfall_amount))
                    : 'LG Liquidation — beneficiary claim settled from cash cover held',
            ],
            default => ['0.00', ucfirst($event->event_type)],
        };

        if ($event->isUndone()) {
            $comment .= ' — REVERSED';
        }

        return [
            'date'             => $event->event_date->toDateString(),
            'lg_code'          => $lg->lg_code,
            'lg_type'          => $lg->lg_type,
            'beneficiary_name' => $lg->beneficiary?->name_en,
            'bank_name'        => $lg->bank?->name_en,
            'event_type'       => $event->event_type,
            'amount'           => $amount,
            'cash_cover'       => (string) ($event->new_cash_cover_amount ?? $lg->cash_cover_amount),
            'commission_rate'  => (string) ($event->new_commission_rate_pct ?? $lg->commission_rate_pct),
            'renewal_date'     => optional($event->new_end_date)->toDateString() ?? $lg->end_date->toDateString(),
            'status'           => $lg->status,
            'is_reversed'      => $event->isUndone(),
            'comment'          => $comment,
        ];
    }

    /** Lookup lists for the picker page. */
    public function bankOptions(int $companyId): array
    {
        return LgIssuance::where('lg_issuances.company_id', $companyId)
            ->join('banks', 'banks.id', '=', 'lg_issuances.bank_id')
            ->distinct()
            ->get(['banks.id', 'banks.name_en'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name_en])
            ->values()->all();
    }

    public function beneficiaryOptions(int $companyId): array
    {
        return LgIssuance::where('lg_issuances.company_id', $companyId)
            ->join('partners', 'partners.id', '=', 'lg_issuances.beneficiary_partner_id')
            ->distinct()
            ->get(['partners.id', 'partners.name_en'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_en])
            ->values()->all();
    }

    public function lgTypeOptions(int $companyId): array
    {
        return LgIssuance::where('company_id', $companyId)
            ->distinct()
            ->pluck('lg_type')
            ->filter()
            ->values()
            ->all();
    }
}
