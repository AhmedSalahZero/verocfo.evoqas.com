<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  IncomeTaxService
//
//  Corporate income tax, the IAS 12 way (Audit H19, confirmed with
//  Mahmoud, Sept 2026):
//
//    1. PROVISION — at year-end the accountant records the year's tax:
//         Dr 591 Current Income Tax      / Cr 226003 Income Tax Payable
//       and, optionally, the deferred tax movement:
//         expense  Dr 592 Deferred Tax   / Cr 215 Deferred Tax Liability
//         income   Dr 124 Deferred Tax Asset / Cr 592
//       591 / 592 show on their own line UNDER Profit Before Tax
//       (IncomeStatementService) — never inside the expense categories.
//       The tax owed is a liability on the Balance Sheet (226003).
//
//    2. WHT OFFSET — the Debit WHT customers deducted during the year
//       (142) is an advance payment of this same tax, so it is set off:
//         Dr 226003 / Cr 142
//       limited to what is left on both accounts on that date.
//
//    3. The remaining 226003 balance is paid in cash through the normal
//       Other Payment screen (debit account 226003) — nothing new.
//
//  The screen shows Profit Before Tax for the chosen fiscal year as a
//  reference; the tax figure itself is typed by the accountant (the
//  tax return is prepared outside the system — non-deductible items,
//  exemptions and rates are a matter for the tax adviser).
//
//  Every entry can be cancelled (journal entry reversed on its own
//  date — the period must be open) and entered again; nothing is ever
//  edited or deleted. bcmath (Rule 4), AuditLog (Rule 5), company_id
//  filtered explicitly (H9).
//
//  @layer    Service
//  @depends  IncomeTaxEntry, JournalEntryService, IncomeStatementService,
//            LedgerStatementService, ChartOfAccount, FiscalYear, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\IncomeTaxEntry;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IncomeTaxService
{
    public const CODE_CURRENT_TAX  = '591';
    public const CODE_DEFERRED_TAX = '592';
    public const CODE_TAX_PAYABLE  = '226003';
    public const CODE_DEBIT_WHT    = '142';
    public const CODE_DTA          = '124';
    public const CODE_DTL          = '215';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private IncomeStatementService $incomeStatementService,
        private LedgerStatementService $ledger,
    ) {}

    // ── Read ─────────────────────────────────────────────────────

    /** Figures for the Income Tax screen, for one fiscal year. */
    public function summary(int $companyId, ?int $fiscalYearId): array
    {
        $years = FiscalYear::where('company_id', $companyId)->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']);
        $year  = $fiscalYearId ? $years->firstWhere('id', $fiscalYearId) : $years->first();

        $out = [
            'fiscal_years'     => $years->map(fn ($y) => ['id' => $y->id, 'name' => $y->name, 'start_date' => $y->start_date?->toDateString(), 'end_date' => $y->end_date?->toDateString()])->values()->all(),
            'fiscal_year_id'   => $year?->id,
            'year_end'         => $year?->end_date?->toDateString(),
            'profit_before_tax'=> '0.00',
            'tax_charged'      => '0.00',
            'wht_balance'      => '0.00',
            'tax_payable'      => '0.00',
        ];

        if ($year) {
            $pl = $this->incomeStatementService->build($companyId, [[
                'key' => 'y', 'label' => $year->name,
                'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(),
            ]]);
            $out['profit_before_tax'] = $pl['profit_before_tax']['y'] ?? '0.00';
            $out['tax_charged']       = $pl['income_tax']['totals']['y'] ?? '0.00';
            $asOf = $year->end_date->toDateString();
            $out['wht_balance'] = $this->balance($companyId, self::CODE_DEBIT_WHT, $asOf, LedgerStatementService::SIGN_ASSET);
            $out['tax_payable'] = $this->balance($companyId, self::CODE_TAX_PAYABLE, $asOf, LedgerStatementService::SIGN_LIABILITY);
        }

        $out['entries'] = IncomeTaxEntry::where('company_id', $companyId)
            ->when($year, fn ($q) => $q->where('fiscal_year_id', $year->id))
            ->with(['journalEntry:id,entry_number', 'creator:id,name'])
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->get();

        return $out;
    }

    // ── Write ────────────────────────────────────────────────────

    public function recordProvision(int $companyId, array $data, User $actor): IncomeTaxEntry
    {
        $current  = bcadd((string) ($data['current_tax'] ?? '0'), '0', 2);
        $deferred = bcadd((string) ($data['deferred_tax'] ?? '0'), '0', 2);
        $date     = substr((string) $data['entry_date'], 0, 10);

        if (bccomp($current, '0', 2) < 0) {
            throw new \DomainException('Current income tax cannot be negative — a tax refund due is recorded against 142 / 226003 directly.');
        }
        if (bccomp($current, '0', 2) === 0 && bccomp($deferred, '0', 2) === 0) {
            throw new \DomainException('Enter the current tax, the deferred tax, or both.');
        }

        $lines = [];
        if (bccomp($current, '0', 2) > 0) {
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_CURRENT_TAX), 'debit' => $current, 'credit' => '0', 'description' => 'Current income tax for the year'];
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_TAX_PAYABLE), 'debit' => '0', 'credit' => $current, 'description' => 'Current income tax for the year'];
        }
        if (bccomp($deferred, '0', 2) > 0) {        // deferred tax expense → liability grows
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_DEFERRED_TAX), 'debit' => $deferred, 'credit' => '0', 'description' => 'Deferred tax expense'];
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_DTL), 'debit' => '0', 'credit' => $deferred, 'description' => 'Deferred tax expense'];
        } elseif (bccomp($deferred, '0', 2) < 0) {  // deferred tax income → asset grows
            $abs = ltrim($deferred, '-');
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_DTA), 'debit' => $abs, 'credit' => '0', 'description' => 'Deferred tax income'];
            $lines[] = ['chart_of_account_id' => $this->account($companyId, self::CODE_DEFERRED_TAX), 'debit' => '0', 'credit' => $abs, 'description' => 'Deferred tax income'];
        }

        return $this->store($companyId, IncomeTaxEntry::TYPE_PROVISION, $date, $current, $deferred, $lines, $data['notes'] ?? null, $actor, 'Income tax provision');
    }

    public function offsetWithholdingTax(int $companyId, array $data, User $actor): IncomeTaxEntry
    {
        $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 2);
        $date   = substr((string) $data['entry_date'], 0, 10);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException('Enter the amount of Debit WHT to set off.');
        }

        $wht = $this->balance($companyId, self::CODE_DEBIT_WHT, $date, LedgerStatementService::SIGN_ASSET);
        $payable = $this->balance($companyId, self::CODE_TAX_PAYABLE, $date, LedgerStatementService::SIGN_LIABILITY);
        if (bccomp($amount, $wht, 2) > 0) {
            throw new \DomainException("Only {$wht} of Debit WHT (142) is available on {$date}.");
        }
        if (bccomp($amount, $payable, 2) > 0) {
            throw new \DomainException("Only {$payable} of income tax is owed (226003) on {$date} — record the year's provision first.");
        }

        $lines = [
            ['chart_of_account_id' => $this->account($companyId, self::CODE_TAX_PAYABLE), 'debit' => $amount, 'credit' => '0', 'description' => 'Debit WHT set off against income tax'],
            ['chart_of_account_id' => $this->account($companyId, self::CODE_DEBIT_WHT), 'debit' => '0', 'credit' => $amount, 'description' => 'Debit WHT set off against income tax'],
        ];

        return $this->store($companyId, IncomeTaxEntry::TYPE_WHT_OFFSET, $date, $amount, '0.00', $lines, $data['notes'] ?? null, $actor, 'Debit WHT set off against income tax');
    }

    public function cancel(int $companyId, IncomeTaxEntry $entry, string $reason, User $actor): IncomeTaxEntry
    {
        abort_unless((int) $entry->company_id === $companyId, 404);

        if ($entry->status !== IncomeTaxEntry::STATUS_POSTED) {
            throw new \DomainException('This entry is already cancelled.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling.');
        }

        return DB::transaction(function () use ($entry, $reason, $actor) {
            $before = $entry->toArray();
            $je = $entry->journal_entry_id ? JournalEntry::find($entry->journal_entry_id) : null;
            if ($je && $je->status === JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($je, $actor, 'Cancelled income tax entry — '.$reason);
            }
            $entry->update([
                'status' => IncomeTaxEntry::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancel_reason' => $reason,
            ]);
            AuditLog::record($actor, 'income_tax.cancelled', $entry, $before, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    // ── Private ──────────────────────────────────────────────────

    private function store(int $companyId, string $type, string $date, string $current, string $deferred, array $lines, ?string $notes, User $actor, string $narration): IncomeTaxEntry
    {
        $fiscalYearId = FiscalYear::where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->value('id');

        return DB::transaction(function () use ($companyId, $type, $date, $current, $deferred, $lines, $notes, $actor, $narration, $fiscalYearId) {
            $entry = IncomeTaxEntry::create([
                'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'entry_type' => $type,
                'entry_date' => $date, 'current_tax' => $current, 'deferred_tax' => $deferred,
                'status' => IncomeTaxEntry::STATUS_POSTED, 'notes' => $notes, 'created_by' => $actor->id,
            ]);

            $je = $this->journalEntryService->post(
                companyId: $companyId, entryDate: $date, lines: $lines,
                sourceType: 'income_tax', sourceId: $entry->id, narration: $narration, actor: $actor,
            );

            $entry->update(['journal_entry_id' => $je->id]);
            AuditLog::record($actor, 'income_tax.'.$type, $entry, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    private function balance(int $companyId, string $code, string $asOf, string $sign): string
    {
        $account = ChartOfAccount::findByCode($companyId, $code);
        if (! $account) return '0.00';

        return $this->ledger->totals($companyId, $account->id, null, $asOf, $sign)['ending_balance'];
    }

    private function account(int $companyId, string $code): int
    {
        $account = ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} — run the latest migrations (000301).");

        return (int) $account->id;
    }
}
