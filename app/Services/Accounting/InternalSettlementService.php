<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlementService
//
//  Internal Settlement (per Mahmoud, September 2026) — nets a
//  partner's Accounts Receivable against their own Accounts Payable,
//  same partner acting as both customer and supplier, ONE currency
//  only (no cross-currency netting — Mahmoud's explicit call).
//
//  ONE ATOMIC ACTION — record(). Same shape as Customer Collection /
//  Supplier Payment: no draft-then-post two-step, posts immediately.
//  'draft' is only ever reached later via setToDraft().
//
//  THE JOURNAL ENTRY — Dr Accounts Payable / Cr Accounts Receivable,
//  both resolved for the settlement's own currency. Each side clears
//  its own documents (customer invoices / supplier bills) at THOSE
//  documents' own booked exchange_rate — same principle already
//  proven in CustomerPaymentService/SupplierPaymentService. Since the
//  two sides can be booked at slightly different historical rates
//  even within "the same currency" (foreign-currency documents raised
//  on different dates), any mismatch between the AP-clearing total
//  and the AR-clearing total posts as Realized FX Gain (431) / Loss
//  (571) — the exact same mechanism already built for those two
//  services, applied here for the same underlying reason.
//
//  VALIDATION — both allocation tables (customer invoices, supplier
//  bills) must sum EXACTLY to the settlement's own `amount`, in that
//  shared native currency — enforced here, not just in the form.
//
//  @layer    Service
//  @depends  InternalSettlement, InternalSettlementCustomerAllocation,
//            InternalSettlementSupplierAllocation, CustomerInvoice,
//            SupplierBill, Partner, ChartOfAccount, CompanyActiveCurrency,
//            JournalEntryService, FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\InternalSettlement;
use App\Models\InternalSettlementCustomerAllocation;
use App\Models\InternalSettlementSupplierAllocation;
use App\Models\JournalEntry;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InternalSettlementService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return InternalSettlement::with(['partner:id,name_en,name_ar', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('settlement_code', 'like', "%{$s}%"))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): InternalSettlement
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $customerAllocations = $data['customer_allocations'] ?? [];
            $supplierAllocations = $data['supplier_allocations'] ?? [];
            unset($data['customer_allocations'], $data['supplier_allocations']);

            $data['company_id']      = $companyId;
            $data['created_by']      = $actor->id;
            $data['settlement_code'] = $this->generateSettlementCode($companyId, $data['settlement_date'] ?? null);
            $data['status']          = InternalSettlement::STATUS_POSTED;
            $data['posted_by']       = $actor->id;
            $data['posted_at']       = now();

            $settlement = InternalSettlement::create($data);

            $this->postSettlementTransaction($companyId, $settlement, $customerAllocations, $supplierAllocations, $actor);

            AuditLog::record($actor, 'internal_settlement.recorded', $settlement, null, $settlement->fresh()->toArray());

            return $settlement->fresh(['partner', 'currency', 'customerAllocations.customerInvoice', 'supplierAllocations.supplierBill', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) settlement and immediately re-posts it —
     * mirroring record() exactly, just on an existing row. Only ever
     * callable on a draft (setToDraft() is the only way a posted
     * settlement becomes one again).
     */
    public function update(int $companyId, InternalSettlement $settlement, array $data, User $actor): InternalSettlement
    {
        if ($settlement->status !== InternalSettlement::STATUS_DRAFT) {
            throw new \DomainException('Only a draft settlement can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($companyId, $settlement, $data, $actor) {
            $before = $settlement->toArray();

            $customerAllocations = $data['customer_allocations'] ?? [];
            $supplierAllocations = $data['supplier_allocations'] ?? [];
            unset($data['customer_allocations'], $data['supplier_allocations']);

            $data['status']     = InternalSettlement::STATUS_POSTED;
            $data['posted_by']  = $actor->id;
            $data['posted_at']  = now();
            $data['updated_by'] = $actor->id;

            $settlement->update($data);

            $this->postSettlementTransaction($companyId, $settlement, $customerAllocations, $supplierAllocations, $actor);

            AuditLog::record($actor, 'internal_settlement.reposted', $settlement, $before, $settlement->fresh()->toArray());

            return $settlement->fresh(['partner', 'currency', 'customerAllocations.customerInvoice', 'supplierAllocations.supplierBill', 'journalEntry.lines']);
        });
    }

    /**
     * The actual posting mechanics — validates both allocation tables
     * sum exactly to settlement->amount, clears each document at its
     * own booked rate, and posts Dr AP / Cr AR (+ FX Gain/Loss for any
     * mismatch). Shared between record() and update().
     */
    private function postSettlementTransaction(int $companyId, InternalSettlement $settlement, array $customerAllocations, array $supplierAllocations, User $actor): void
    {
        if (empty($customerAllocations) || empty($supplierAllocations)) {
            throw new \DomainException('An Internal Settlement needs at least one Customer Invoice AND at least one Supplier Bill allocated.');
        }

        $customerNativeTotal = array_reduce($customerAllocations, fn ($sum, $l) => bcadd($sum, (string) $l['allocated_amount'], 2), '0');
        $supplierNativeTotal = array_reduce($supplierAllocations, fn ($sum, $l) => bcadd($sum, (string) $l['allocated_amount'], 2), '0');

        if (bccomp($customerNativeTotal, (string) $settlement->amount, 2) !== 0) {
            throw new \DomainException("Customer Invoice allocations ({$customerNativeTotal}) must sum exactly to the settlement amount ({$settlement->amount}).");
        }
        if (bccomp($supplierNativeTotal, (string) $settlement->amount, 2) !== 0) {
            throw new \DomainException("Supplier Bill allocations ({$supplierNativeTotal}) must sum exactly to the settlement amount ({$settlement->amount}).");
        }

        $arClearTotal = '0';
        $createdCustomerAllocationIds = [];

        foreach ($customerAllocations as $line) {
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($line['customer_invoice_id']);

            if ((int) $invoice->currency_id !== (int) $settlement->currency_id) {
                throw new \DomainException("Invoice {$invoice->invoice_code} is not in this settlement's currency — Internal Settlement only nets within the same currency.");
            }
            if ((int) $invoice->partner_id !== (int) $settlement->partner_id) {
                throw new \DomainException("Invoice {$invoice->invoice_code} does not belong to this settlement's partner.");
            }

            $native = (string) $line['allocated_amount'];
            $functional = bcmul($native, (string) $invoice->exchange_rate, 2);

            if (bccomp($functional, (string) $invoice->amount_outstanding, 2) > 0) {
                throw new \DomainException("Allocation exceeds what's still outstanding on Invoice {$invoice->invoice_code} ({$invoice->amount_outstanding}).");
            }

            $allocation = InternalSettlementCustomerAllocation::create([
                'company_id'                    => $companyId,
                'internal_settlement_id'        => $settlement->id,
                'customer_invoice_id'           => $invoice->id,
                'allocated_amount'              => $native,
                'allocated_amount_functional'   => $functional,
                'created_by'                    => $actor->id,
            ]);
            $createdCustomerAllocationIds[] = $allocation->id;

            $invoice->update([
                'amount_paid'         => bcadd((string) $invoice->amount_paid, $functional, 2),
                'amount_outstanding'  => bcsub((string) $invoice->amount_outstanding, $functional, 2),
            ]);

            $arClearTotal = bcadd($arClearTotal, $functional, 2);
        }

        $apClearTotal = '0';
        $createdSupplierAllocationIds = [];

        foreach ($supplierAllocations as $line) {
            $bill = SupplierBill::lockForUpdate()->findOrFail($line['supplier_bill_id']);

            if ((int) $bill->currency_id !== (int) $settlement->currency_id) {
                throw new \DomainException("Bill {$bill->bill_code} is not in this settlement's currency — Internal Settlement only nets within the same currency.");
            }
            if ((int) $bill->partner_id !== (int) $settlement->partner_id) {
                throw new \DomainException("Bill {$bill->bill_code} does not belong to this settlement's partner.");
            }

            $native = (string) $line['allocated_amount'];
            $functional = bcmul($native, (string) $bill->exchange_rate, 2);

            if (bccomp($functional, (string) $bill->amount_outstanding, 2) > 0) {
                throw new \DomainException("Allocation exceeds what's still outstanding on Bill {$bill->bill_code} ({$bill->amount_outstanding}).");
            }

            $allocation = InternalSettlementSupplierAllocation::create([
                'company_id'                    => $companyId,
                'internal_settlement_id'        => $settlement->id,
                'supplier_bill_id'              => $bill->id,
                'allocated_amount'              => $native,
                'allocated_amount_functional'   => $functional,
                'created_by'                    => $actor->id,
            ]);
            $createdSupplierAllocationIds[] = $allocation->id;

            $bill->update([
                'amount_paid'         => bcadd((string) $bill->amount_paid, $functional, 2),
                'amount_outstanding'  => bcsub((string) $bill->amount_outstanding, $functional, 2),
            ]);

            $apClearTotal = bcadd($apClearTotal, $functional, 2);
        }

        $arAccount = $this->resolveArAccount($companyId, $settlement->currency_id);
        $apAccount = $this->resolveApAccount($companyId, $settlement->currency_id);

        // Positive = gain (AP cleared for more than AR given up),
        // negative = loss — same sign convention as the other two FX
        // fixes. Zero for the vast majority (functional-currency
        // partners, or any pair of documents raised at the same rate).
        $fxDiff = bcsub($apClearTotal, $arClearTotal, 2);

        $lines = [
            ['chart_of_account_id' => $apAccount->id, 'debit' => $apClearTotal, 'credit' => '0', 'description' => 'Internal Settlement '.$settlement->settlement_code],
        ];

        if (bccomp($fxDiff, '0', 2) < 0) {
            $fxLossAccount = $this->resolveRealizedFxLossAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxLossAccount->id, 'debit' => bcmul($fxDiff, '-1', 2), 'credit' => '0', 'description' => 'Realized FX Loss — '.$settlement->settlement_code];
        }

        $lines[] = ['chart_of_account_id' => $arAccount->id, 'debit' => '0', 'credit' => $arClearTotal, 'description' => 'Internal Settlement '.$settlement->settlement_code];

        if (bccomp($fxDiff, '0', 2) > 0) {
            $fxGainAccount = $this->resolveRealizedFxGainAccount($companyId);
            $lines[] = ['chart_of_account_id' => $fxGainAccount->id, 'debit' => '0', 'credit' => $fxDiff, 'description' => 'Realized FX Gain — '.$settlement->settlement_code];
        }

        $journalEntry = $this->journalEntryService->post(
            companyId: $companyId,
            entryDate: $settlement->settlement_date,
            lines: $lines,
            sourceType: 'internal_settlement',
            sourceId: $settlement->id,
            narration: $this->narrationFor($settlement),
            actor: $actor,
        );

        $settlement->update(['journal_entry_id' => $journalEntry->id]);

        if ($createdCustomerAllocationIds) {
            InternalSettlementCustomerAllocation::whereIn('id', $createdCustomerAllocationIds)->update(['internal_settlement_id' => $settlement->id]);
        }
        if ($createdSupplierAllocationIds) {
            InternalSettlementSupplierAllocation::whereIn('id', $createdSupplierAllocationIds)->update(['internal_settlement_id' => $settlement->id]);
        }
    }

    /**
     * Reopens a posted settlement back to draft — the ONLY way a
     * posted InternalSettlement becomes editable again. Automatically
     * reverses everything real it did: its journal entry, every
     * invoice/bill application. Same pattern already proven on
     * Customer Collection / Supplier Payment.
     */
    public function setToDraft(InternalSettlement $settlement, User $actor): InternalSettlement
    {
        if ($settlement->status !== InternalSettlement::STATUS_POSTED) {
            throw new \DomainException('Only a posted settlement can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($settlement->company_id, (string) $settlement->settlement_date)) {
            throw new \DomainException('This settlement\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($settlement, $actor) {
            $before = $settlement->toArray();

            $this->reverseEverything($settlement, $actor, 'Reopened to draft — '.$settlement->settlement_code);

            $settlement->update([
                'status'           => InternalSettlement::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'internal_settlement.set_to_draft', $settlement, $before, $settlement->fresh()->toArray());

            return $settlement->fresh();
        });
    }

    private function reverseEverything(InternalSettlement $settlement, User $actor, string $reversalNarration): void
    {
        foreach ($settlement->customerAllocations()->lockForUpdate()->get() as $allocation) {
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($allocation->customer_invoice_id);
            $amount  = (string) $allocation->allocated_amount_functional;

            $invoice->update([
                'amount_paid'        => bcsub((string) $invoice->amount_paid, $amount, 2),
                'amount_outstanding' => bcadd((string) $invoice->amount_outstanding, $amount, 2),
            ]);

            $allocation->delete();
        }

        foreach ($settlement->supplierAllocations()->lockForUpdate()->get() as $allocation) {
            $bill   = SupplierBill::lockForUpdate()->findOrFail($allocation->supplier_bill_id);
            $amount = (string) $allocation->allocated_amount_functional;

            $bill->update([
                'amount_paid'        => bcsub((string) $bill->amount_paid, $amount, 2),
                'amount_outstanding' => bcadd((string) $bill->amount_outstanding, $amount, 2),
            ]);

            $allocation->delete();
        }

        if ($settlement->journal_entry_id) {
            $entry = JournalEntry::findOrFail($settlement->journal_entry_id);
            $this->journalEntryService->reverse($entry, $actor, $reversalNarration);
        }
    }

    /**
     * Deletes a settlement outright — only on a draft, only reachable
     * via setToDraft() first (which already fully reversed everything
     * real it did). Same "no hard deletes on financial data" reasoning
     * as Customer Collection / Supplier Payment.
     */
    public function delete(InternalSettlement $settlement, User $actor): void
    {
        if ($settlement->status !== InternalSettlement::STATUS_DRAFT) {
            throw new \DomainException('Only a draft settlement can be deleted — use "Set to Draft" first.');
        }
        if ($settlement->journal_entry_id) {
            throw new \DomainException('This settlement still has a journal entry attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($settlement, $actor) {
            $before = $settlement->toArray();
            $settlement->delete();
            AuditLog::record($actor, 'internal_settlement.deleted', $settlement, $before, null);
        });
    }

    public function cancel(InternalSettlement $settlement, User $actor): InternalSettlement
    {
        if ($this->fiscalYearService->isPeriodClosed($settlement->company_id, (string) $settlement->settlement_date)) {
            throw new \DomainException('This settlement\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($settlement, $actor) {
            $before = $settlement->toArray();

            $this->reverseEverything($settlement, $actor, 'Cancelled — '.$settlement->settlement_code);

            $settlement->update([
                'status'     => InternalSettlement::STATUS_CANCELLED,
                'updated_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'internal_settlement.cancelled', $settlement, $before, $settlement->fresh()->toArray());

            return $settlement->fresh();
        });
    }

    // ── Eligibility lookups (JSON endpoints) ─────────────────────

    /**
     * Partners eligible for a NEW Internal Settlement: carry both
     * 'customer' and 'supplier' types, AND have a positive open
     * balance on BOTH sides in at least one shared currency. Returns
     * one row per (partner, currency) pair that qualifies — a partner
     * dealing in two currencies with overlap in both appears twice,
     * once per currency.
     */
    public function eligiblePartnerCurrencyPairs(int $companyId): array
    {
        $arRows = CustomerInvoice::where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->select('partner_id', 'currency_id')
            ->selectRaw('SUM(amount_outstanding) as ar_total')
            ->groupBy('partner_id', 'currency_id')
            ->get()
            ->keyBy(fn ($r) => $r->partner_id.'-'.$r->currency_id);

        $apRows = SupplierBill::where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->select('partner_id', 'currency_id')
            ->selectRaw('SUM(amount_outstanding) as ap_total')
            ->groupBy('partner_id', 'currency_id')
            ->get()
            ->keyBy(fn ($r) => $r->partner_id.'-'.$r->currency_id);

        $pairs = [];
        foreach ($arRows as $key => $arRow) {
            if (! isset($apRows[$key])) continue;

            $partner = \App\Models\Partner::find($arRow->partner_id);
            if (! $partner || ! $partner->hasType('customer') || ! $partner->hasType('supplier')) continue;

            $currency = \App\Models\Currency::find($arRow->currency_id);

            $pairs[] = [
                'partner_id'     => $arRow->partner_id,
                'partner_name'   => $partner->name_en,
                'currency_id'    => $arRow->currency_id,
                'currency_code'  => $currency?->code,
                'ar_total'       => (string) $arRow->ar_total,
                'ap_total'       => (string) $apRows[$key]->ap_total,
                'max_amount'     => bccomp((string) $arRow->ar_total, (string) $apRows[$key]->ap_total, 2) <= 0 ? (string) $arRow->ar_total : (string) $apRows[$key]->ap_total,
            ];
        }

        return $pairs;
    }

    /** Open customer invoices for one partner in one currency — the AR allocation table. */
    public function openCustomerInvoices(int $partnerId, int $currencyId): array
    {
        return CustomerInvoice::open()
            ->where('partner_id', $partnerId)
            ->where('currency_id', $currencyId)
            ->orderByDesc('invoice_date')
            ->get(['id', 'invoice_code', 'invoice_date', 'invoice_value', 'amount_paid', 'amount_outstanding', 'exchange_rate'])
            ->toArray();
    }

    /** Open supplier bills for one partner in one currency — the AP allocation table. */
    public function openSupplierBills(int $partnerId, int $currencyId): array
    {
        return SupplierBill::open()
            ->where('partner_id', $partnerId)
            ->where('currency_id', $currencyId)
            ->orderByDesc('bill_date')
            ->get(['id', 'bill_code', 'bill_date', 'bill_value', 'amount_paid', 'amount_outstanding', 'exchange_rate'])
            ->toArray();
    }

    // ── Account resolvers ─────────────────────────────────────────

    private function resolveArAccount(int $companyId, int $currencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Receivable account ({$code}) is not seeded for this company — cannot post.");
    }

    private function resolveApAccount(int $companyId, int $currencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
    }

    private function resolveRealizedFxGainAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '431')
            ?? throw new \DomainException('Chart of Accounts is missing system account 431 (Realized FX Gain) — check the seeder.');
    }

    private function resolveRealizedFxLossAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '571')
            ?? throw new \DomainException('Chart of Accounts is missing system account 571 (Realized FX Loss) — check the seeder.');
    }

    private function narrationFor(InternalSettlement $settlement): string
    {
        return "Internal Settlement {$settlement->settlement_code} — {$settlement->partner->name_en}";
    }

    private function generateSettlementCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\InternalSettlement::class, 'settlement_code', 'IS', $date);
    }
}
