<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliationService
//
//  Audit H-25 — the bank reconciliation module: the core month-end
//  control that proves the company's books agree with the bank's own
//  statement. Everything is done in the bank account's OWN currency
//  (a USD account is reconciled in USD, using the foreign amount kept
//  on every ledger line — see ForeignCurrencyLedgerService), so the
//  result can be ticked against the paper/PDF statement from the bank.
//
//  HOW IT WORKS (plain language)
//    1. START   — pick a bank account, the statement date and the
//                 closing balance printed on the bank's statement.
//    2. LOAD    — import the bank's statement lines (Excel/CSV) or type
//                 them in. Lines are kept per bank account.
//    3. MATCH   — each statement line is matched to the ledger entry
//                 (book item) it represents: automatically (same
//                 amount, nearby date) or by hand (one statement line
//                 can be matched to several ledger entries that add up
//                 to it, e.g. one deposit slip for three cheques).
//                 Ledger entries can also simply be ticked as "seen on
//                 the bank statement" when no statement lines were loaded.
//    4. REPORT  — the reconciling-items report:
//
//        Bank statement closing balance                       S
//        + Outstanding deposits   (in books, not yet at bank)
//        − Outstanding payments   (in books, not yet at bank)
//        = Adjusted bank balance
//
//        Book (ledger) balance at the statement date          B
//        + Bank items not yet in the books  (charges, interest)  U
//        + Entries cleared that are dated after the statement    L
//        = Adjusted book balance
//
//        Difference = Adjusted bank − Adjusted book
//                   = S − (all cleared ledger entries + U)
//
//    5. COMPLETE — only when the difference is exactly 0.00 and no
//                 ledger line is missing its foreign amount. The report
//                 figures are then frozen. A completed reconciliation
//                 can be reopened only if it is the latest one.
//
//  RULES
//    • A ledger entry can be cleared once, ever (unique index) — it can
//      never be counted in two months.
//    • Nothing here posts to the ledger. Items missing from the books
//      are booked through the normal screens, then matched.
//    • bcmath only (Rule 4). Every query filters company_id explicitly
//      (Audit H9). Every write is audit-logged (Rule 5).
//
//  @layer    Service
//  @depends  BankReconciliation, BankReconciliationItem, BankStatementLine,
//            BankAccount, JournalEntryLine, ForeignCurrencyLedgerService,
//            CompanyActiveCurrency, Currency, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\BankReconciliation;
use App\Models\FinancialInstitutions\BankReconciliationItem;
use App\Models\FinancialInstitutions\BankStatementLine;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\ForeignCurrencyLedgerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class BankReconciliationService
{
    /** Ledger lines up to this many days away from a statement line are considered by auto-match. */
    public const DEFAULT_MATCH_WINDOW_DAYS = 15;

    /** Hard cap on how many open ledger lines are sent to the screen. */
    private const MAX_BOOK_LINES = 1000;

    public function __construct(private ForeignCurrencyLedgerService $foreignLedger) {}

    // ══════════════════════════════════════════════════════════════
    //  Lists & lookups
    // ══════════════════════════════════════════════════════════════

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $paginator = BankReconciliation::where('company_id', $companyId)
            ->with(['bankAccount:id,bank_id,account_name,account_number,account_type', 'bankAccount.bank:id,name_en'])
            ->when($filters['bank_account_id'] ?? null, fn ($q, $id) => $q->where('bank_account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $codes = $this->currencyCodes($paginator->getCollection()->pluck('currency_id')->all());
        $paginator->getCollection()->transform(function ($rec) use ($codes) {
            $rec->currency_code = $codes[$rec->currency_id] ?? null;

            return $rec;
        });

        return $paginator;
    }

    /** Active bank accounts that have a ledger account (only those can be reconciled). */
    public function bankAccountOptions(int $companyId): array
    {
        $accounts = BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->with('bank:id,name_en')
            ->orderBy('account_name')
            ->get(['id', 'bank_id', 'account_name', 'account_number', 'account_type', 'currency_id']);

        $codes = $this->currencyCodes($accounts->pluck('currency_id')->all());

        return $accounts->map(fn ($a) => [
            'id'             => $a->id,
            'label'          => trim(($a->bank?->name_en ?? '').' · '.$a->account_name.($a->account_number ? ' · '.$a->account_number : '')),
            'account_type'   => $a->account_type,
            'currency_code'  => $codes[$a->currency_id] ?? null,
            'last_completed' => BankReconciliation::where('company_id', $companyId)
                ->where('bank_account_id', $a->id)->where('status', BankReconciliation::STATUS_COMPLETED)
                ->max('statement_date'),
        ])->values()->all();
    }

    // ══════════════════════════════════════════════════════════════
    //  Start / delete
    // ══════════════════════════════════════════════════════════════

    public function start(int $companyId, array $data, User $actor): BankReconciliation
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $account = BankAccount::where('company_id', $companyId)->findOrFail($data['bank_account_id']);

            if (! $account->coa_account_id) {
                throw new \DomainException('This bank account has no Chart of Accounts link — it cannot be reconciled.');
            }

            $statementDate = Carbon::parse($data['statement_date'])->startOfDay();
            if ($statementDate->isFuture()) {
                throw new \DomainException('The statement date cannot be in the future.');
            }

            if (BankReconciliation::where('company_id', $companyId)->where('bank_account_id', $account->id)
                ->where('status', BankReconciliation::STATUS_DRAFT)->exists()) {
                throw new \DomainException('This bank account already has a reconciliation in progress — complete or delete it first.');
            }

            $last = BankReconciliation::where('company_id', $companyId)->where('bank_account_id', $account->id)
                ->where('status', BankReconciliation::STATUS_COMPLETED)->max('statement_date');
            if ($last && $statementDate->lte(Carbon::parse($last))) {
                throw new \DomainException('The statement date must be after the last completed reconciliation ('.Carbon::parse($last)->format('Y-m-d').').');
            }

            $rec = BankReconciliation::create([
                'company_id'               => $companyId,
                'bank_account_id'          => $account->id,
                'currency_id'              => $account->currency_id,
                'statement_date'           => $statementDate->format('Y-m-d'),
                'statement_ending_balance' => $this->money($data['statement_ending_balance']),
                'status'                   => BankReconciliation::STATUS_DRAFT,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $actor->id,
            ]);

            AuditLog::record($actor, 'bank_reconciliation.started', $rec, null, $rec->toArray());

            return $rec;
        });
    }

    /** Change the closing balance / date typed on a draft (typo correction). */
    public function updateHeader(int $companyId, BankReconciliation $rec, array $data, User $actor): BankReconciliation
    {
        $this->assertDraft($companyId, $rec);

        $before = $rec->toArray();
        $date   = Carbon::parse($data['statement_date'])->startOfDay();
        if ($date->isFuture()) {
            throw new \DomainException('The statement date cannot be in the future.');
        }
        $last = BankReconciliation::where('company_id', $companyId)->where('bank_account_id', $rec->bank_account_id)
            ->where('status', BankReconciliation::STATUS_COMPLETED)->max('statement_date');
        if ($last && $date->lte(Carbon::parse($last))) {
            throw new \DomainException('The statement date must be after the last completed reconciliation.');
        }

        $lateMatched = BankStatementLine::where('company_id', $companyId)
            ->where('bank_account_id', $rec->bank_account_id)
            ->whereDate('line_date', '>', $date->format('Y-m-d'))
            ->whereExists(function ($q) use ($rec) {
                $q->select(DB::raw(1))->from('bank_reconciliation_items as bri')
                    ->whereColumn('bri.bank_statement_line_id', 'bank_statement_lines.id')
                    ->where('bri.bank_reconciliation_id', $rec->id);
            })->exists();
        if ($lateMatched) {
            throw new \DomainException('Some matched statement lines are dated after that date — unmatch them first.');
        }

        $rec->update([
            'statement_date'           => $date->format('Y-m-d'),
            'statement_ending_balance' => $this->money($data['statement_ending_balance']),
            'notes'                    => $data['notes'] ?? $rec->notes,
        ]);

        AuditLog::record($actor, 'bank_reconciliation.updated', $rec, $before, $rec->fresh()->toArray());

        return $rec->fresh();
    }

    public function delete(int $companyId, BankReconciliation $rec, User $actor): void
    {
        $this->assertDraft($companyId, $rec);

        DB::transaction(function () use ($rec, $actor) {
            $before = $rec->toArray();
            $rec->items()->delete(); // frees the ledger lines to be cleared again
            $rec->delete();
            AuditLog::record($actor, 'bank_reconciliation.deleted', $rec, $before, null);
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  Bank statement lines — type in / import / delete
    // ══════════════════════════════════════════════════════════════

    public function addStatementLine(int $companyId, BankReconciliation $rec, array $data, User $actor): BankStatementLine
    {
        $this->assertDraft($companyId, $rec);

        $amount = $this->money($data['amount']);
        if (bccomp($amount, '0', 2) === 0) {
            throw new \DomainException('A statement line cannot be zero.');
        }

        return DB::transaction(function () use ($companyId, $rec, $data, $amount, $actor) {
            $hash = $this->nextManualHash($companyId, $rec->bank_account_id, Carbon::parse($data['line_date'])->format('Y-m-d'), $amount, $data['reference'] ?? '', $data['description'] ?? '');

            $line = BankStatementLine::create([
                'company_id'      => $companyId,
                'bank_account_id' => $rec->bank_account_id,
                'line_date'       => $data['line_date'],
                'description'     => $data['description'] ?? null,
                'reference'       => $data['reference'] ?? null,
                'amount'          => $amount,
                'line_hash'       => $hash,
                'source'          => 'manual',
                'created_by'      => $actor->id,
            ]);

            AuditLog::record($actor, 'bank_statement_line.created', $line, null, $line->toArray());

            return $line;
        });
    }

    /**
     * Import the bank's statement from Excel/CSV. The first row is the
     * header. Recognised columns (English or Arabic names, any order):
     *   Date | Description | Reference | Debit/Withdrawal | Credit/Deposit | Amount
     * "Withdrawal/Debit" = money OUT of the account, "Deposit/Credit" =
     * money IN (bank's wording). Or a single signed Amount column
     * (+ in / − out). A line already imported (same date, amount,
     * reference, description) is skipped — safe to import a file twice.
     *
     * @return array{imported:int, skipped:int, errors:array<int,string>}
     */
    public function importStatement(int $companyId, BankReconciliation $rec, UploadedFile $file, User $actor): array
    {
        $this->assertDraft($companyId, $rec);

        $rows = Excel::toArray([], $file)[0] ?? [];
        if (count($rows) < 2) {
            throw new \DomainException('The file is empty — it needs a header row and at least one statement line.');
        }

        $columns = $this->detectColumns($rows[0]);
        if ($columns['date'] === null || ($columns['amount'] === null && $columns['in'] === null && $columns['out'] === null)) {
            throw new \DomainException('Could not find the columns. The first row must contain a Date column and either Amount, or Debit/Withdrawal and Credit/Deposit columns.');
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $batch    = []; // occurrence counter per identical line inside THIS file

        DB::transaction(function () use ($companyId, $rec, $rows, $columns, $actor, &$imported, &$skipped, &$errors, &$batch) {
            foreach ($rows as $i => $row) {
                if ($i === 0 || empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                try {
                    $date   = $this->parseDate($row[$columns['date']] ?? null);
                    $amount = $this->parseAmount($row, $columns);
                } catch (\DomainException $e) {
                    $errors[] = 'Row '.($i + 1).': '.$e->getMessage();
                    continue;
                }

                if (bccomp($amount, '0', 2) === 0) {
                    continue; // blank or zero line — nothing to reconcile
                }

                $description = $columns['description'] !== null ? trim((string) ($row[$columns['description']] ?? '')) : '';
                $reference   = $columns['reference'] !== null ? trim((string) ($row[$columns['reference']] ?? '')) : '';

                // The same line twice in one file is two real lines; the same line
                // already stored from an earlier import is a duplicate to skip.
                $base = $this->baseHash($rec->bank_account_id, $date, $amount, $reference, $description);
                $batch[$base] = ($batch[$base] ?? 0) + 1;
                $hash = sha1($base.'#'.$batch[$base]);
                if ($this->hashExists($companyId, $rec->bank_account_id, $hash)) {
                    $skipped++;
                    continue;
                }

                BankStatementLine::create([
                    'company_id'      => $companyId,
                    'bank_account_id' => $rec->bank_account_id,
                    'line_date'       => $date,
                    'description'     => $description !== '' ? mb_substr($description, 0, 500) : null,
                    'reference'       => $reference !== '' ? mb_substr($reference, 0, 100) : null,
                    'amount'          => $amount,
                    'line_hash'       => $hash,
                    'source'          => 'import',
                    'created_by'      => $actor->id,
                ]);
                $imported++;
            }

            AuditLog::record($actor, 'bank_statement.imported', $rec, null, ['imported' => $imported, 'skipped' => $skipped, 'errors' => count($errors)]);
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
    }

    /** Delete a statement line that has not been matched (e.g. typed or imported by mistake). */
    public function deleteStatementLine(int $companyId, BankReconciliation $rec, int $lineId, User $actor): void
    {
        $this->assertDraft($companyId, $rec);

        $line = BankStatementLine::where('company_id', $companyId)->where('bank_account_id', $rec->bank_account_id)->findOrFail($lineId);
        if ($line->items()->exists()) {
            throw new \DomainException('This statement line is matched — unmatch it first.');
        }

        $before = $line->toArray();
        $line->delete();
        AuditLog::record($actor, 'bank_statement_line.deleted', $line, $before, null);
    }

    // ══════════════════════════════════════════════════════════════
    //  Matching
    // ══════════════════════════════════════════════════════════════

    /**
     * Automatic matching: every unmatched statement line (dated up to the
     * statement date) is paired with an open ledger entry of exactly the
     * same amount dated within $windowDays. A reference found in the
     * ledger entry's text wins first, then the closest date. One-to-one.
     *
     * @return int number of pairs matched
     */
    public function autoMatch(int $companyId, BankReconciliation $rec, int $windowDays, User $actor): int
    {
        $this->assertDraft($companyId, $rec);
        $ctx = $this->context($companyId, $rec);

        $statements = $this->unmatchedStatementLines($companyId, $rec)->all();
        $books      = $this->openBookLines($ctx, $rec)->groupBy(fn ($b) => $this->amountKey($b->amount));

        $candidates = [];
        foreach ($statements as $s) {
            foreach ($books->get($this->amountKey($s->amount), collect()) as $b) {
                $days = abs(Carbon::parse($s->line_date)->diffInDays(Carbon::parse($b->entry_date), true));
                if ($days > $windowDays) {
                    continue;
                }
                $refHit = $s->reference && $this->containsText($b, (string) $s->reference);
                $candidates[] = [$refHit ? 0 : 1, $days, $s->id, $b->id, $s, $b];
            }
        }
        usort($candidates, fn ($x, $y) => [$x[0], $x[1], $x[2], $x[3]] <=> [$y[0], $y[1], $y[2], $y[3]]);

        $usedStatements = [];
        $usedBooks      = [];
        $count          = 0;

        DB::transaction(function () use ($companyId, $rec, $candidates, $actor, &$usedStatements, &$usedBooks, &$count) {
            foreach ($candidates as [, , $sid, $bid, , $b]) {
                if (isset($usedStatements[$sid]) || isset($usedBooks[$bid])) {
                    continue;
                }
                $usedStatements[$sid] = true;
                $usedBooks[$bid]      = true;

                BankReconciliationItem::create([
                    'company_id'             => $companyId,
                    'bank_reconciliation_id' => $rec->id,
                    'journal_entry_line_id'  => $bid,
                    'bank_statement_line_id' => $sid,
                    'amount'                 => $this->money($b->amount),
                    'matched_by'             => 'auto',
                    'created_by'             => $actor->id,
                ]);
                $count++;
            }

            AuditLog::record($actor, 'bank_reconciliation.auto_matched', $rec, null, ['pairs' => $count]);
        });

        return $count;
    }

    /**
     * Manual match: one statement line ↔ one or more ledger entries whose
     * amounts add up to exactly the statement line's amount.
     */
    public function match(int $companyId, BankReconciliation $rec, int $statementLineId, array $bookLineIds, User $actor): void
    {
        $this->assertDraft($companyId, $rec);

        $statement = BankStatementLine::where('company_id', $companyId)->where('bank_account_id', $rec->bank_account_id)->findOrFail($statementLineId);
        if ($statement->items()->exists()) {
            throw new \DomainException('This statement line is already matched.');
        }
        if ($statement->line_date->gt($rec->statement_date)) {
            throw new \DomainException('This statement line is dated after the statement date.');
        }

        $lines = $this->eligibleBookLines($companyId, $rec, $bookLineIds);

        $sum = '0.00';
        foreach ($lines as $l) {
            $sum = bcadd($sum, $this->money($l->amount), 2);
        }
        if (bccomp($sum, $this->money($statement->amount), 2) !== 0) {
            throw new \DomainException("The selected ledger entries add up to {$sum}, but the statement line is {$statement->amount}. They must be exactly equal.");
        }

        DB::transaction(function () use ($companyId, $rec, $statement, $lines, $actor) {
            foreach ($lines as $l) {
                BankReconciliationItem::create([
                    'company_id'             => $companyId,
                    'bank_reconciliation_id' => $rec->id,
                    'journal_entry_line_id'  => $l->id,
                    'bank_statement_line_id' => $statement->id,
                    'amount'                 => $this->money($l->amount),
                    'matched_by'             => 'manual',
                    'created_by'             => $actor->id,
                ]);
            }
            AuditLog::record($actor, 'bank_reconciliation.matched', $rec, null, ['statement_line_id' => $statement->id, 'journal_entry_line_ids' => $lines->pluck('id')->all()]);
        });
    }

    /** Tick ledger entries as "seen on the bank statement" without a statement line. */
    public function clear(int $companyId, BankReconciliation $rec, array $bookLineIds, User $actor): void
    {
        $this->assertDraft($companyId, $rec);
        $lines = $this->eligibleBookLines($companyId, $rec, $bookLineIds);

        DB::transaction(function () use ($companyId, $rec, $lines, $actor) {
            foreach ($lines as $l) {
                BankReconciliationItem::create([
                    'company_id'             => $companyId,
                    'bank_reconciliation_id' => $rec->id,
                    'journal_entry_line_id'  => $l->id,
                    'bank_statement_line_id' => null,
                    'amount'                 => $this->money($l->amount),
                    'matched_by'             => 'manual',
                    'created_by'             => $actor->id,
                ]);
            }
            AuditLog::record($actor, 'bank_reconciliation.cleared', $rec, null, ['journal_entry_line_ids' => $lines->pluck('id')->all()]);
        });
    }

    /** Undo: free ledger entries (and so their statement lines) cleared in this draft. */
    public function unclear(int $companyId, BankReconciliation $rec, array $bookLineIds, User $actor): void
    {
        $this->assertDraft($companyId, $rec);

        $deleted = BankReconciliationItem::where('company_id', $companyId)
            ->where('bank_reconciliation_id', $rec->id)
            ->whereIn('journal_entry_line_id', $bookLineIds)
            ->delete();

        if ($deleted === 0) {
            throw new \DomainException('None of the selected entries is cleared in this reconciliation.');
        }

        AuditLog::record($actor, 'bank_reconciliation.uncleared', $rec, ['journal_entry_line_ids' => $bookLineIds], null);
    }

    /** Undo the match of one statement line (all its ledger entries). */
    public function unmatchStatementLine(int $companyId, BankReconciliation $rec, int $statementLineId, User $actor): void
    {
        $this->assertDraft($companyId, $rec);

        $deleted = BankReconciliationItem::where('company_id', $companyId)
            ->where('bank_reconciliation_id', $rec->id)
            ->where('bank_statement_line_id', $statementLineId)
            ->delete();

        if ($deleted === 0) {
            throw new \DomainException('This statement line is not matched in this reconciliation.');
        }

        AuditLog::record($actor, 'bank_reconciliation.unmatched', $rec, ['statement_line_id' => $statementLineId], null);
    }

    // ══════════════════════════════════════════════════════════════
    //  Complete / reopen
    // ══════════════════════════════════════════════════════════════

    public function complete(int $companyId, BankReconciliation $rec, User $actor): BankReconciliation
    {
        $this->assertDraft($companyId, $rec);

        return DB::transaction(function () use ($companyId, $rec, $actor) {
            $report = $this->report($companyId, $rec);

            if ($report['unknown_foreign_count'] > 0) {
                throw new \DomainException("{$report['unknown_foreign_count']} ledger entries on this account have no foreign-currency amount recorded, so the account cannot be reconciled in its own currency. Run the foreign-amount backfill command (fx:backfill-foreign-amounts) first.");
            }
            if (bccomp($report['difference'], '0', 2) !== 0) {
                throw new \DomainException("The reconciliation does not balance — the difference is {$report['difference']}. It can only be completed at exactly 0.00.");
            }

            $before = $rec->toArray();
            $rec->update([
                'status'               => BankReconciliation::STATUS_COMPLETED,
                'book_balance'         => $report['book_balance'],
                'outstanding_deposits' => $report['outstanding_deposits'],
                'outstanding_payments' => $report['outstanding_payments'],
                'unbooked_bank_items'  => $report['unbooked_bank_items'],
                'booked_after_date'    => $report['booked_after_date'],
                'difference'           => $report['difference'],
                'completed_at'         => now(),
                'completed_by'         => $actor->id,
            ]);

            AuditLog::record($actor, 'bank_reconciliation.completed', $rec, $before, $rec->fresh()->toArray());

            return $rec->fresh();
        });
    }

    /** Reopen the latest completed reconciliation of an account (to correct it). */
    public function reopen(int $companyId, BankReconciliation $rec, User $actor): BankReconciliation
    {
        abort_unless((int) $rec->company_id === $companyId, 404);

        if (! $rec->isCompleted()) {
            throw new \DomainException('Only a completed reconciliation can be reopened.');
        }
        $newer = BankReconciliation::where('company_id', $companyId)->where('bank_account_id', $rec->bank_account_id)
            ->where('id', '!=', $rec->id)
            ->where(fn ($q) => $q->where('status', BankReconciliation::STATUS_DRAFT)->orWhere('statement_date', '>', $rec->statement_date))
            ->exists();
        if ($newer) {
            throw new \DomainException('Only the latest reconciliation of an account can be reopened — a newer one exists (or is in progress) for this bank account.');
        }

        $before = $rec->toArray();
        $rec->update([
            'status' => BankReconciliation::STATUS_DRAFT,
            'book_balance' => null, 'outstanding_deposits' => null, 'outstanding_payments' => null,
            'unbooked_bank_items' => null, 'booked_after_date' => null, 'difference' => null,
            'completed_at' => null, 'completed_by' => null,
        ]);

        AuditLog::record($actor, 'bank_reconciliation.reopened', $rec, $before, $rec->fresh()->toArray());

        return $rec->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    //  The workspace and the report
    // ══════════════════════════════════════════════════════════════

    /** Everything the reconcile screen needs. */
    public function workspace(int $companyId, BankReconciliation $rec): array
    {
        abort_unless((int) $rec->company_id === $companyId, 404);

        $ctx    = $this->context($companyId, $rec);
        $report = $this->report($companyId, $rec);

        // A completed reconciliation always shows the figures frozen when it was
        // completed — a late back-dated entry must not silently change an
        // already-signed report (the live counts above stay for information).
        if ($rec->isCompleted() && $rec->difference !== null) {
            $report = array_merge($report, $this->snapshot($rec));
        }

        // Items cleared in THIS reconciliation, keyed by ledger line.
        $items = BankReconciliationItem::where('company_id', $companyId)
            ->where('bank_reconciliation_id', $rec->id)->get()->keyBy('journal_entry_line_id');

        $bookLines = $this->openBookLines($ctx, $rec, includeThisRec: true)->map(function ($b) use ($items) {
            $b->cleared_here           = $items->has($b->id);
            $b->bank_statement_line_id = $items->get($b->id)?->bank_statement_line_id;

            return $b;
        })->values();

        $rawStatements = BankStatementLine::where('company_id', $companyId)
            ->where('bank_account_id', $rec->bank_account_id)
            ->whereDate('line_date', '<=', $rec->statement_date->format('Y-m-d'))
            ->orderBy('line_date')->orderBy('id')
            ->get();

        $linked = BankReconciliationItem::where('company_id', $companyId)
            ->whereIn('bank_statement_line_id', $rawStatements->pluck('id'))
            ->get()->groupBy('bank_statement_line_id');

        $statementLines = $rawStatements->map(function ($s) use ($linked, $rec) {
            $rows = $linked->get($s->id, collect());
            $s->matched_here      = $rows->contains('bank_reconciliation_id', $rec->id);
            $s->matched_elsewhere = $rows->isNotEmpty() && ! $s->matched_here;
            $s->book_line_ids     = $rows->where('bank_reconciliation_id', $rec->id)->pluck('journal_entry_line_id')->values()->all();

            return $s;
        })->filter(fn ($s) => ! $s->matched_elsewhere)->values(); // matched in an earlier, completed reconciliation

        return [
            'reconciliation'   => $rec->load(['bankAccount.bank:id,name_en']),
            'currency_code'    => $ctx['currency_code'],
            'is_native'        => $ctx['native'],
            'report'           => $report,
            'book_lines'       => $bookLines,
            'statement_lines'  => $statementLines,
            'book_lines_capped' => $bookLines->count() >= self::MAX_BOOK_LINES,
        ];
    }

    /**
     * The reconciling-items report (all native-currency strings), computed
     * "as of" the statement date — so a completed reconciliation always
     * reads the same even after later ones exist.
     */
    public function report(int $companyId, BankReconciliation $rec): array
    {
        $ctx  = $this->context($companyId, $rec);
        $date = $rec->statement_date->format('Y-m-d');

        $native = $ctx['native_sql'];

        // Ledger balance at the statement date.
        $bookBalance = (string) ($this->baseBookQuery($ctx)
            ->where('journal_entries.entry_date', '<=', $date)
            ->selectRaw("COALESCE(SUM($native), 0) as total")->value('total') ?? '0');

        // Everything cleared in this or any EARLIER reconciliation of the account.
        $clearedBase = DB::table('bank_reconciliation_items as bri')
            ->join('bank_reconciliations as br', 'br.id', '=', 'bri.bank_reconciliation_id')
            ->join('journal_entry_lines as jel', 'jel.id', '=', 'bri.journal_entry_line_id')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('bri.company_id', $companyId)
            ->where('br.bank_account_id', $rec->bank_account_id)
            ->where('br.statement_date', '<=', $date);

        $clearedAll = (string) ((clone $clearedBase)->sum('bri.amount') ?? '0');
        $clearedUpToDate = (string) ((clone $clearedBase)->where('je.entry_date', '<=', $date)->sum('bri.amount') ?? '0');
        $bookedAfter = bcsub($this->money($clearedAll), $this->money($clearedUpToDate), 2);

        // Outstanding items: ledger lines up to the date not cleared as of the date.
        $outstanding = $this->outstandingQuery($ctx, $rec)->selectRaw("jel.id, $native as amount")->get();
        $deposits = '0.00';
        $payments = '0.00';
        foreach ($outstanding as $o) {
            $amount = $this->money($o->amount);
            if (bccomp($amount, '0', 2) > 0) {
                $deposits = bcadd($deposits, $amount, 2);
            } else {
                $payments = bcadd($payments, bcmul($amount, '-1', 2), 2); // shown as a positive amount
            }
        }

        // Bank items with no ledger entry yet.
        $unmatchedStatements = $this->unmatchedStatementLines($companyId, $rec);
        $unbooked = '0.00';
        foreach ($unmatchedStatements as $s) {
            $unbooked = bcadd($unbooked, $this->money($s->amount), 2);
        }

        $statementBalance = $this->money($rec->statement_ending_balance);
        $adjustedBank = bcsub(bcadd($statementBalance, $deposits, 2), $payments, 2);
        $adjustedBook = bcadd(bcadd($this->money($bookBalance), $unbooked, 2), $bookedAfter, 2);
        $difference   = bcsub($adjustedBank, $adjustedBook, 2);

        // Lines whose foreign amount was never recorded count as zero — must be fixed before completion.
        $unknown = $ctx['native']
            ? (int) $this->baseBookQuery($ctx)->where('journal_entries.entry_date', '<=', $date)->whereNull('jel.amount_currency')->count()
            : 0;

        return [
            'statement_balance'     => $statementBalance,
            'outstanding_deposits'  => $deposits,
            'outstanding_payments'  => $payments,
            'adjusted_bank_balance' => $adjustedBank,
            'book_balance'          => $this->money($bookBalance),
            'unbooked_bank_items'   => $unbooked,
            'booked_after_date'     => $bookedAfter,
            'adjusted_book_balance' => $adjustedBook,
            'difference'            => $difference,
            'unknown_foreign_count' => $unknown,
            'unmatched_statement_count' => $unmatchedStatements->count(),
            'outstanding_count'     => $outstanding->count(),
        ];
    }

    /** The report figures frozen on a completed reconciliation. */
    private function snapshot(BankReconciliation $rec): array
    {
        $statement = $this->money($rec->statement_ending_balance);
        $deposits  = $this->money($rec->outstanding_deposits);
        $payments  = $this->money($rec->outstanding_payments);
        $book      = $this->money($rec->book_balance);
        $unbooked  = $this->money($rec->unbooked_bank_items);
        $after     = $this->money($rec->booked_after_date);

        return [
            'statement_balance'     => $statement,
            'outstanding_deposits'  => $deposits,
            'outstanding_payments'  => $payments,
            'adjusted_bank_balance' => bcsub(bcadd($statement, $deposits, 2), $payments, 2),
            'book_balance'          => $book,
            'unbooked_bank_items'   => $unbooked,
            'booked_after_date'     => $after,
            'adjusted_book_balance' => bcadd(bcadd($book, $unbooked, 2), $after, 2),
            'difference'            => $this->money($rec->difference),
        ];
    }

    /** Rows for the Excel/print version of the report. */
    public function reportDetail(int $companyId, BankReconciliation $rec): array
    {
        $ctx    = $this->context($companyId, $rec);
        $native = $ctx['native_sql'];

        $outstanding = $this->outstandingQuery($ctx, $rec)
            ->selectRaw("jel.id, je.entry_date, je.entry_number, COALESCE(jel.description, je.narration) as description, $native as amount")
            ->orderBy('je.entry_date')->orderBy('jel.id')->get();

        $report = $this->report($companyId, $rec);
        if ($rec->isCompleted() && $rec->difference !== null) {
            $report = array_merge($report, $this->snapshot($rec));
        }

        return [
            'report'               => $report,
            'currency_code'        => $ctx['currency_code'],
            'outstanding_deposits' => $outstanding->filter(fn ($o) => bccomp($this->money($o->amount), '0', 2) > 0)->values(),
            'outstanding_payments' => $outstanding->filter(fn ($o) => bccomp($this->money($o->amount), '0', 2) < 0)->values(),
            'unbooked_bank_items'  => $this->unmatchedStatementLines($companyId, $rec)->values(),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  Private — queries
    // ══════════════════════════════════════════════════════════════

    /** Account facts shared by every query: ledger account, currency, native-or-functional SQL. */
    private function context(int $companyId, BankReconciliation $rec): array
    {
        $account = BankAccount::where('company_id', $companyId)->findOrFail($rec->bank_account_id);
        $coaId   = (int) $account->coa_account_id;

        $native = array_key_exists($coaId, $this->foreignLedger->accountCurrencyMap($companyId, [$coaId]));

        return [
            'company_id'    => $companyId,
            'coa_id'        => $coaId,
            'native'        => $native,
            'native_sql'    => $native ? 'COALESCE(jel.amount_currency, 0)' : '(jel.debit - jel.credit)',
            'currency_code' => $this->currencyCodes([$account->currency_id])[$account->currency_id] ?? null,
        ];
    }

    /** All ledger lines of the account (posted or since reversed — both stay visible and net to zero). */
    private function baseBookQuery(array $ctx)
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries', 'journal_entries.id', '=', 'jel.journal_entry_id')
            ->where('jel.company_id', $ctx['company_id'])
            ->where('jel.chart_of_account_id', $ctx['coa_id'])
            ->whereIn('journal_entries.status', ['posted', 'reversed']);
    }

    /** Ledger lines up to the statement date not yet cleared by this or an earlier reconciliation. */
    private function outstandingQuery(array $ctx, BankReconciliation $rec)
    {
        $date = $rec->statement_date->format('Y-m-d');

        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.company_id', $ctx['company_id'])
            ->where('jel.chart_of_account_id', $ctx['coa_id'])
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.entry_date', '<=', $date)
            ->whereNotExists(function ($q) use ($rec, $date) {
                $q->select(DB::raw(1))->from('bank_reconciliation_items as bri')
                    ->join('bank_reconciliations as br', 'br.id', '=', 'bri.bank_reconciliation_id')
                    ->whereColumn('bri.journal_entry_line_id', 'jel.id')
                    ->where('br.bank_account_id', $rec->bank_account_id)
                    ->where('br.statement_date', '<=', $date);
            });
    }

    /**
     * Ledger lines shown on the reconcile screen: open ones (not cleared
     * anywhere), plus — when $includeThisRec — the ones cleared in this
     * reconciliation. Cut off at today; newest 1000.
     */
    private function openBookLines(array $ctx, BankReconciliation $rec, bool $includeThisRec = false, ?array $onlyIds = null): Collection
    {
        $native = $ctx['native_sql'];

        $q = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.company_id', $ctx['company_id'])
            ->where('jel.chart_of_account_id', $ctx['coa_id'])
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereDate('je.entry_date', '<=', now()->format('Y-m-d'))
            ->where(function ($w) use ($rec, $includeThisRec) {
                $w->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))->from('bank_reconciliation_items as bri')
                        ->whereColumn('bri.journal_entry_line_id', 'jel.id');
                });
                if ($includeThisRec) {
                    $w->orWhereExists(function ($q) use ($rec) {
                        $q->select(DB::raw(1))->from('bank_reconciliation_items as bri')
                            ->whereColumn('bri.journal_entry_line_id', 'jel.id')
                            ->where('bri.bank_reconciliation_id', $rec->id);
                    });
                }
            })
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('jel.id', $onlyIds))
            ->selectRaw("jel.id, je.entry_date, je.entry_number, je.id as journal_entry_id, je.narration, jel.description, jel.amount_currency, $native as amount")
            ->orderBy('je.entry_date')->orderBy('jel.id')
            ->when($onlyIds === null, fn ($q) => $q->limit(self::MAX_BOOK_LINES));

        return collect($q->get())->map(function ($row) {
            $row->amount = $this->money($row->amount);

            return $row;
        });
    }

    /** Statement lines (up to the statement date) not matched in this or an earlier reconciliation. */
    private function unmatchedStatementLines(int $companyId, BankReconciliation $rec): Collection
    {
        $date = $rec->statement_date->format('Y-m-d');

        return BankStatementLine::where('company_id', $companyId)
            ->where('bank_account_id', $rec->bank_account_id)
            ->whereDate('line_date', '<=', $date)
            ->whereNotExists(function ($q) use ($rec, $date) {
                $q->select(DB::raw(1))->from('bank_reconciliation_items as bri')
                    ->join('bank_reconciliations as br', 'br.id', '=', 'bri.bank_reconciliation_id')
                    ->whereColumn('bri.bank_statement_line_id', 'bank_statement_lines.id')
                    ->where('br.bank_account_id', $rec->bank_account_id)
                    ->where('br.statement_date', '<=', $date);
            })
            ->orderBy('line_date')->orderBy('id')
            ->get();
    }

    /** Validate and load the ledger lines a user wants to clear/match. */
    private function eligibleBookLines(int $companyId, BankReconciliation $rec, array $bookLineIds): Collection
    {
        $bookLineIds = array_values(array_unique(array_map('intval', $bookLineIds)));
        if ($bookLineIds === []) {
            throw new \DomainException('Select at least one ledger entry.');
        }

        $ctx   = $this->context($companyId, $rec);
        $lines = $this->openBookLines($ctx, $rec, false, $bookLineIds)->values();

        if ($lines->count() !== count($bookLineIds)) {
            throw new \DomainException('Some of the selected ledger entries are not available — they belong to another account, are already cleared, or are dated in the future.');
        }
        if ($ctx['native'] && $lines->contains(fn ($l) => $l->amount_currency === null)) {
            throw new \DomainException('One of the selected entries has no foreign-currency amount recorded — run the foreign-amount backfill command first.');
        }

        return $lines;
    }

    // ══════════════════════════════════════════════════════════════
    //  Private — helpers
    // ══════════════════════════════════════════════════════════════

    private function assertDraft(int $companyId, BankReconciliation $rec): void
    {
        abort_unless((int) $rec->company_id === $companyId, 404);

        if (! $rec->isDraft()) {
            throw new \DomainException('This reconciliation is completed — reopen it first to change it.');
        }
    }

    /** 2-decimal string, rounded half away from zero, via bcmath. */
    private function money(string|float|int|null $value): string
    {
        $v = $value === null || $value === '' ? '0' : (is_float($value) ? number_format($value, 10, '.', '') : (string) $value);
        $v = str_replace(',', '', trim($v));
        if (! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new \DomainException("'{$value}' is not a valid amount.");
        }
        $half = bccomp($v, '0', 10) >= 0 ? '0.005' : '-0.005';

        return bcadd($v, $half, 2);
    }

    private function amountKey(string|float|int|null $amount): string
    {
        return $this->money($amount);
    }

    private function containsText(object $bookLine, string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            return false;
        }
        $hay = mb_strtolower(($bookLine->description ?? '').' '.($bookLine->narration ?? '').' '.($bookLine->entry_number ?? ''));

        return str_contains($hay, $needle);
    }

    private function baseHash(int $accountId, string $date, string $amount, string $reference, string $description): string
    {
        return sha1(implode('|', [$accountId, $date, $amount, mb_strtolower(trim($reference)), mb_strtolower(trim($description))]));
    }

    private function hashExists(int $companyId, int $accountId, string $hash): bool
    {
        return BankStatementLine::where('company_id', $companyId)->where('bank_account_id', $accountId)->where('line_hash', $hash)->exists();
    }

    /**
     * Typed-in line: the first free occurrence number, so two genuinely
     * identical lines on one day (e.g. two 5.00 fees) can both be kept.
     */
    private function nextManualHash(int $companyId, int $accountId, string $date, string $amount, string $reference, string $description): string
    {
        $base = $this->baseHash($accountId, $date, $amount, $reference, $description);
        for ($n = 1; ; $n++) {
            $hash = sha1($base.'#'.$n);
            if (! $this->hashExists($companyId, $accountId, $hash)) {
                return $hash;
            }
        }
    }

    /** Map header names (English / Arabic) to column positions. */
    private function detectColumns(array $header): array
    {
        $map = ['date' => null, 'description' => null, 'reference' => null, 'in' => null, 'out' => null, 'amount' => null];

        $names = [
            'date'        => ['date', 'transaction date', 'value date', 'posting date', 'التاريخ', 'تاريخ'],
            'description' => ['description', 'details', 'narration', 'particulars', 'memo', 'البيان', 'الوصف', 'التفاصيل'],
            'reference'   => ['reference', 'ref', 'ref no', 'reference no', 'cheque no', 'cheque number', 'check no', 'المرجع', 'رقم المرجع', 'رقم الشيك'],
            'out'         => ['debit', 'withdrawal', 'withdrawals', 'paid out', 'money out', 'dr', 'مدين', 'سحب', 'سحوبات', 'منصرف'],
            'in'          => ['credit', 'deposit', 'deposits', 'paid in', 'money in', 'cr', 'دائن', 'إيداع', 'ايداع', 'ايداعات', 'إيداعات', 'وارد'],
            'amount'      => ['amount', 'المبلغ', 'القيمة'],
        ];

        foreach ($header as $idx => $cell) {
            $label = mb_strtolower(trim((string) $cell));
            foreach ($names as $field => $aliases) {
                if ($map[$field] === null && in_array($label, $aliases, true)) {
                    $map[$field] = $idx;
                }
            }
        }

        return $map;
    }

    private function parseDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new \DomainException('the date is missing.');
        }
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        $ts = strtotime(str_replace('/', '-', trim((string) $value)));
        if ($ts === false) {
            throw new \DomainException("'{$value}' is not a valid date.");
        }

        return date('Y-m-d', $ts);
    }

    /** Signed amount: + money in, − money out. */
    private function parseAmount(array $row, array $columns): string
    {
        $clean = function ($v): string {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                return '0';
            }
            $negative = str_starts_with($v, '(') && str_ends_with($v, ')');
            $v = str_replace([',', ' ', '(', ')'], '', $v);
            if (! is_numeric($v)) {
                throw new \DomainException("'{$v}' is not a valid amount.");
            }

            return $negative ? '-'.ltrim($v, '-') : $v;
        };

        if ($columns['amount'] !== null && $columns['in'] === null && $columns['out'] === null) {
            return $this->money($clean($row[$columns['amount']] ?? null));
        }

        $in  = $columns['in'] !== null ? $clean($row[$columns['in']] ?? null) : '0';
        $out = $columns['out'] !== null ? $clean($row[$columns['out']] ?? null) : '0';

        // Both columns hold positive figures in a bank file; money out is subtracted.
        return $this->money(bcsub($this->absStr($in), $this->absStr($out), 10));
    }

    private function absStr(string $v): string
    {
        return ltrim($v, '-') === '' ? '0' : ltrim($v, '-');
    }

    /** @param array<int,int|null> $ids @return array<int,string> */
    private function currencyCodes(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Currency::withoutGlobalScopes()->whereIn('id', $ids)->pluck('code', 'id')->all();
    }
}
