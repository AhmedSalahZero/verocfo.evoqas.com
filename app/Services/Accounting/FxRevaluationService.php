<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FxRevaluationService
//
//  Audit H12 (Sept 2026) — month-end foreign-currency revaluation
//  (IAS 21.23: foreign-currency MONETARY items are translated at the
//  closing rate at each reporting date; the difference goes to profit
//  or loss).
//
//  WHAT IS REVALUED (all as at the chosen month-end date)
//    1. Foreign-currency ACCOUNTS — every account that holds one
//       foreign currency: bank current accounts, safes, time
//       deposits, cash-cover accounts, overdrafts, loans (see
//       ForeignCurrencyLedgerService). Foreign balance = sum of the
//       foreign amounts on its journal lines; book value = its EGP
//       ledger balance.
//    2. Open CUSTOMER INVOICES and SUPPLIER BILLS in a foreign
//       currency (they share AR 133002 / AP 221002 with other
//       currencies, so they are revalued document by document).
//       The amount still open AT THE MONTH-END is rebuilt from today's
//       figure by adding back what was settled after that date:
//       collections / payments, credit and debit notes and internal
//       settlements dated later. A document touched after the date by
//       factoring, reverse factoring or an LC payment is NOT revalued
//       — it is listed as a warning to review by hand, never guessed.
//    3. Open foreign-currency CHEQUES — received (134001) and issued
//       (222001) — at face value; book value is what the cheque was
//       booked at.
//  For each item: revalued = foreign balance × closing rate, and
//  difference = revalued − book value.
//
//  WHAT IS POSTED — two journal entries, in one transaction:
//    • dated the month-end: each item's difference to its account;
//      the total gains to 432 Unrealized FX Gain, the total losses to
//      572 Unrealized FX Loss;
//    • dated the 1st of the next month: the exact reversal.
//  Because of the reversal, every balance is back at its booked rate
//  on day 1, so a later collection or payment still measures its
//  REALISED gain/loss (431/571) against the original booking — no
//  payment module needed to change. Lines on foreign-currency
//  accounts carry a foreign amount of 0: the EGP value moves, the USD
//  balance does not.
//
//  RULES
//    • Only a month-end date. One posted run per month-end; cancel it
//      first to run again. Both the month-end and the next month must
//      be open accounting periods.
//    • The closing rate is the rate-table rate on (or before) the
//      month-end (Settings › Currencies, same rule as FxService). A
//      currency with no rate stops the run with a clear message.
//    • An account whose foreign balance is incomplete (lines posted
//      with no rate on file) or which is shared by two currencies is
//      NOT revalued and is listed as a warning.
//    • Cancel reverses both journal entries (needs both periods open)
//      and keeps the run as "cancelled" — nothing is deleted.
//    • bcmath throughout (Rule 4); AuditLog on post and cancel
//      (Rule 5); every query filters company_id explicitly (H9).
//
//  NOT COVERED (listed on the screen, for a later step): lease
//  liabilities (shared account 213 — see LeaseAmortizationService),
//  advances to/from customers and suppliers (144 / 235), which are
//  normally non-monetary under IAS 21.16 anyway.
//
//  @layer    Service
//  @depends  JournalEntryService, ForeignCurrencyLedgerService,
//            FxService, FxRevaluationRun, FxRevaluationLine,
//            CustomerInvoice, SupplierBill, ChequeReceivable,
//            ChequePayable, ChartOfAccount, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Models\CustomerInvoice;
use App\Models\FxRevaluationLine;
use App\Models\FxRevaluationRun;
use App\Models\SupplierBill;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FxRevaluationService
{
    public const CODE_UNREALIZED_GAIN = '432';
    public const CODE_UNREALIZED_LOSS = '572';

    public const SOURCE_TYPE          = 'fx_revaluation';
    public const SOURCE_TYPE_REVERSAL = 'fx_revaluation_reversal';

    private const CODE_AR_FOREIGN = '133002';
    private const CODE_AP_FOREIGN = '221002';
    private const CODE_NOTES_RECEIVABLE = '134001';
    private const CODE_NOTES_PAYABLE    = '222001';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private ForeignCurrencyLedgerService $foreignLedger,
        private FxService $fx,
    ) {}

    // ── Read ─────────────────────────────────────────────────────

    public function paginate(int $companyId): LengthAwarePaginator
    {
        return FxRevaluationRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['journalEntry:id,entry_number', 'reversalJournalEntry:id,entry_number', 'creator:id,name'])
            ->orderByDesc('revaluation_date')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function show(int $companyId, FxRevaluationRun $run): FxRevaluationRun
    {
        abort_unless((int) $run->company_id === $companyId, 404);

        return $run->load([
            'lines' => fn ($q) => $q->orderBy('item_type')->orderBy('item_label'),
            'lines.currency:id,code',
            'lines.chartOfAccount:id,code,name_en',
            'journalEntry:id,entry_number,entry_date',
            'reversalJournalEntry:id,entry_number,entry_date',
            'creator:id,name', 'canceller:id,name',
        ]);
    }

    /** Last day of the previous month — the natural default. */
    public function defaultDate(): string
    {
        return Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
    }

    // ── Preview (nothing posted) ─────────────────────────────────

    /**
     * Everything the run WOULD post for this month-end. Recomputed
     * from scratch on post(), never trusted from the browser.
     */
    public function preview(int $companyId, string $date): array
    {
        $date = $this->assertMonthEnd($date);
        $currencyCodes = DB::table('currencies')->pluck('code', 'id');

        $state = (object) ['items' => [], 'warnings' => [], 'rates' => [], 'missing' => []];

        $this->collectAccounts($companyId, $date, $currencyCodes, $state);
        $this->collectCustomerInvoices($companyId, $date, $state);
        $this->collectSupplierBills($companyId, $date, $state);
        $this->collectCheques($companyId, $date, $state);

        $gain = '0.00';
        $loss = '0.00';
        foreach ($state->items as $item) {
            if (bccomp($item['difference'], '0', 2) > 0) {
                $gain = bcadd($gain, $item['difference'], 2);
            } else {
                $loss = bcadd($loss, ltrim($item['difference'], '-'), 2);
            }
        }

        $rates = [];
        foreach ($state->rates as $currencyId => $rate) {
            if ($rate !== null) $rates[(string) ($currencyCodes[$currencyId] ?? $currencyId)] = $rate;
        }

        $existing = FxRevaluationRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('revaluation_date', $date)
            ->where('status', FxRevaluationRun::STATUS_POSTED)
            ->first(['id', 'run_code']);

        return [
            'revaluation_date' => $date,
            'reversal_date'    => Carbon::parse($date)->addDay()->toDateString(),
            'rates'            => $rates,
            'missing_rates'    => array_values(array_unique(array_map(fn ($id) => (string) ($currencyCodes[$id] ?? $id), $state->missing))),
            'items'            => array_map(fn ($i) => $i + ['currency_code' => (string) ($currencyCodes[$i['currency_id']] ?? '')], $state->items),
            'warnings'         => $state->warnings,
            'total_gain'       => $gain,
            'total_loss'       => $loss,
            'net_amount'       => bcsub($gain, $loss, 2),
            'existing_run'     => $existing ? ['id' => $existing->id, 'run_code' => $existing->run_code] : null,
        ];
    }

    // ── Post ─────────────────────────────────────────────────────

    public function post(int $companyId, string $date, ?string $notes, User $actor): FxRevaluationRun
    {
        $preview = $this->preview($companyId, $date);

        if ($preview['existing_run']) {
            throw new \DomainException("This month-end has already been revalued ({$preview['existing_run']['run_code']}). Cancel that run first to revalue again.");
        }
        if ($preview['missing_rates'] !== []) {
            throw new \DomainException('No exchange rate on or before '.$preview['revaluation_date'].' for: '.implode(', ', $preview['missing_rates']).' — add it in Settings › Currencies, then try again.');
        }

        $postable = array_values(array_filter($preview['items'], fn ($i) => bccomp($i['difference'], '0', 2) !== 0));
        if ($postable === []) {
            throw new \DomainException('Nothing to revalue on this date — every foreign-currency balance is already at the closing rate, or there are none.');
        }

        $gainAccount = ChartOfAccount::findByCode($companyId, self::CODE_UNREALIZED_GAIN)
            ?? throw new \DomainException('Chart of Accounts is missing system account 432 (Unrealized FX Gain) — check the seeder.');
        $lossAccount = ChartOfAccount::findByCode($companyId, self::CODE_UNREALIZED_LOSS)
            ?? throw new \DomainException('Chart of Accounts is missing system account 572 (Unrealized FX Loss) — check the seeder.');

        $lines = $this->buildLines($postable, $preview, $gainAccount->id, $lossAccount->id);
        $mirror = array_map(fn ($l) => ['debit' => $l['credit'], 'credit' => $l['debit']] + $l, $lines);

        return DB::transaction(function () use ($companyId, $preview, $lines, $mirror, $notes, $actor) {
            // Serialise runs per company (two clicks, two users).
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $again = FxRevaluationRun::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereDate('revaluation_date', $preview['revaluation_date'])
                ->where('status', FxRevaluationRun::STATUS_POSTED)->exists();
            if ($again) {
                throw new \DomainException('This month-end was just revalued by someone else — refresh the page.');
            }

            $run = FxRevaluationRun::create([
                'company_id'       => $companyId,
                'run_code'         => $this->nextRunCode($companyId, $preview['revaluation_date']),
                'revaluation_date' => $preview['revaluation_date'],
                'reversal_date'    => $preview['reversal_date'],
                'status'           => FxRevaluationRun::STATUS_POSTED,
                'closing_rates'    => $preview['rates'],
                'total_gain'       => $preview['total_gain'],
                'total_loss'       => $preview['total_loss'],
                'net_amount'       => $preview['net_amount'],
                'notes'            => $notes,
                'created_by'       => $actor->id,
            ]);

            $entry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $preview['revaluation_date'],
                lines: $lines,
                sourceType: self::SOURCE_TYPE,
                sourceId: $run->id,
                narration: "Month-end FX revaluation {$run->run_code} as at {$preview['revaluation_date']} (IAS 21)",
                actor: $actor,
            );

            $reversal = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $preview['reversal_date'],
                lines: $mirror,
                sourceType: self::SOURCE_TYPE_REVERSAL,
                sourceId: $run->id,
                narration: "Automatic reversal of FX revaluation {$run->run_code}",
                actor: $actor,
            );

            $run->update(['journal_entry_id' => $entry->id, 'reversal_journal_entry_id' => $reversal->id]);

            foreach ($preview['items'] as $item) {
                FxRevaluationLine::create([
                    'company_id'            => $companyId,
                    'fx_revaluation_run_id' => $run->id,
                    'item_type'             => $item['item_type'],
                    'item_id'               => $item['item_id'],
                    'item_label'            => mb_substr($item['item_label'], 0, 255),
                    'chart_of_account_id'   => $item['chart_of_account_id'],
                    'currency_id'           => $item['currency_id'],
                    'native_balance'        => $item['native_balance'],
                    'book_balance'          => $item['book_balance'],
                    'closing_rate'          => $item['closing_rate'],
                    'revalued_balance'      => $item['revalued_balance'],
                    'difference'            => $item['difference'],
                ]);
            }

            AuditLog::record($actor, 'fx_revaluation.posted', $run, null, $run->fresh()->toArray());

            return $run->fresh(['journalEntry', 'reversalJournalEntry']);
        });
    }

    // ── Cancel ───────────────────────────────────────────────────

    public function cancel(int $companyId, FxRevaluationRun $run, string $reason, User $actor): FxRevaluationRun
    {
        abort_unless((int) $run->company_id === $companyId, 404);

        if (! $run->isPosted()) {
            throw new \DomainException('Only a posted revaluation can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling this revaluation.');
        }

        return DB::transaction(function () use ($run, $reason, $actor) {
            $run = FxRevaluationRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            if (! $run->isPosted()) {
                throw new \DomainException('This revaluation was just cancelled by someone else — refresh the page.');
            }

            $before = $run->toArray();

            // Each entry is reversed on its OWN date (JournalEntryService
            // rule), so both months must still be open.
            foreach ([$run->reversalJournalEntry, $run->journalEntry] as $entry) {
                if ($entry && $entry->status === \App\Models\JournalEntry::STATUS_POSTED) {
                    $this->journalEntryService->reverse($entry, $actor, "Cancelled FX revaluation {$run->run_code} — {$reason}");
                }
            }

            $run->update([
                'status'        => FxRevaluationRun::STATUS_CANCELLED,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ]);

            AuditLog::record($actor, 'fx_revaluation.cancelled', $run, $before, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    // ── Collectors ───────────────────────────────────────────────

    /** 1. Single-foreign-currency accounts (bank, safe, deposit, facility…). */
    private function collectAccounts(int $companyId, string $date, $currencyCodes, object $state): void
    {
        $controlIds = $this->controlAccountIds($companyId);
        $map = array_diff_key($this->foreignLedger->accountCurrencyMap($companyId), array_flip($controlIds));
        if ($map === [] && $this->foreignLedger->mixedAccounts($companyId) === []) return;

        $balances = $map === [] ? [] : $this->foreignLedger->balances($companyId, array_keys($map), $date);
        $names = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->whereIn('id', array_merge(array_keys($map), $this->foreignLedger->mixedAccounts($companyId)))
            ->get(['id', 'code', 'name_en'])->keyBy('id');

        foreach ($balances as $coaId => $b) {
            if (bccomp($b['native'], '0', 2) === 0 && bccomp($b['functional'], '0', 2) === 0) continue;

            $label = trim(($names[$coaId]->code ?? '').' '.($names[$coaId]->name_en ?? ''));

            if ($b['unknown_lines'] > 0) {
                $state->warnings[] = [
                    'type'  => 'incomplete_account',
                    'label' => $label,
                    'message' => "{$b['unknown_lines']} posting(s) on this account have no foreign amount (no exchange rate was on file when they were posted), so its foreign balance is not known. It was NOT revalued. Add the missing rates and run: php artisan fx:backfill-foreign-amounts",
                ];
                continue;
            }

            $rate = $this->rate($companyId, $b['currency_id'], $date, $state);
            if ($rate === null) continue;

            $this->addItem($state, 'account', null, $label, $coaId, $b['currency_id'], $b['native'], $b['functional'], $rate);
        }

        foreach ($this->foreignLedger->mixedAccounts($companyId) as $coaId) {
            if (in_array($coaId, $controlIds, true)) continue;
            $state->warnings[] = [
                'type'    => 'mixed_account',
                'label'   => trim(($names[$coaId]->code ?? '#'.$coaId).' '.($names[$coaId]->name_en ?? '')),
                'message' => 'This account is linked to more than one currency (e.g. two bank accounts in different currencies share it), so it cannot be revalued. Give each currency its own account.',
            ];
        }
    }

    /** 2a. Open foreign-currency customer invoices (AR 133002). */
    private function collectCustomerInvoices(int $companyId, string $date, object $state): void
    {
        $functionalId = $this->fx->functionalCurrencyId($companyId);
        $arAccount = ChartOfAccount::findByCode($companyId, self::CODE_AR_FOREIGN);

        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', CustomerInvoice::STATUS_POSTED)
            ->whereDate('invoice_date', '<=', $date)
            ->whereNotNull('currency_id')
            ->when($functionalId, fn ($q) => $q->where('currency_id', '!=', $functionalId))
            ->with('partner:id,name_en')
            ->get(['id', 'invoice_code', 'partner_id', 'currency_id', 'exchange_rate', 'amount_outstanding']);
        if ($invoices->isEmpty()) return;

        $ids = $invoices->pluck('id')->all();
        $after = $this->sumAfter([
            // + collections applied after the date (cash + WHT certificate)
            DB::table('customer_payment_allocations as a')
                ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
                ->leftJoin('journal_entries as j', 'j.id', '=', 'a.journal_entry_id')
                ->where('a.company_id', $companyId)->whereIn('a.customer_invoice_id', $ids)
                ->whereRaw('COALESCE(j.entry_date, p.payment_date) > ?', [$date])
                ->groupBy('a.customer_invoice_id')
                ->selectRaw('a.customer_invoice_id as doc_id, SUM(a.allocated_amount + COALESCE(a.wht_amount_functional, 0)) as amt'),
            // + credit notes issued after the date
            // (Audit M9: only the part that reduced the invoice — a surplus went to a customer credit balance)
            DB::table('credit_notes')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
                ->where('status', 'posted')->where('credit_date', '>', $date)
                ->groupBy('customer_invoice_id')->selectRaw('customer_invoice_id as doc_id, SUM(credit_value_functional - credit_balance_functional) as amt'),
            // + customer credit balances applied to the invoice after the date (Audit M9)
            DB::table('customer_credit_applications')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
                ->where('status', 'applied')->where('application_date', '>', $date)
                ->groupBy('customer_invoice_id')->selectRaw('customer_invoice_id as doc_id, SUM(amount) as amt'),
            // + internal settlements (netting) after the date
            DB::table('internal_settlement_customer_allocations as a')
                ->join('internal_settlements as s', 's.id', '=', 'a.internal_settlement_id')
                ->where('a.company_id', $companyId)->whereIn('a.customer_invoice_id', $ids)
                ->where('s.status', 'posted')->where('s.settlement_date', '>', $date)
                ->groupBy('a.customer_invoice_id')->selectRaw('a.customer_invoice_id as doc_id, SUM(a.allocated_amount_functional) as amt'),
        ], [
            // − debit notes issued after the date (they added to what is owed)
            DB::table('customer_debit_notes')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
                ->where('status', 'posted')->where('debit_date', '>', $date)
                ->groupBy('customer_invoice_id')->selectRaw('customer_invoice_id as doc_id, SUM(debit_value_functional) as amt'),
            // − credit notes that were in force on the date but reversed after it (Audit M9)
            DB::table('credit_notes')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
                ->where('status', 'reversed')->where('credit_date', '<=', $date)->whereDate('reversed_at', '>', $date)
                ->groupBy('customer_invoice_id')->selectRaw('customer_invoice_id as doc_id, SUM(credit_value_functional - credit_balance_functional) as amt'),
            // − credit-balance applications in force on the date but undone after it (Audit M9)
            DB::table('customer_credit_applications')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
                ->where('status', 'reversed')->where('application_date', '<=', $date)->whereDate('reversed_at', '>', $date)
                ->groupBy('customer_invoice_id')->selectRaw('customer_invoice_id as doc_id, SUM(amount) as amt'),
        ]);

        // Touched after the date by a flow that cannot be rebuilt safely.
        $touched = DB::table('factoring_transactions')->where('company_id', $companyId)->whereIn('customer_invoice_id', $ids)
            ->where(fn ($q) => $q->where('factoring_date', '>', $date)->orWhere('collection_date', '>', $date)->orWhere('rejection_date', '>', $date))
            ->pluck('customer_invoice_id')->all();

        foreach ($invoices as $inv) {
            $this->addOpenItem($state, $companyId, $date, 'customer_invoice', $inv->id,
                "Invoice {$inv->invoice_code} — ".($inv->partner?->name_en ?? ''),
                $arAccount?->id, (int) $inv->currency_id, (string) $inv->exchange_rate,
                bcadd((string) $inv->amount_outstanding, $after[$inv->id] ?? '0', 2), '1',
                in_array($inv->id, $touched), 'factoring');
        }
    }

    /** 2b. Open foreign-currency supplier bills (AP 221002). */
    private function collectSupplierBills(int $companyId, string $date, object $state): void
    {
        $functionalId = $this->fx->functionalCurrencyId($companyId);
        $apAccount = ChartOfAccount::findByCode($companyId, self::CODE_AP_FOREIGN);

        $bills = SupplierBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', SupplierBill::STATUS_POSTED)
            ->whereDate('bill_date', '<=', $date)
            ->whereNotNull('currency_id')
            ->when($functionalId, fn ($q) => $q->where('currency_id', '!=', $functionalId))
            ->with('partner:id,name_en')
            ->get(['id', 'bill_code', 'partner_id', 'currency_id', 'exchange_rate', 'amount_outstanding']);
        if ($bills->isEmpty()) return;

        $ids = $bills->pluck('id')->all();
        $after = $this->sumAfter([
            DB::table('supplier_payment_allocations as a')
                ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
                ->leftJoin('journal_entries as j', 'j.id', '=', 'a.journal_entry_id')
                ->where('a.company_id', $companyId)->whereIn('a.supplier_bill_id', $ids)
                ->whereRaw('COALESCE(j.entry_date, p.payment_date) > ?', [$date])
                ->groupBy('a.supplier_bill_id')
                ->selectRaw('a.supplier_bill_id as doc_id, SUM(a.allocated_amount) as amt'),
            DB::table('supplier_debit_notes')->where('company_id', $companyId)->whereIn('supplier_bill_id', $ids)
                ->where('status', 'posted')->where('debit_date', '>', $date)
                ->groupBy('supplier_bill_id')->selectRaw('supplier_bill_id as doc_id, SUM(debit_value_functional) as amt'),
            DB::table('internal_settlement_supplier_allocations as a')
                ->join('internal_settlements as s', 's.id', '=', 'a.internal_settlement_id')
                ->where('a.company_id', $companyId)->whereIn('a.supplier_bill_id', $ids)
                ->where('s.status', 'posted')->where('s.settlement_date', '>', $date)
                ->groupBy('a.supplier_bill_id')->selectRaw('a.supplier_bill_id as doc_id, SUM(a.allocated_amount_functional) as amt'),
        ], [
            DB::table('supplier_credit_notes')->where('company_id', $companyId)->whereIn('supplier_bill_id', $ids)
                ->where('status', 'posted')->where('credit_date', '>', $date)
                ->groupBy('supplier_bill_id')->selectRaw('supplier_bill_id as doc_id, SUM(credit_value_functional) as amt'),
        ]);

        $touched = array_merge(
            DB::table('reverse_factoring_transactions')->where('company_id', $companyId)->whereIn('supplier_bill_id', $ids)
                ->where(fn ($q) => $q->where('reverse_factoring_date', '>', $date)->orWhere('settlement_date', '>', $date))
                ->pluck('supplier_bill_id')->all(),
            DB::table('lc_issuance_history')->where('company_id', $companyId)->whereIn('supplier_bill_id', $ids)
                ->where('event_date', '>', $date)
                ->pluck('supplier_bill_id')->all(),
        );

        foreach ($bills as $bill) {
            // AP is a credit balance → signed negative.
            $this->addOpenItem($state, $companyId, $date, 'supplier_bill', $bill->id,
                "Bill {$bill->bill_code} — ".($bill->partner?->name_en ?? ''),
                $apAccount?->id, (int) $bill->currency_id, (string) $bill->exchange_rate,
                bcadd((string) $bill->amount_outstanding, $after[$bill->id] ?? '0', 2), '-1',
                in_array($bill->id, $touched), 'reverse factoring or an LC payment');
        }
    }

    /** 3. Open foreign-currency cheques — received (134001) and issued (222001). */
    private function collectCheques(int $companyId, string $date, object $state): void
    {
        $functionalId = $this->fx->functionalCurrencyId($companyId);
        $nr = ChartOfAccount::findByCode($companyId, self::CODE_NOTES_RECEIVABLE);
        $np = ChartOfAccount::findByCode($companyId, self::CODE_NOTES_PAYABLE);

        $received = ChequeReceivable::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->whereIn('status', [ChequeReceivable::STATUS_RECEIVED, ChequeReceivable::STATUS_SENT_FOR_COLLECTION])
                ->orWhere(fn ($q) => $q->where('status', ChequeReceivable::STATUS_COLLECTED)->where('collected_at', '>', $date.' 23:59:59'))
                ->orWhere(fn ($q) => $q->where('status', ChequeReceivable::STATUS_REJECTED)->where('rejected_at', '>', $date.' 23:59:59')))
            ->get();

        foreach ($received as $cheque) {
            $currencyId = $cheque->currencyIdResolved();
            if (! $currencyId || $currencyId === $functionalId || ! $nr) continue;
            if ($this->chequeDate($cheque) > $date) continue;

            $rate = $this->rate($companyId, $currencyId, $date, $state);
            if ($rate === null) continue;

            $this->addItem($state, 'cheque_receivable', $cheque->id, "Cheque received {$cheque->cheque_number}",
                $nr->id, $currencyId, bcadd((string) $cheque->cheque_amount, '0', 2), $cheque->bookedFunctional(), $rate);
        }

        $issued = ChequePayable::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->whereIn('status', [ChequePayable::STATUS_ISSUED, ChequePayable::STATUS_VOID, ChequePayable::STATUS_STOPPED]) // M-31: a voided cheque is still on the books until its payment is reopened
                ->orWhere(fn ($q) => $q->where('status', ChequePayable::STATUS_PAID)->where('paid_at', '>', $date.' 23:59:59')))
            ->get();

        foreach ($issued as $cheque) {
            $currencyId = $cheque->currencyIdResolved();
            if (! $currencyId || $currencyId === $functionalId || ! $np) continue;
            if ($this->chequeDate($cheque) > $date) continue;

            $rate = $this->rate($companyId, $currencyId, $date, $state);
            if ($rate === null) continue;

            $this->addItem($state, 'cheque_payable', $cheque->id, "Cheque issued {$cheque->cheque_number}",
                $np->id, $currencyId, bcmul((string) $cheque->cheque_amount, '-1', 2), bcmul($cheque->bookedFunctional(), '-1', 2), $rate);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function addOpenItem(object $state, int $companyId, string $date, string $type, int $id, string $label,
        ?int $accountId, int $currencyId, string $bookedRate, string $outstandingFunctional, string $sign,
        bool $touched, string $touchedBy): void
    {
        if (bccomp($outstandingFunctional, '0', 2) <= 0) return; // not open at the month-end

        if ($touched) {
            $state->warnings[] = [
                'type'    => 'open_item_skipped',
                'label'   => $label,
                'message' => "Changed after {$date} by {$touchedBy}, so the amount open at the month-end cannot be rebuilt safely. It was NOT revalued — review it by hand.",
            ];
            return;
        }
        if (! $accountId) {
            $state->warnings[] = ['type' => 'missing_account', 'label' => $label, 'message' => 'The foreign-currency control account is not in the Chart of Accounts — it was not revalued.'];
            return;
        }

        $rate = $this->rate($companyId, $currencyId, $date, $state);
        if ($rate === null) return;

        $booked = bccomp($bookedRate, '0', 6) > 0 ? $bookedRate : '1';
        $native = $this->round2(bcdiv($outstandingFunctional, $booked, 8));

        $this->addItem($state, $type, $id, $label, $accountId, $currencyId,
            bcmul($native, $sign, 2), bcmul($outstandingFunctional, $sign, 2), $rate);
    }

    /** Adds one item; all amounts signed debit − credit. */
    private function addItem(object $state, string $type, ?int $id, string $label, int $accountId, int $currencyId,
        string $native, string $book, string $rate): void
    {
        $revalued = $this->round2(bcmul($native, $rate, 8));

        $state->items[] = [
            'item_type'           => $type,
            'item_id'             => $id,
            'item_label'          => $label,
            'chart_of_account_id' => $accountId,
            'currency_id'         => $currencyId,
            'native_balance'      => $native,
            'book_balance'        => bcadd($book, '0', 2),
            'closing_rate'        => $rate,
            'revalued_balance'    => $revalued,
            'difference'          => bcsub($revalued, $book, 2),
        ];
    }

    /** Closing rate for a currency, cached; records a missing rate instead of throwing. */
    private function rate(int $companyId, int $currencyId, string $date, object $state): ?string
    {
        if (! array_key_exists($currencyId, $state->rates)) {
            try {
                $state->rates[$currencyId] = $this->fx->rateOn($companyId, $currencyId, $date);
            } catch (\DomainException) {
                $state->rates[$currencyId] = null;
                $state->missing[] = $currencyId;
            }
        }

        return $state->rates[$currencyId];
    }

    /**
     * Journal lines: one per account (items netted per account), the
     * gains to 432 and the losses to 572. Lines on foreign-currency
     * accounts carry a foreign amount of 0 — see class header.
     */
    private function buildLines(array $items, array $preview, int $gainAccountId, int $lossAccountId): array
    {
        $byAccount = [];
        foreach ($items as $item) {
            $key = $item['chart_of_account_id'];
            $byAccount[$key]['amount'] = bcadd($byAccount[$key]['amount'] ?? '0', $item['difference'], 2);
            $byAccount[$key]['currency_id'] = $item['item_type'] === 'account' ? $item['currency_id'] : null;
            $byAccount[$key]['count'] = ($byAccount[$key]['count'] ?? 0) + 1;
        }

        $label = 'FX revaluation as at '.$preview['revaluation_date'];
        $lines = [];
        foreach ($byAccount as $accountId => $row) {
            if (bccomp($row['amount'], '0', 2) === 0) continue;
            $line = [
                'chart_of_account_id' => $accountId,
                'debit'               => bccomp($row['amount'], '0', 2) > 0 ? $row['amount'] : '0',
                'credit'              => bccomp($row['amount'], '0', 2) < 0 ? ltrim($row['amount'], '-') : '0',
                'description'         => $label.($row['count'] > 1 ? " ({$row['count']} items)" : ''),
            ];
            if ($row['currency_id']) {
                $line['currency_id'] = $row['currency_id'];
                $line['amount_currency'] = '0';
            }
            $lines[] = $line;
        }

        if (bccomp($preview['total_gain'], '0', 2) > 0) {
            $lines[] = ['chart_of_account_id' => $gainAccountId, 'debit' => '0', 'credit' => $preview['total_gain'], 'description' => 'Unrealized FX Gain — '.$label];
        }
        if (bccomp($preview['total_loss'], '0', 2) > 0) {
            $lines[] = ['chart_of_account_id' => $lossAccountId, 'debit' => $preview['total_loss'], 'credit' => '0', 'description' => 'Unrealized FX Loss — '.$label];
        }

        return $lines;
    }

    /** Sums per document: + for $add queries, − for $subtract queries. */
    private function sumAfter(array $add, array $subtract): array
    {
        $out = [];
        foreach ([[$add, '1'], [$subtract, '-1']] as [$queries, $sign]) {
            foreach ($queries as $query) {
                foreach ($query->get() as $row) {
                    $out[$row->doc_id] = bcadd($out[$row->doc_id] ?? '0', bcmul((string) ($row->amt ?? '0'), $sign, 2), 2);
                }
            }
        }
        return $out;
    }

    /** The date a cheque entered 134/222 — its parent document's date. */
    private function chequeDate($cheque): string
    {
        $source = $cheque->source();
        $date = $source?->payment_date ?? $source?->collection_date ?? $source?->reverse_factoring_date ?? null;

        return $date ? Carbon::parse($date)->toDateString() : '0000-00-00'; // opening-balance cheque: always before
    }

    private function controlAccountIds(int $companyId): array
    {
        return ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->whereIn('code', [self::CODE_AR_FOREIGN, self::CODE_AP_FOREIGN, self::CODE_NOTES_RECEIVABLE, self::CODE_NOTES_PAYABLE])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertMonthEnd(string $date): string
    {
        try {
            $d = Carbon::parse($date);
        } catch (\Throwable) {
            throw new \DomainException('Choose a valid month-end date.');
        }

        if ($d->toDateString() !== $d->copy()->endOfMonth()->toDateString()) {
            throw new \DomainException('Revaluation is a month-end step — choose the last day of a month (e.g. '.$d->copy()->endOfMonth()->toDateString().').');
        }

        return $d->toDateString();
    }

    private function nextRunCode(int $companyId, string $date): string
    {
        // Audit L-7: atomic monthly counter (code shape FXR-YYYYMM-NN kept).
        $period = substr($date, 0, 4).substr($date, 5, 2);
        $prefix = 'FXR-'.$period.'-';
        $build  = fn (int $n) => $prefix.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $n      = \App\Support\DocumentNumber::nextNumber(
            $companyId, FxRevaluationRun::class, 'run_code', 'FXR', $period, $prefix.'%', $build, '-'
        );

        return $build($n);
    }

    private function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
