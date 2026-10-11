<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LandingCostService
//
//  All business logic for Landing Cost — see migration 000203 and
//  VeroCFO_Landing_Cost_Design_v1_0.md for the full design and
//  decision log. A standalone document (never linked to a Purchase
//  Order) representing one logistics cost tied to a shipment.
//
//  THREE INDEPENDENT ACTIONS on one record:
//    post()   — Dr chart_of_account_id (146xxx) / Cr 232 Other
//               Creditors. The liability now exists.
//    pay()    — Dr 232 Other Creditors / Cr Bank. Independent of
//               attachment — can happen before or after the goods
//               physically arrive, and before or after attach().
//    attach() — called BY GoodsReceiptNoteService::post(), never
//               directly by a controller. Computes the per-line
//               allocation (see computeAllocations() below), returns
//               it to the caller to fold into the GRN's own journal
//               entry bucket, and marks this record permanently used.
//               See LandingCost::isEligibleForAttachment() — the
//               single source of truth this whole class defers to.
//
//  ALLOCATION BASES (Value / Quantity / Weight / Manual) — see
//  computeAllocations(). The last line in iteration order always
//  absorbs the rounding residue, same pattern already used elsewhere
//  in this codebase (e.g. SupplierBillService's Fixed Asset variance
//  distribution) — guarantees the split always sums to EXACTLY the
//  Landing Cost's own amount, never off by a cent from bcdiv
//  rounding.
//
//  AUDIT C8 (Sept 2026): pay() refuses a foreign-currency bank account,
//  since a Landing Cost's amount is always in the functional currency
//  (its own currency field is audit item M32).
//
//  @layer    Service
//  @depends  FxService, LandingCost, LandingCostAllocation, Partner, ChartOfAccount,
//            FinancialInstitutions\BankAccount, GoodsReceiptNoteLine,
//            JournalEntryService, FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\GoodsReceiptNoteLine;
use App\Models\JournalEntry;
use App\Models\LandingCost;
use App\Models\LandingCostAllocation;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LandingCostService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private \App\Services\FiscalYearService $fiscalYearService,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LandingCost::with(['partner:id,name_en,name_ar', 'chartOfAccount:id,code,name_en', 'goodsReceiptNote:id,grn_code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('landing_cost_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** Live lookup — every unused Landing Cost, for the GRN attachment picker. See LandingCost::scopeEligibleForAttachment(). */
    public function eligibleForAttachment(int $companyId, array $filters = []): \Illuminate\Support\Collection
    {
        return LandingCost::eligibleForAttachment()
            ->with(['partner:id,name_en,name_ar', 'chartOfAccount:id,code,name_en'])
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('cost_date')
            ->get();
    }

    /**
     * M-32: the cost and its recoverable VAT are entered in the document
     * currency; the rate is the typed one or the rate table on the cost
     * date (FxService refuses to guess a missing foreign rate).
     */
    private function withFunctionalAmounts(int $companyId, array $data): array
    {
        $fx   = app(FxService::class);
        $curr = ! empty($data['currency_id']) ? (int) $data['currency_id'] : null;
        $rate = $fx->resolveRate($companyId, $curr, substr((string) $data['cost_date'], 0, 10), $data['exchange_rate'] ?? null);
        $vat  = (string) ($data['vat_amount'] ?? 0) === '' ? '0' : (string) ($data['vat_amount'] ?? 0);

        $data['exchange_rate']         = $rate;
        $data['vat_amount']            = $vat;
        $data['amount_functional']     = $fx->toFunctional($data['amount'], $rate);
        $data['vat_amount_functional'] = $fx->toFunctional($vat, $rate);

        return $data;
    }

    // ── Create (draft) ────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): LandingCost
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $partner = Partner::findOrFail($data['partner_id']);
            if (! $partner->is_landing_cost_supplier) {
                throw new \DomainException("{$partner->name_en} is not marked as a Landing Cost Supplier.");
            }

            $data = $this->withFunctionalAmounts($companyId, $data);
            $data['company_id']         = $companyId;
            $data['created_by']         = $actor->id;
            $data['landing_cost_code']  = $this->generateCode($companyId, $data['cost_date'] ?? null);
            $data['status']             = LandingCost::STATUS_DRAFT;

            $landingCost = LandingCost::create($data);

            AuditLog::record($actor, 'landing_cost.created', $landingCost, null, $landingCost->fresh()->toArray());

            return $landingCost->fresh(['partner', 'chartOfAccount']);
        });
    }

    public function update(LandingCost $landingCost, array $data, User $actor): LandingCost
    {
        if (! $landingCost->isDraft()) {
            throw new \DomainException('Only a draft Landing Cost can be edited.');
        }

        return DB::transaction(function () use ($landingCost, $data, $actor) {
            $before = $landingCost->toArray();
            $data = $this->withFunctionalAmounts($landingCost->company_id, $data);
            $data['updated_by'] = $actor->id;
            $landingCost->update($data);

            AuditLog::record($actor, 'landing_cost.updated', $landingCost, $before, $landingCost->fresh()->toArray());

            return $landingCost->fresh(['partner', 'chartOfAccount']);
        });
    }

    public function delete(LandingCost $landingCost, User $actor): void
    {
        if (! $landingCost->isDraft()) {
            throw new \DomainException('Only a draft Landing Cost can be deleted.');
        }

        $before = $landingCost->toArray();
        $landingCost->delete();
        AuditLog::record($actor, 'landing_cost.deleted', $landingCost, $before, null);
    }

    // ── Post — creates the liability ──────────────────────────────

    public function post(LandingCost $landingCost, User $actor): LandingCost
    {
        if (! $landingCost->isDraft()) {
            throw new \DomainException('Only a draft Landing Cost can be posted.');
        }

        // Rule 26.5 extension (confirmed with Mahmoud, September 2026):
        // a Landing Cost changes the recorded value of stock that was
        // already received via a Goods Receipt Note — back-dating it
        // past a newer fiscal year risks corrupting a valuation that
        // later calculations may already have built on top of.
        if ($this->fiscalYearService->newerFiscalYearExists($landingCost->company_id, (string) $landingCost->cost_date)) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Landing Cost [{$landingCost->landing_cost_code}] — its date ({$landingCost->cost_date}) falls in a fiscal year that has since been superseded by a newer one. Landing Costs cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
            );
        }

        return DB::transaction(function () use ($landingCost, $actor) {
            $before = $landingCost->toArray();

            $otherCreditors = ChartOfAccount::findByCode($landingCost->company_id, '232');
            if (! $otherCreditors) {
                throw new \DomainException('Other Creditors account (232) is not seeded for this company — cannot post.');
            }

            $net = (string) $landingCost->amount_functional;
            $vat = (string) $landingCost->vat_amount_functional;
            $gross = bcadd($net, $vat, 2);

            $lines = [
                ['chart_of_account_id' => $landingCost->chart_of_account_id, 'debit' => $net, 'credit' => '0', 'description' => 'Landing Cost '.$landingCost->landing_cost_code],
            ];
            // M-32: customs / clearing VAT is recoverable — it goes to VAT Input, never into the stock cost.
            if (bccomp($vat, '0', 2) > 0) {
                $vatAccount = ChartOfAccount::findByCode($landingCost->company_id, '137')
                    ?? throw new \DomainException('VAT Input account (137) is not seeded for this company — cannot post the recoverable VAT.');
                $lines[] = ['chart_of_account_id' => $vatAccount->id, 'debit' => $vat, 'credit' => '0', 'description' => 'Landing Cost VAT '.$landingCost->landing_cost_code];
            }
            $lines[] = ['chart_of_account_id' => $otherCreditors->id, 'debit' => '0', 'credit' => $gross, 'description' => 'Landing Cost '.$landingCost->landing_cost_code];

            $journalEntry = $this->journalEntryService->post(
                companyId: $landingCost->company_id,
                entryDate: $landingCost->cost_date,
                lines: $lines,
                sourceType: 'landing_cost',
                sourceId: $landingCost->id,
                narration: "Landing Cost {$landingCost->landing_cost_code} — {$landingCost->partner->name_en}",
                actor: $actor,
            );

            $landingCost->update([
                'status'           => LandingCost::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'        => $actor->id,
                'posted_at'        => now(),
            ]);

            AuditLog::record($actor, 'landing_cost.posted', $landingCost, $before, $landingCost->fresh()->toArray());

            return $landingCost->fresh(['partner', 'chartOfAccount', 'journalEntry.lines']);
        });
    }

    /**
     * Reopens a posted-but-not-yet-paid-or-attached Landing Cost back
     * to draft — mirrors the reopen pattern used throughout this
     * project (e.g. OtherPaymentService::setToDraft()). Blocked once
     * paid or attached, same reasoning as everywhere else: those are
     * real, separate downstream effects this method has no way to
     * safely unwind on its own.
     */
    public function setToDraft(LandingCost $landingCost, User $actor): LandingCost
    {
        if (! $landingCost->isPosted()) {
            throw new \DomainException('Only a posted Landing Cost (not yet paid or attached) can be set back to draft.');
        }
        if ($landingCost->isAttached()) {
            throw new \DomainException('This Landing Cost is already attached to a Goods Receipt Note — it cannot be reopened.');
        }
        if ($this->fiscalYearService->isPeriodClosed($landingCost->company_id, (string) $landingCost->cost_date)) {
            throw new \DomainException('This Landing Cost\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($landingCost, $actor) {
            $before = $landingCost->toArray();

            if ($landingCost->journal_entry_id) {
                $entry = JournalEntry::findOrFail($landingCost->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$landingCost->landing_cost_code);
            }

            $landingCost->update([
                'status'           => LandingCost::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'landing_cost.set_to_draft', $landingCost, $before, $landingCost->fresh()->toArray());

            return $landingCost->fresh();
        });
    }

    // ── Pay — independent of attachment, see class header ──────────

    public function pay(LandingCost $landingCost, int $bankAccountId, string $paidDate, User $actor): LandingCost
    {
        if (! $landingCost->isPosted()) {
            throw new \DomainException('Only a posted Landing Cost can be paid.');
        }

        return DB::transaction(function () use ($landingCost, $bankAccountId, $paidDate, $actor) {
            $before = $landingCost->toArray();

            $bankAccount = BankAccount::where('id', $bankAccountId)->where('company_id', $landingCost->company_id)->first();
            if (! $bankAccount || ! $bankAccount->coa_account_id) {
                throw new \DomainException('The selected bank account does not belong to this company, or has no Chart of Accounts link.');
            }

            // M-32: the bank account must hold the Landing Cost's own currency.
            $fx = app(FxService::class);
            $costCurrency = $landingCost->currency_id ? (int) $landingCost->currency_id : null;
            $bankCurrency = $bankAccount->currency_id ? (int) $bankAccount->currency_id : null;
            if ($fx->isFunctional($landingCost->company_id, $costCurrency) !== $fx->isFunctional($landingCost->company_id, $bankCurrency)
                || (! $fx->isFunctional($landingCost->company_id, $costCurrency) && $costCurrency !== $bankCurrency)) {
                throw new \DomainException('Pay this Landing Cost from a bank account in the same currency as the cost itself.');
            }

            $otherCreditors = ChartOfAccount::findByCode($landingCost->company_id, '232');
            if (! $otherCreditors) {
                throw new \DomainException('Other Creditors account (232) is not seeded for this company — cannot post.');
            }

            // 232 was credited at the booked value; the bank is credited at
            // the payment-date value; any difference is realised FX.
            $booked  = bcadd((string) $landingCost->amount_functional, (string) $landingCost->vat_amount_functional, 2);
            $docTotal = bcadd((string) $landingCost->amount, (string) $landingCost->vat_amount, 2);
            $rate    = $fx->rateOn($landingCost->company_id, $costCurrency, (string) $paidDate);
            $settled = $fx->toFunctional($docTotal, $rate);

            $lines = [
                ['chart_of_account_id' => $otherCreditors->id, 'debit' => $booked, 'credit' => '0', 'description' => 'Landing Cost payment '.$landingCost->landing_cost_code],
                ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => '0', 'credit' => $settled, 'description' => 'Landing Cost payment '.$landingCost->landing_cost_code],
            ];
            if ($fxLine = $fx->fxDifferenceLine($landingCost->company_id, $booked, $settled, 'credit', 'landing cost '.$landingCost->landing_cost_code)) {
                $lines[] = $fxLine;
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $landingCost->company_id,
                entryDate: $paidDate,
                lines: $lines,
                sourceType: 'landing_cost_payment',
                sourceId: $landingCost->id,
                narration: "Landing Cost payment {$landingCost->landing_cost_code} — {$landingCost->partner->name_en}",
                actor: $actor,
            );

            $landingCost->update([
                'status'                   => LandingCost::STATUS_PAID,
                'payment_journal_entry_id' => $journalEntry->id,
                'paid_bank_account_id'     => $bankAccount->id,
                'paid_date'                => $paidDate,
                'updated_by'               => $actor->id,
            ]);

            AuditLog::record($actor, 'landing_cost.paid', $landingCost, $before, $landingCost->fresh()->toArray());

            return $landingCost->fresh(['partner', 'chartOfAccount', 'paidBankAccount', 'paymentJournalEntry.lines']);
        });
    }

    /**
     * M-32: undoes pay() — reverses the payment journal entry (a real
     * reversal, never a delete) and puts the cost back to 'posted'.
     * Blocked if the payment's accounting period is closed.
     */
    public function unpay(LandingCost $landingCost, User $actor): LandingCost
    {
        if ($landingCost->status !== LandingCost::STATUS_PAID) {
            throw new \DomainException('Only a paid Landing Cost can have its payment undone.');
        }
        if ($this->fiscalYearService->isPeriodClosed($landingCost->company_id, (string) $landingCost->paid_date)) {
            throw new \DomainException('The accounting period of this payment is closed — it cannot be undone.');
        }

        return DB::transaction(function () use ($landingCost, $actor) {
            $before = $landingCost->toArray();

            if ($landingCost->payment_journal_entry_id) {
                $entry = JournalEntry::findOrFail($landingCost->payment_journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Payment undone — '.$landingCost->landing_cost_code);
            }

            $landingCost->update([
                'status'                   => LandingCost::STATUS_POSTED,
                'payment_journal_entry_id' => null,
                'paid_bank_account_id'     => null,
                'paid_date'                => null,
                'updated_by'               => $actor->id,
            ]);

            AuditLog::record($actor, 'landing_cost.unpaid', $landingCost, $before, $landingCost->fresh()->toArray());

            return $landingCost->fresh();
        });
    }

    /**
     * Undoes attach() — called by GoodsReceiptNoteService::setToDraft()
     * when a posted GRN carrying this Landing Cost is reopened. Only
     * the ATTACHMENT is undone (goods_receipt_note_id, attached_at,
     * the LandingCostAllocation rows) — the liability itself (status,
     * journal_entry_id) is untouched, since the GRN's own journal
     * entry reversal (which the caller already did) is what undoes
     * the GL side of this. Once released, the Landing Cost is
     * eligible for attachment again — including back onto the very
     * same GRN when it's re-posted, via its still-intact
     * GrnLandingCostSelection row (see that model's header).
     *
     * Caller is responsible for the DB transaction — this method does
     * not open its own, same convention as attach().
     */
    public function release(LandingCost $landingCost, User $actor): void
    {
        if (! $landingCost->isAttached()) {
            return;
        }

        $before = $landingCost->toArray();

        LandingCostAllocation::where('landing_cost_id', $landingCost->id)->delete();

        $landingCost->update([
            'goods_receipt_note_id' => null,
            'attached_at'           => null,
            'updated_by'            => $actor->id,
        ]);

        AuditLog::record($actor, 'landing_cost.released', $landingCost, $before, ['goods_receipt_note_id' => null]);
    }

    // ── Attach — called by GoodsReceiptNoteService::post() only ────

    /**
     * Computes this Landing Cost's split across the given GRN lines,
     * creates the LandingCostAllocation rows, and marks the record
     * permanently used. Returns [chart_of_account_id => allocated
     * amount] buckets — one entry per distinct inventory/fixed-asset
     * account touched — for the caller to fold into its own journal
     * entry, plus the Landing Cost's own clearing account total so
     * the caller can add the offsetting credit line.
     *
     * $manualAmounts is only read when allocation_base = 'manual':
     * [goods_receipt_note_line_id => amount], must sum to exactly
     * this Landing Cost's own amount.
     *
     * Caller (GoodsReceiptNoteService) is responsible for the
     * DB transaction — this method does not open its own.
     */
    public function attach(
        LandingCost $landingCost,
        \Illuminate\Support\Collection $grnLines,
        string $allocationBase,
        \App\Models\GoodsReceiptNote $grn,
        User $actor,
        ?array $manualAmounts = null,
    ): array {
        if (! $landingCost->isEligibleForAttachment()) {
            throw new \DomainException("Landing Cost {$landingCost->landing_cost_code} is not eligible for attachment — it may already be used, still a draft, or cancelled.");
        }
        if ((int) $landingCost->company_id !== (int) $grn->company_id) {
            throw new \DomainException('This Landing Cost does not belong to this company.');
        }

        $perLineAmounts = $this->computeAllocations($landingCost, $grnLines, $allocationBase, $manualAmounts);

        $accountBuckets = [];
        foreach ($perLineAmounts as $grnLineId => $amount) {
            $line = $grnLines->firstWhere('id', $grnLineId);
            $accountId = $line->product && $line->product->isFixedAsset()
                ? $line->product->fixed_asset_account_id
                : $line->product->inventory_account_id;

            if (! $accountId) {
                throw new \DomainException("Product {$line->product->code} has no inventory/fixed-asset account assigned — cannot allocate Landing Cost.");
            }

            $accountBuckets[$accountId] = bcadd($accountBuckets[$accountId] ?? '0', $amount, 2);

            LandingCostAllocation::create([
                'company_id'                 => $grn->company_id,
                'landing_cost_id'            => $landingCost->id,
                'goods_receipt_note_line_id' => $grnLineId,
                'allocation_base'            => $allocationBase,
                'allocated_amount'           => $amount,
            ]);
        }

        $landingCost->update([
            'goods_receipt_note_id' => $grn->id,
            'attached_at'           => now(),
            'updated_by'            => $actor->id,
        ]);

        AuditLog::record($actor, 'landing_cost.attached', $landingCost, null, ['goods_receipt_note_id' => $grn->id]);

        return [
            'inventory_buckets'       => $accountBuckets,          // chart_of_account_id => amount, to Dr
            'clearing_account_id'     => $landingCost->chart_of_account_id,
            'clearing_amount'         => (string) $landingCost->amount_functional, // to Cr, in full (VAT is NOT part of it — M-32)
        ];
    }

    /**
     * The actual split math. Returns [goods_receipt_note_line_id =>
     * allocated amount], always summing to EXACTLY landing_cost.amount
     * — the last line in iteration order absorbs the rounding
     * residue (see class header).
     */
    private function computeAllocations(
        LandingCost $landingCost,
        \Illuminate\Support\Collection $grnLines,
        string $base,
        ?array $manualAmounts,
    ): array {
        if ($grnLines->isEmpty()) {
            throw new \DomainException('Cannot allocate a Landing Cost to a Goods Receipt Note with no lines.');
        }

        $total = (string) $landingCost->amount_functional;

        if ($base === LandingCostAllocation::BASE_MANUAL) {
            if (! $manualAmounts) {
                throw new \DomainException('Manual allocation requires an amount per line.');
            }
            $sum = '0';
            foreach ($manualAmounts as $amount) {
                $sum = bcadd($sum, (string) $amount, 2);
            }
            if (bccomp($sum, $total, 2) !== 0) {
                throw new \DomainException("Manual allocation lines sum to {$sum}, which does not match the Landing Cost's own amount of {$total}.");
            }
            return array_map(fn ($v) => (string) $v, $manualAmounts);
        }

        $weights = [];
        foreach ($grnLines as $line) {
            $weights[$line->id] = match ($base) {
                LandingCostAllocation::BASE_VALUE    => (string) $line->line_total,
                LandingCostAllocation::BASE_QUANTITY => (string) $line->quantity,
                LandingCostAllocation::BASE_WEIGHT   => $this->lineWeight($line),
                default => throw new \DomainException("Unknown allocation base: {$base}"),
            };
        }

        $weightTotal = array_reduce($weights, fn ($carry, $w) => bcadd($carry, $w, 4), '0');
        if (bccomp($weightTotal, '0', 4) <= 0) {
            throw new \DomainException("Every line has a zero {$base} — cannot allocate proportionally on this base. Pick Manual instead.");
        }

        $result = [];
        $allocated = '0';
        $lineIds = array_keys($weights);
        $lastLineId = end($lineIds);

        foreach ($weights as $grnLineId => $weight) {
            if ($grnLineId === $lastLineId) {
                $share = bcsub($total, $allocated, 2);
            } else {
                $share = bcdiv(bcmul($total, $weight, 4), $weightTotal, 2);
                $allocated = bcadd($allocated, $share, 2);
            }
            $result[$grnLineId] = $share;
        }

        return $result;
    }

    private function lineWeight(GoodsReceiptNoteLine $line): string
    {
        $product = $line->product;
        if (! $product || bccomp((string) ($product->weight ?? '0'), '0', 4) <= 0) {
            throw new \DomainException(
                "Product {$product?->code} has no Weight set — cannot allocate by Weight. Pick a different base or set the product's Weight first."
            );
        }

        return bcmul((string) $product->weight, (string) $line->quantity, 4);
    }

    private function generateCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\LandingCost::class, 'landing_cost_code', 'LC', $date);
    }
}
