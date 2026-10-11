<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerBalanceService
//
//  Read-only aggregation for the Customer Balances screen (Accounting
//  → Customers Receivables → Customer Balances, per Mahmoud,
//  September 2026): the currency-tabbed summary + customer list, the
//  per-customer Invoices Report, and the Customer Statement. Nothing
//  in this service writes anything — it only reads CustomerInvoice /
//  CustomerPayment / CreditNote / CustomerDebitNote rows that other
//  services already maintain.
//
//  CURRENCY HANDLING — CustomerInvoice's amount_paid / amount_credited
//  / amount_debited / amount_wht_deducted / amount_outstanding are
//  ALWAYS functional-currency, maintained consistently that way by
//  CustomerInvoiceService / CustomerPaymentService / CreditNoteService
//  / CustomerDebitNoteService (every increment/decrement uses the
//  invoice's OWN exchange_rate). Native-currency equivalents are
//  therefore always recoverable exactly via functional ÷
//  invoice->exchange_rate — see nativeOutstanding() below. A
//  currency-specific tab (EGP/EUR/USD) shows amounts converted back
//  to that invoice's own native currency; the Main Currency tab shows
//  the functional figures directly, no conversion needed.
//
//  TEN-002 fix (Audit, 8 Sep 2026) — every query below now filters
//  explicitly by company_id instead of relying on CompanyScope alone.
//  CompanyScope only applies inside an authenticated web request; a
//  scheduled report, queued job, or artisan command bypasses it
//  entirely and would otherwise mix every company's data together.
//  Same explicit pattern already used correctly by
//  App\Services\FinancialInstitutions\InvoiceAgingService.
//
//  AUDIT H11 (Sept 2026) — the customer statement now opens with a
//  "Balance brought forward" row (everything up to the day before the
//  start date), so its closing balance is right for any date range.
//
//  AUDIT M9 (Oct 2026) — Customer Credit Balances. A Credit Note
//  worth more than its invoice still owed puts the surplus in 235
//  (not in Accounts Receivable), exactly like a down payment: the
//  statement shows only the part that reduced receivables, and the
//  surplus appears under "down payments" until it is applied to an
//  invoice (a "Credit applied" row on the statement) or refunded.
//
//  @layer    Service
//  @depends  CustomerInvoice, CustomerPayment, CreditNote, CustomerDebitNote, Partner, CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Models\CreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerCreditApplication;
use App\Models\CustomerCreditBalance;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Partner;
use App\Services\CurrencyService;
use Illuminate\Support\Collection;

class CustomerBalanceService
{
    public function __construct(private CurrencyService $currencyService) {}

    /**
     * The tabs shown along the top of the page — every active
     * currency for this company, in the same order the rest of the
     * app already uses (functional currency first), each carrying
     * its own headline total. 'main' is always appended last —
     * synthetic, not a real currency row — for the Main Currency tab.
     */
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
            'id'    => null, // 'main' — the synthetic tab
            'code'  => 'MAIN',
            'label' => "Main Currency ({$functionalCode})",
            'total' => $this->currencyHeadlineTotal($companyId, null),
        ];

        return $tabs;
    }

    /** Unfiltered net-balance total for one currency tab's headline card. */
    private function currencyHeadlineTotal(int $companyId, ?int $currencyId): string
    {
        $query = CustomerInvoice::where('company_id', $companyId)->where('status', 'posted');

        if ($currencyId) {
            $invoices = $query->where('currency_id', $currencyId)->get(['amount_outstanding', 'exchange_rate']);
            return $invoices->reduce(fn ($sum, $inv) => bcadd($sum, $this->nativeAmount($inv->amount_outstanding, $inv->exchange_rate), 2), '0');
        }

        return (string) $query->sum('amount_outstanding');
    }

    /**
     * The customer table rows for one tab. $duesFilter: 'all' |
     * 'coming' | 'past_due' — evaluated against each invoice's
     * (possibly adjusted) due_date vs. today, matching the same
     * definition the Invoices Report's Status badge uses.
     */
    public function customersTable(int $companyId, ?int $currencyId, string $duesFilter = 'all', ?string $search = null): array
    {
        $query = CustomerInvoice::where('company_id', $companyId)
            ->where('status', 'posted')
            ->with('partner:id,code,name_en,name_ar')
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        if ($search) {
            $query->whereHas('partner', fn ($q) => $q->where('name_en', 'like', "%{$search}%")->orWhere('name_ar', 'like', "%{$search}%"));
        }

        $invoices = $query->get(['id', 'partner_id', 'currency_id', 'exchange_rate', 'invoice_value', 'invoice_value_functional', 'amount_outstanding', 'due_date']);
        $today = now()->toDateString();

        $rows = [];
        foreach ($invoices->groupBy('partner_id') as $partnerId => $group) {
            $invoicesTotal = '0';
            $netBalance = '0';
            $matchedAny = false;

            foreach ($group as $inv) {
                $outstanding = (string) $inv->amount_outstanding;
                $isOpen = bccomp($outstanding, '0', 2) > 0;
                $isPastDue = $isOpen && $inv->due_date && $inv->due_date->toDateString() < $today;
                $isComingDue = $isOpen && ! $isPastDue;

                if ($duesFilter === 'past_due' && ! $isPastDue) continue;
                if ($duesFilter === 'coming' && ! $isComingDue) continue;

                $matchedAny = true;

                if ($currencyId) {
                    $invoicesTotal = bcadd($invoicesTotal, (string) $inv->invoice_value, 2);
                    $netBalance    = bcadd($netBalance, $this->nativeAmount($outstanding, $inv->exchange_rate), 2);
                } else {
                    $invoicesTotal = bcadd($invoicesTotal, (string) $inv->invoice_value_functional, 2);
                    $netBalance    = bcadd($netBalance, $outstanding, 2);
                }
            }

            if (! $matchedAny) continue;

            $partner = $group->first()->partner;
            $rows[] = [
                'partner_id'    => $partnerId,
                'name'          => $partner?->name_en,
                'code'          => $partner?->code,
                'currency_code' => $currencyId ? $group->first()->currency?->code : null,
                'invoices'      => $invoicesTotal,
                'down_payments' => $this->downPaymentsFor($companyId, $partnerId, $currencyId),
                'net_balance'   => $netBalance,
            ];
        }

        usort($rows, fn ($a, $b) => bccomp($b['net_balance'], $a['net_balance'], 2));

        return $rows;
    }

    /** Unapplied down payment credit this partner is currently holding (Advances from Customers, 235). */
    private function downPaymentsFor(int $companyId, int $partnerId, ?int $currencyId): string
    {
        $payments = CustomerPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->when($currencyId, fn ($q) => $q->where('receive_currency_id', $currencyId))
            ->get(['unallocated_amount', 'exchange_rate']);

        // Audit M9 — plus any open Customer Credit Balance (also held in 235).
        $credits = CustomerCreditBalance::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', CustomerCreditBalance::STATUS_OPEN)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->get(['remaining_amount', 'exchange_rate']);

        if ($currencyId) {
            $total = $payments->reduce(fn ($sum, $p) => bcadd($sum, $this->nativeAmount((string) $p->unallocated_amount, (string) $p->exchange_rate), 2), '0');

            return $credits->reduce(fn ($sum, $c) => bcadd($sum, $this->nativeAmount((string) $c->remaining_amount, (string) $c->exchange_rate), 2), $total);
        }

        return bcadd((string) $payments->sum('unallocated_amount'), (string) $credits->sum('remaining_amount'), 2);
    }

    /**
     * Every posted, open invoice for one customer in one currency —
     * the Invoices Report table. Aging is calculated against due_date
     * (which may have been moved by "Adjust Due Date" — that's the
     * point; original_due_date is preserved separately for a future
     * collection-pattern report, not used for today's Aging figure).
     */
    public function invoicesReport(int $companyId, int $partnerId, int $currencyId): array
    {
        $invoices = CustomerInvoice::where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('partner_id', $partnerId)
            ->where('currency_id', $currencyId)
            ->with('salesOrder.project:id,name_en,name_ar')
            ->orderByDesc('invoice_date')
            ->get();

        $today = now()->toDateString();

        return $invoices->map(function (CustomerInvoice $inv) use ($today) {
            $rate = (string) $inv->exchange_rate;
            $outstanding = (string) $inv->amount_outstanding;
            $isOpen = bccomp($outstanding, '0', 2) > 0;
            $isPastDue = $isOpen && $inv->due_date && $inv->due_date->toDateString() < $today;

            $paidAny = bccomp((string) $inv->amount_paid, '0', 2) > 0
                || bccomp((string) $inv->amount_wht_deducted, '0', 2) > 0
                || bccomp((string) $inv->amount_credited, '0', 2) > 0;

            if (! $isOpen) {
                $status = 'collected';
            } elseif ($paidAny) {
                $status = 'partially_collected';
            } else {
                $status = 'not_collected';
            }

            // Aging (per Mahmoud, September 2026 — simplified from the
            // due-date-based version this replaced): Collected → no
            // aging at all (null, shown as a dash). Anything else →
            // Today minus Invoice Date, full stop — NOT due date.
            $agingDays = $status === 'collected'
                ? null
                : max(0, (int) floor((strtotime($today) - strtotime($inv->invoice_date->toDateString())) / 86400));

            return [
                'id'                 => $inv->id,
                'project_name'       => $inv->salesOrder?->project?->name_en,
                'invoice_date'       => $inv->invoice_date?->toDateString(),
                'invoice_code'       => $inv->invoice_code,
                'invoice_amount'     => (string) $inv->subtotal_amount,
                'withhold_amount'    => (string) $inv->amount_wht_deducted > 0 ? $this->nativeAmount((string) $inv->amount_wht_deducted, $rate) : '0',
                'vat_amount'         => (string) $inv->vat_amount,
                'total_deductions'   => $this->nativeAmount(bcadd((string) $inv->amount_wht_deducted, (string) $inv->amount_credited, 2), $rate),
                'total_collections'  => $this->nativeAmount((string) $inv->amount_paid, $rate),
                'original_due_date'  => $inv->original_due_date?->toDateString(),
                'due_date'           => $inv->due_date?->toDateString(),
                'net_balance'        => $this->nativeAmount($outstanding, $rate),
                'status'             => $status,
                'is_past_due'        => $isPastDue,
                'aging_days'         => $agingDays,
            ];
        })->all();
    }

    /**
     * Customer Statement — every AR-affecting document for one
     * customer within a date range, in date order, with a running
     * End Balance. $currencyId = null means the Main Currency tab:
     * every invoice/collection regardless of native currency, all in
     * functional terms. A real currencyId restricts to that currency
     * only, native amounts.
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

        // AR is an asset — Invoice / Debit Note increase it (debit),
        // Collection / Credit Note reduce it (credit).
        $balance = $opening;
        $rows = [[
            'n' => 1, 'date' => $startDate, 'type' => 'Opening Balance', 'doc_no' => '',
            'debit' => '0.00', 'credit' => '0.00', 'comment' => 'Balance brought forward',
            'fx_gain_loss' => null, 'end_balance' => $opening, 'is_opening' => true,
        ]];
        $n = 2;
        foreach ($sorted as $m) {
            $balance = bcadd(bcsub($balance, $m['credit'], 2), $m['debit'], 2);
            $rows[] = array_merge($m, ['n' => $n++, 'end_balance' => $balance, 'is_opening' => false]);
        }

        return $rows;
    }

    /**
     * Every customer-balance movement between $from (null = from the very
     * first document) and $to, inclusive — one row per document, not
     * yet sorted. Shared by the statement body and its opening
     * balance so both follow exactly the same rules.
     */
    private function statementMovements(int $companyId, int $partnerId, ?int $currencyId, ?string $from, string $to): Collection
    {
        $movements = collect();

        $invoiceQuery = CustomerInvoice::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('invoice_date', '>=', $from))->where('invoice_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        foreach ($invoiceQuery->get() as $inv) {
            $amount = $currencyId ? (string) $inv->invoice_value : (string) $inv->invoice_value_functional;
            $movements->push([
                'date' => $inv->invoice_date->toDateString(), 'type' => 'Invoice', 'doc_no' => $inv->invoice_code,
                'debit' => $amount, 'credit' => '0', 'comment' => null, 'fx_gain_loss' => null,
            ]);
        }

        $paymentQuery = CustomerPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('payment_date', '>=', $from))->where('payment_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('receive_currency_id', $currencyId));

        foreach ($paymentQuery->with('allocations')->get() as $pmt) {
            $collected = $pmt->allocations->reduce(fn ($sum, $a) => bcadd($sum, bcadd((string) $a->allocated_amount, (string) $a->wht_amount_functional, 2), 2), '0');
            if (bccomp($collected, '0', 2) <= 0) continue;

            $amount = $currencyId ? bcdiv($collected, (string) ($pmt->exchange_rate ?: 1), 2) : $collected;
            $movements->push([
                'date' => $pmt->payment_date->toDateString(), 'type' => 'Collection', 'doc_no' => $pmt->payment_code,
                'debit' => '0', 'credit' => $amount, 'comment' => 'Collected Amount',
                // FX Gain/Loss is only meaningful on the Main Currency tab —
                // it's already expressed in functional terms and doesn't
                // translate into "how many EGP" on a native-currency tab
                // the way the other columns do. Per Mahmoud, September
                // 2026: shown here purely for visibility, it does NOT feed
                // into end_balance below — the AR clearing (the row above)
                // already fully accounts for the customer's real position.
                'fx_gain_loss' => $currencyId ? null : (string) $pmt->fx_gain_loss_functional,
            ]);
        }

        $creditNoteQuery = CreditNote::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('credit_date', '>=', $from))->where('credit_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        foreach ($creditNoteQuery->get() as $cn) {
            // Audit M9 — only the part that reduced what the invoice owed;
            // any surplus is a customer credit balance, shown with the down payments.
            $surplus = (string) $cn->credit_balance_functional;
            $amount  = $currencyId
                ? bcsub((string) $cn->credit_value, $this->nativeAmount($surplus, (string) $cn->exchange_rate), 2)
                : bcsub((string) $cn->credit_value_functional, $surplus, 2);
            if (bccomp($amount, '0', 2) <= 0) continue;
            $movements->push([
                'date' => $cn->credit_date->toDateString(), 'type' => 'Credit Note', 'doc_no' => $cn->credit_note_code,
                'debit' => '0', 'credit' => $amount, 'comment' => bccomp($surplus, '0', 2) > 0 ? 'Surplus held as customer credit' : null, 'fx_gain_loss' => null,
            ]);
        }

        // Audit M9 — a customer credit balance used against an invoice
        // (Dr 235 / Cr receivable): reduces what the customer owes.
        $applications = CustomerCreditApplication::where('company_id', $companyId)
            ->where('status', CustomerCreditApplication::STATUS_APPLIED)
            ->whereHas('balance', fn ($q) => $q->where('partner_id', $partnerId))
            ->when($from, fn ($q) => $q->where('application_date', '>=', $from))->where('application_date', '<=', $to)
            ->with('customerInvoice:id,invoice_code,currency_id,exchange_rate')
            ->get();

        foreach ($applications as $app) {
            $inv = $app->customerInvoice;
            if ($currencyId && (int) $inv?->currency_id !== (int) $currencyId) continue;
            $amount = $currencyId ? $this->nativeAmount((string) $app->amount, (string) $inv->exchange_rate) : (string) $app->amount;
            $movements->push([
                'date' => $app->application_date->toDateString(), 'type' => 'Credit applied', 'doc_no' => $inv?->invoice_code,
                'debit' => '0', 'credit' => $amount, 'comment' => 'Customer credit balance applied', 'fx_gain_loss' => null,
            ]);
        }

        $debitNoteQuery = CustomerDebitNote::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'posted')
            ->when($from, fn ($q) => $q->where('debit_date', '>=', $from))->where('debit_date', '<=', $to)
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId));

        foreach ($debitNoteQuery->get() as $dn) {
            $amount = $currencyId ? (string) $dn->debit_value : (string) $dn->debit_value_functional;
            $movements->push([
                'date' => $dn->debit_date->toDateString(), 'type' => 'Debit Note', 'doc_no' => $dn->debit_note_code,
                'debit' => $amount, 'credit' => '0', 'comment' => null, 'fx_gain_loss' => null,
            ]);
        }


        return $movements;
    }

    /** Net effect of a set of movements, signed the same way as the running End Balance. */
    private function netStatementMovement(Collection $movements): string
    {
        $net = '0';
        foreach ($movements as $m) {
            $net = bcadd(bcsub($net, $m['credit'], 2), $m['debit'], 2);
        }

        return $net;
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** functional amount → this invoice/payment's own native-currency equivalent. Exact, since every running total was built up the same way (see class header). */
    private function nativeAmount(string $functionalAmount, string $exchangeRate): string
    {
        $rate = bccomp($exchangeRate, '0', 6) > 0 ? $exchangeRate : '1';
        return bcdiv($functionalAmount, $rate, 2);
    }
}
