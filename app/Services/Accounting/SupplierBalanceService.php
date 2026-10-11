<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierBalanceService
//
//  Payable-side mirror of CustomerBalanceService — read-only
//  aggregation for the Supplier Balances screen (Accounting →
//  Suppliers Payables → Supplier Balances, per Mahmoud, September
//  2026): the currency-tabbed summary + supplier list, the
//  per-supplier Invoices Report, and the Supplier Statement.
//
//  ONE REAL DIFFERENCE FROM THE CUSTOMER SIDE — Withholding Tax:
//  Credit WHT is captured ONCE, at Bill posting time
//  (SupplierBill::withholding_tax_amount), not incrementally at
//  payment time the way Debit WHT is on the customer side. So
//  there's no running "amount_wht_deducted" to track here — the
//  bill's own withholding_tax_amount is already a fixed, final
//  figure the moment it posts.
//
//  CURRENCY HANDLING — identical principle to CustomerBalanceService:
//  SupplierBill's amount_paid / amount_credited / amount_debited /
//  amount_outstanding are ALWAYS functional-currency, native
//  equivalents recovered via functional ÷ bill->exchange_rate.
//
//  TEN-002 fix (Audit, 8 Sep 2026) — every query below now filters
//  explicitly by company_id instead of relying on CompanyScope alone.
//  CompanyScope only applies inside an authenticated web request; a
//  scheduled report, queued job, or artisan command bypasses it
//  entirely and would otherwise mix every company's data together.
//  Same explicit pattern already used correctly by
//  App\Services\FinancialInstitutions\InvoiceAgingService.
//
//  AUDIT H11 (Sept 2026) — the supplier statement now opens with a
//  "Balance brought forward" row (everything up to the day before the
//  start date), so its closing balance is right for any date range.
//
//  @layer    Service
//  @depends  SupplierBill, SupplierPayment, SupplierCreditNote, SupplierDebitNote, Partner, CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\SupplierDebitNote;
use App\Models\SupplierPayment;
use App\Services\CurrencyService;
use Illuminate\Support\Collection;

class SupplierBalanceService
{
    public function __construct(private CurrencyService $currencyService) {}

    public function currencyTabs(int $companyId): array
    {
        $currencies = $this->currencyService->activeCurrenciesOrdered($companyId);
        $functionalCurrencyId = $this->currencyService->functionalCurrencyId($companyId);

        $tabs = $currencies->map(fn ($c) => [
            'id'    => $c->id,
            'code'  => $c->code,
            'total' => $this->currencyHeadlineTotal($companyId, $c->id),
        ])->values()->all();

        $functionalCode = $currencies->firstWhere('id', $functionalCurrencyId)?->code ?? '';

        $tabs[] = [
            'id'    => null,
            'code'  => 'MAIN',
            'label' => "Main Currency ({$functionalCode})",
            'total' => $this->currencyHeadlineTotal($companyId, null),
        ];

        return $tabs;
    }

    private function currencyHeadlineTotal(int $companyId, ?int $currencyId): string
    {
        $query = SupplierBill::where('company_id', $companyId)->where('status', 'posted');

        if ($currencyId) {
            $bills = $query->where('currency_id', $currencyId)->get(['amount_outstanding', 'exchange_rate']);
            return $bills->reduce(fn ($sum, $b) => bcadd($sum, $this->nativeAmount($b->amount_outstanding, $b->exchange_rate), 2), '0');
        }

        return (string) $query->sum('amount_outstanding');
    }

    public function suppliersTable(int $companyId, ?int $currencyId, string $duesFilter = 'all', ?string $search = null): array
    {
        $query = SupplierBill::where('company_id', $companyId)
            ->where('status', 'posted')
            ->with('partner:id,code,name_en,name_ar')
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        if ($search) {
            $query->whereHas('partner', fn ($q) => $q->where('name_en', 'like', "%{$search}%")->orWhere('name_ar', 'like', "%{$search}%"));
        }

        $bills = $query->get(['id', 'partner_id', 'currency_id', 'exchange_rate', 'bill_value', 'bill_value_functional', 'amount_outstanding', 'due_date']);
        $today = now()->toDateString();

        $rows = [];
        foreach ($bills->groupBy('partner_id') as $partnerId => $group) {
            $billsTotal = '0';
            $netBalance = '0';
            $matchedAny = false;

            foreach ($group as $bill) {
                $outstanding = (string) $bill->amount_outstanding;
                $isOpen = bccomp($outstanding, '0', 2) > 0;
                $isPastDue = $isOpen && $bill->due_date && $bill->due_date->toDateString() < $today;
                $isComingDue = $isOpen && ! $isPastDue;

                if ($duesFilter === 'past_due' && ! $isPastDue) continue;
                if ($duesFilter === 'coming' && ! $isComingDue) continue;

                $matchedAny = true;

                if ($currencyId) {
                    $billsTotal = bcadd($billsTotal, (string) $bill->bill_value, 2);
                    $netBalance = bcadd($netBalance, $this->nativeAmount($outstanding, $bill->exchange_rate), 2);
                } else {
                    $billsTotal = bcadd($billsTotal, (string) $bill->bill_value_functional, 2);
                    $netBalance = bcadd($netBalance, $outstanding, 2);
                }
            }

            if (! $matchedAny) continue;

            $partner = $group->first()->partner;
            $rows[] = [
                'partner_id'    => $partnerId,
                'name'          => $partner?->name_en,
                'code'          => $partner?->code,
                'currency_code' => $currencyId ? $group->first()->currency?->code : null,
                'bills'         => $billsTotal,
                'down_payments' => $this->downPaymentsFor($companyId, $partnerId, $currencyId),
                'net_balance'   => $netBalance,
            ];
        }

        usort($rows, fn ($a, $b) => bccomp($b['net_balance'], $a['net_balance'], 2));

        return $rows;
    }

    /** Unapplied down payment credit this supplier is currently holding (Advances to Suppliers, 144). */
    private function downPaymentsFor(int $companyId, int $partnerId, ?int $currencyId): string
    {
        $payments = SupplierPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->when($currencyId, fn ($q) => $q->where('pay_currency_id', $currencyId))
            ->get(['unallocated_amount', 'exchange_rate']);

        if ($currencyId) {
            return $payments->reduce(fn ($sum, $p) => bcadd($sum, $this->nativeAmount((string) $p->unallocated_amount, (string) $p->exchange_rate), 2), '0');
        }

        return (string) $payments->sum('unallocated_amount');
    }

    /**
     * Every posted, open bill for one supplier in one currency — the
     * Invoices Report table. Aging: Paid → dash, anything else →
     * Today minus Bill Date (same simplified rule as the customer
     * side, per Mahmoud, September 2026).
     */
    public function invoicesReport(int $companyId, int $partnerId, int $currencyId): array
    {
        $bills = SupplierBill::where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('partner_id', $partnerId)
            ->where('currency_id', $currencyId)
            ->with('purchaseOrder.project:id,name_en,name_ar')
            ->orderByDesc('bill_date')
            ->get();

        $today = now()->toDateString();

        return $bills->map(function (SupplierBill $bill) use ($today) {
            $rate = (string) $bill->exchange_rate;
            $outstanding = (string) $bill->amount_outstanding;
            $isOpen = bccomp($outstanding, '0', 2) > 0;
            $isPastDue = $isOpen && $bill->due_date && $bill->due_date->toDateString() < $today;

            $paidAny = bccomp((string) $bill->amount_paid, '0', 2) > 0
                || bccomp((string) $bill->withholding_tax_amount, '0', 2) > 0
                || bccomp((string) $bill->amount_credited, '0', 2) > 0;

            if (! $isOpen) {
                $status = 'paid';
            } elseif ($paidAny) {
                $status = 'partially_paid';
            } else {
                $status = 'not_paid';
            }

            $agingDays = $status === 'paid'
                ? null
                : max(0, (int) floor((strtotime($today) - strtotime($bill->bill_date->toDateString())) / 86400));

            return [
                'id'                 => $bill->id,
                'project_name'       => $bill->purchaseOrder?->project?->name_en,
                'bill_date'          => $bill->bill_date?->toDateString(),
                'bill_code'          => $bill->bill_code,
                'bill_amount'        => (string) $bill->subtotal_amount,
                'withhold_amount'    => (string) $bill->withholding_tax_amount,
                'vat_amount'         => (string) $bill->vat_amount,
                'total_deductions'   => bcadd((string) $bill->withholding_tax_amount, $this->nativeAmount((string) $bill->amount_credited, $rate), 2),
                'total_payments'     => $this->nativeAmount((string) $bill->amount_paid, $rate),
                'original_due_date'  => $bill->original_due_date?->toDateString(),
                'due_date'           => $bill->due_date?->toDateString(),
                'net_balance'        => $this->nativeAmount($outstanding, $rate),
                'status'             => $status,
                'is_past_due'        => $isPastDue,
                'aging_days'         => $agingDays,
            ];
        })->all();
    }

    /**
     * Supplier Statement — every AP-affecting document for one
     * supplier within a date range, running End Balance. Same
     * currencyId = null → Main Currency convention as the customer
     * side, with an FX Gain/Loss column on Payment rows.
     */
    public function statement(int $companyId, int $partnerId, ?int $currencyId, string $startDate, string $endDate): array
    {
        // Audit H11 (Sept 2026) — the statement used to start at ZERO
        // on the start date, so any range not starting on go-live day
        // lost everything before it and showed a wrong closing
        // balance. It now opens with a "Balance brought forward" row:
        // every movement from the very beginning up to the day BEFORE
        // the start date, worked out with exactly the same rules as
        // the rows below (same documents, same currency tab).
        $dayBefore = \Carbon\Carbon::parse($startDate)->subDay()->toDateString();
        $opening   = $this->netStatementMovement($this->statementMovements($companyId, $partnerId, $currencyId, null, $dayBefore));

        $sorted = $this->statementMovements($companyId, $partnerId, $currencyId, $startDate, $endDate)
            ->sortBy('date')->values();

        if ($sorted->isEmpty() && bccomp($opening, '0', 2) === 0) {
            return [];
        }

        // AP is a liability — a Bill INCREASES what we owe (credit),
        // same as a Credit Note; a Payment or Debit Note REDUCES it
        // (debit). End Balance therefore accumulates credit minus
        // debit — the mirror image of the customer side.
        $balance = $opening;
        $rows = [[
            'n' => 1, 'date' => $startDate, 'type' => 'Opening Balance', 'doc_no' => '',
            'debit' => '0.00', 'credit' => '0.00', 'comment' => 'Balance brought forward',
            'fx_gain_loss' => null, 'end_balance' => $opening, 'is_opening' => true,
        ]];
        $n = 2;
        foreach ($sorted as $m) {
            $balance = bcadd(bcsub($balance, $m['debit'], 2), $m['credit'], 2);
            $rows[] = array_merge($m, ['n' => $n++, 'end_balance' => $balance, 'is_opening' => false]);
        }

        return $rows;
    }

    /**
     * Every supplier-balance movement between $from (null = from the very
     * first document) and $to, inclusive — one row per document, not
     * yet sorted. Shared by the statement body and its opening
     * balance so both follow exactly the same rules.
     */
    private function statementMovements(int $companyId, int $partnerId, ?int $currencyId, ?string $from, string $to): Collection
    {
        $movements = collect();

        $billQuery = SupplierBill::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('bill_date', '>=', $from))->where('bill_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        foreach ($billQuery->get() as $bill) {
            $amount = $currencyId ? (string) $bill->bill_value : (string) $bill->bill_value_functional;
            $movements->push([
                'date' => $bill->bill_date->toDateString(), 'type' => 'Bill', 'doc_no' => $bill->bill_code,
                'debit' => '0', 'credit' => $amount, 'comment' => null, 'fx_gain_loss' => null,
            ]);
        }

        $paymentQuery = SupplierPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('payment_date', '>=', $from))->where('payment_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('pay_currency_id', $currencyId));

        foreach ($paymentQuery->with('allocations')->get() as $pmt) {
            $paid = $pmt->allocations->reduce(fn ($sum, $a) => bcadd($sum, (string) $a->allocated_amount, 2), '0');
            if (bccomp($paid, '0', 2) <= 0) continue;

            $amount = $currencyId ? bcdiv($paid, (string) ($pmt->exchange_rate ?: 1), 2) : $paid;
            $movements->push([
                'date' => $pmt->payment_date->toDateString(), 'type' => 'Payment', 'doc_no' => $pmt->payment_code,
                'debit' => $amount, 'credit' => '0', 'comment' => 'Paid Amount',
                'fx_gain_loss' => $currencyId ? null : (string) $pmt->fx_gain_loss_functional,
            ]);
        }

        $debitNoteQuery = SupplierDebitNote::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('debit_date', '>=', $from))->where('debit_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        // Debit Note DECREASES what we owe (Dr AP) — opposite of the
        // customer side's Debit Note, which increases what they owe.
        // Confirmed against SupplierDebitNoteService::post()'s own
        // journal line: Dr AP / Cr [expense/variance].
        foreach ($debitNoteQuery->get() as $dn) {
            $amount = $currencyId ? (string) $dn->debit_value : (string) $dn->debit_value_functional;
            $movements->push([
                'date' => $dn->debit_date->toDateString(), 'type' => 'Debit Note', 'doc_no' => $dn->debit_note_code,
                'debit' => $amount, 'credit' => '0', 'comment' => null, 'fx_gain_loss' => null,
            ]);
        }

        $creditNoteQuery = SupplierCreditNote::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('credit_date', '>=', $from))->where('credit_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        // Credit Note INCREASES what we owe (Cr AP) — opposite of the
        // customer side's Credit Note, which decreases what they owe.
        // Confirmed against SupplierCreditNoteService::post()'s own
        // journal line: Dr [expense/variance] / Cr AP.
        foreach ($creditNoteQuery->get() as $cn) {
            $amount = $currencyId ? (string) $cn->credit_value : (string) $cn->credit_value_functional;
            $movements->push([
                'date' => $cn->credit_date->toDateString(), 'type' => 'Credit Note', 'doc_no' => $cn->credit_note_code,
                'debit' => '0', 'credit' => $amount, 'comment' => null, 'fx_gain_loss' => null,
            ]);
        }


        return $movements;
    }

    /** Net effect of a set of movements, signed the same way as the running End Balance. */
    private function netStatementMovement(Collection $movements): string
    {
        $net = '0';
        foreach ($movements as $m) {
            $net = bcadd(bcsub($net, $m['debit'], 2), $m['credit'], 2);
        }

        return $net;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function nativeAmount(string $functionalAmount, string $exchangeRate): string
    {
        $rate = bccomp($exchangeRate, '0', 6) > 0 ? $exchangeRate : '1';
        return bcdiv($functionalAmount, $rate, 2);
    }
}
