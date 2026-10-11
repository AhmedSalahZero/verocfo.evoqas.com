<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerCreditBalanceService
//
//  Audit M-9 (Oct 2026) — what happens when a Credit Note is worth
//  more than the invoice it credits still owes, which is the normal
//  case when that invoice was already paid.
//
//  The surplus is a CUSTOMER CREDIT BALANCE: money the company owes
//  the customer. It sits in 235 Advances from Customers (the same
//  liability that holds down payments) and has exactly two ways out:
//
//    APPLY    Dr 235 / Cr Accounts Receivable — used up against one of
//             the customer's open invoices (reduces what it owes).
//             Undo reverses the entry and puts the balance back.
//
//    REFUND   Dr 235 / Cr cash safe or bank account — paid back in
//             cash. If the customer had already withheld tax (WHT) on
//             the paid invoice, the matching share of that withholding
//             is given up too: Cr 142 Debit WHT Receivable, and only
//             the rest is paid in cash. The accountant may change the
//             WHT figure within its limits. Reversing a refund puts the
//             balance back.
//
//  Everything is in the functional currency; the balance is not
//  revalued. A refund is paid from a functional-currency safe / bank
//  account only.
//
//  Control (same maker-checker idea as audit H-3): a refund must be
//  made by someone other than the person who posted the Credit Note
//  that created the balance. The platform Super Admin is exempt.
//
//  bcmath throughout (Rule 4); every write is audit-logged (Rule 5).
//
//  @layer    Service
//  @depends  CustomerCreditBalance, CustomerCreditApplication,
//            CustomerRefund, CustomerInvoice, CreditNote, ChartOfAccount,
//            BankAccount, JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CreditNote;
use App\Models\CustomerCreditApplication;
use App\Models\CustomerCreditBalance;
use App\Models\CustomerInvoice;
use App\Models\CustomerRefund;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerCreditBalanceService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    // ── Reading ───────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CustomerCreditBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with([
                'partner:id,code,name_en,name_ar',
                'creditNote:id,credit_note_code,posted_by',
                'customerInvoice:id,invoice_code',
                'applications.customerInvoice:id,invoice_code',
                'refunds',
            ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('balance_code', 'like', "%{$s}%"))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('credit_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    /** Total still owed to customers across every open balance (functional). */
    public function totalOpen(int $companyId): string
    {
        return (string) CustomerCreditBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', CustomerCreditBalance::STATUS_OPEN)
            ->sum('remaining_amount');
    }

    /** Where a refund can be paid from: active functional-currency bank accounts and cash safes (under 131). */
    public function refundSources(int $companyId): array
    {
        $functionalId = CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id');

        $banks = BankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->when($functionalId, fn ($q) => $q->where('currency_id', $functionalId))
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_number']);

        $cashParent = ChartOfAccount::findByCode($companyId, '131');
        $safes = $cashParent
            ? ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->postable()->active()
                ->where('parent_id', $cashParent->id)->orderBy('code')->get(['id', 'code', 'name_en'])
            : collect();

        return ['bankAccounts' => $banks, 'safeAccounts' => $safes];
    }

    /** The customer's posted invoices that still owe something — where a balance can be applied. */
    public function openInvoices(CustomerCreditBalance $balance): Collection
    {
        return CustomerInvoice::withoutGlobalScopes()
            ->where('company_id', $balance->company_id)
            ->where('partner_id', $balance->partner_id)
            ->where('status', CustomerInvoice::STATUS_POSTED)
            ->where('amount_outstanding', '>', 0)
            ->orderBy('invoice_date')
            ->get(['id', 'invoice_code', 'invoice_date', 'currency_id', 'exchange_rate', 'amount_outstanding']);
    }

    // ── Created by CreditNoteService::post() ──────────────────────

    /**
     * Opens the balance for the surplus of a Credit Note. $excess is
     * functional. The withholding share is the fraction of the invoice
     * the customer withheld tax on, applied to the surplus.
     */
    public function createFromCreditNote(CreditNote $creditNote, CustomerInvoice $invoice, string $excess, JournalEntry $entry): CustomerCreditBalance
    {
        $whtPortion = '0.00';
        if (bccomp((string) $invoice->amount_wht_deducted, '0', 2) > 0 && bccomp((string) $invoice->invoice_value_functional, '0', 2) > 0) {
            $whtPortion = $this->round2(bcdiv(bcmul($excess, (string) $invoice->amount_wht_deducted, 6), (string) $invoice->invoice_value_functional, 6));
            if (bccomp($whtPortion, $excess, 2) > 0) {
                $whtPortion = $excess;
            }
        }

        return CustomerCreditBalance::create([
            'company_id'          => $creditNote->company_id,
            'balance_code'        => $this->nextCode($creditNote->company_id, 'CCB', CustomerCreditBalance::class, 'balance_code'),
            'partner_id'          => $creditNote->partner_id,
            'credit_note_id'      => $creditNote->id,
            'customer_invoice_id' => $invoice->id,
            'credit_date'         => $creditNote->credit_date,
            'currency_id'         => $creditNote->currency_id,
            'exchange_rate'       => $creditNote->exchange_rate,
            'original_amount'     => $excess,
            'applied_amount'      => '0',
            'refunded_amount'     => '0',
            'remaining_amount'    => $excess,
            'wht_portion'         => $whtPortion,
            'wht_remaining'       => $whtPortion,
            'status'              => CustomerCreditBalance::STATUS_OPEN,
            'journal_entry_id'    => $entry->id,
            'created_by'          => $creditNote->posted_by,
        ]);
    }

    /**
     * Called when a posted Credit Note is reversed: the balance it
     * created disappears with it — but only if nothing has been taken
     * out of it yet.
     */
    public function cancelForCreditNote(CreditNote $creditNote): void
    {
        $balance = CustomerCreditBalance::withoutGlobalScopes()->where('credit_note_id', $creditNote->id)->lockForUpdate()->first();
        if (! $balance || $balance->status === CustomerCreditBalance::STATUS_REVERSED) {
            return;
        }

        $used = CustomerCreditApplication::withoutGlobalScopes()
            ->where('customer_credit_balance_id', $balance->id)->where('status', CustomerCreditApplication::STATUS_APPLIED)->exists()
            || CustomerRefund::withoutGlobalScopes()
                ->where('customer_credit_balance_id', $balance->id)->where('status', CustomerRefund::STATUS_POSTED)->exists();

        if ($used) {
            throw new \DomainException(
                "This Credit Note created Customer Credit Balance {$balance->balance_code}, which has already been applied or refunded — undo those first, then reverse the Credit Note."
            );
        }

        $balance->update([
            'status'           => CustomerCreditBalance::STATUS_REVERSED,
            'remaining_amount' => '0',
            'wht_remaining'    => '0',
        ]);
    }

    // ── Apply to an invoice ───────────────────────────────────────

    /** @param array{customer_invoice_id:int, amount:string|float|int, application_date?:?string} $data */
    public function apply(CustomerCreditBalance $balance, array $data, User $actor): CustomerCreditApplication
    {
        return DB::transaction(function () use ($balance, $data, $actor) {
            $balance = CustomerCreditBalance::withoutGlobalScopes()->lockForUpdate()->findOrFail($balance->id);
            $this->assertOpen($balance);

            $invoice = CustomerInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($data['customer_invoice_id']);
            if ((int) $invoice->company_id !== (int) $balance->company_id || (int) $invoice->partner_id !== (int) $balance->partner_id) {
                throw new \DomainException('This invoice belongs to a different customer.');
            }
            if ($invoice->status !== CustomerInvoice::STATUS_POSTED) {
                throw new \DomainException('A credit balance can only be applied to a posted invoice.');
            }

            $amount = $this->money($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                throw new \DomainException('The amount to apply must be more than zero.');
            }
            if (bccomp($amount, (string) $balance->remaining_amount, 2) > 0) {
                throw new \DomainException("Only {$balance->remaining_amount} is left on this credit balance.");
            }
            if (bccomp($amount, (string) $invoice->amount_outstanding, 2) > 0) {
                throw new \DomainException("Invoice {$invoice->invoice_code} only has {$invoice->amount_outstanding} still outstanding.");
            }

            $date = $this->dateOrToday($data['application_date'] ?? null, $balance);

            $whtReleased = $this->shareOf((string) $balance->wht_remaining, $amount, (string) $balance->remaining_amount);

            $entry = $this->journalEntryService->post(
                companyId: $balance->company_id,
                entryDate: $date,
                lines: [
                    ['chart_of_account_id' => $this->account($balance->company_id, '235')->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Credit balance '.$balance->balance_code.' applied → '.$invoice->invoice_code],
                    ['chart_of_account_id' => $this->arAccount($balance->company_id, (int) $invoice->currency_id)->id, 'debit' => '0', 'credit' => $amount, 'description' => 'Credit balance '.$balance->balance_code.' applied → '.$invoice->invoice_code],
                ],
                sourceType: 'customer_credit_application',
                sourceId: $balance->id,
                narration: "Credit balance {$balance->balance_code} applied to Invoice {$invoice->invoice_code}",
                actor: $actor,
            );

            $application = CustomerCreditApplication::create([
                'company_id'                 => $balance->company_id,
                'customer_credit_balance_id' => $balance->id,
                'customer_invoice_id'        => $invoice->id,
                'application_date'           => $date,
                'amount'                     => $amount,
                'wht_released'               => $whtReleased,
                'status'                     => CustomerCreditApplication::STATUS_APPLIED,
                'journal_entry_id'           => $entry->id,
                'created_by'                 => $actor->id,
            ]);

            $invoice->update([
                'amount_credited'    => bcadd((string) $invoice->amount_credited, $amount, 2),
                'amount_outstanding' => bcsub((string) $invoice->amount_outstanding, $amount, 2),
            ]);

            $this->moveBalance($balance, applied: $amount, whtOut: $whtReleased);

            AuditLog::record($actor, 'customer_credit.applied', $balance, null, ['invoice' => $invoice->invoice_code, 'amount' => $amount]);

            return $application->fresh(['customerInvoice']);
        });
    }

    public function undoApplication(CustomerCreditApplication $application, User $actor): void
    {
        DB::transaction(function () use ($application, $actor) {
            $application = CustomerCreditApplication::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->id);
            if ($application->status !== CustomerCreditApplication::STATUS_APPLIED) {
                throw new \DomainException('This application has already been undone.');
            }

            $balance = CustomerCreditBalance::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->customer_credit_balance_id);
            if ($balance->status === CustomerCreditBalance::STATUS_REVERSED) {
                throw new \DomainException('The Credit Note behind this balance has been reversed.');
            }
            $invoice = CustomerInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($application->customer_invoice_id);

            $this->journalEntryService->reverse(
                JournalEntry::findOrFail($application->journal_entry_id),
                $actor,
                'Credit balance application undone — '.$balance->balance_code.' / '.$invoice->invoice_code
            );

            $invoice->update([
                'amount_credited'    => bcsub((string) $invoice->amount_credited, (string) $application->amount, 2),
                'amount_outstanding' => bcadd((string) $invoice->amount_outstanding, (string) $application->amount, 2),
            ]);

            $this->moveBalance($balance, applied: bcmul((string) $application->amount, '-1', 2), whtOut: bcmul((string) $application->wht_released, '-1', 2));

            $application->update([
                'status'      => CustomerCreditApplication::STATUS_REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ]);

            AuditLog::record($actor, 'customer_credit.application_undone', $balance, null, ['invoice' => $invoice->invoice_code, 'amount' => (string) $application->amount]);
        });
    }

    // ── Cash refund ───────────────────────────────────────────────

    /**
     * @param array{amount:string|float|int, refund_date?:?string, source_type:string, bank_account_id?:?int, safe_account_id?:?int, reference?:?string, notes?:?string, wht_adjustment?:string|float|int|null} $data
     */
    public function refund(CustomerCreditBalance $balance, array $data, User $actor): CustomerRefund
    {
        return DB::transaction(function () use ($balance, $data, $actor) {
            $balance = CustomerCreditBalance::withoutGlobalScopes()->lockForUpdate()->findOrFail($balance->id);
            $this->assertOpen($balance);
            $this->assertDifferentPerson($balance, $actor);

            $amount = $this->money($data['amount']);
            if (bccomp($amount, '0', 2) <= 0) {
                throw new \DomainException('The refund amount must be more than zero.');
            }
            if (bccomp($amount, (string) $balance->remaining_amount, 2) > 0) {
                throw new \DomainException("Only {$balance->remaining_amount} is left on this credit balance.");
            }

            // WHT given up with this refund: the proportional share unless
            // the accountant sets it, and never more than the limits.
            $maxWht = bccomp((string) $balance->wht_remaining, $amount, 2) < 0 ? (string) $balance->wht_remaining : $amount;
            if (array_key_exists('wht_adjustment', $data) && $data['wht_adjustment'] !== null && $data['wht_adjustment'] !== '') {
                $wht = $this->money($data['wht_adjustment']);
                if (bccomp($wht, '0', 2) < 0 || bccomp($wht, $maxWht, 2) > 0) {
                    throw new \DomainException("The withholding tax adjustment must be between 0 and {$maxWht}.");
                }
            } else {
                $wht = $this->shareOf((string) $balance->wht_remaining, $amount, (string) $balance->remaining_amount);
            }

            $cash = bcsub($amount, $wht, 2);
            if (bccomp($cash, '0', 2) <= 0) {
                throw new \DomainException('Nothing would be paid in cash — apply the balance to an invoice instead.');
            }

            $date   = $this->dateOrToday($data['refund_date'] ?? null, $balance);
            $source = $this->resolveSource($balance->company_id, (string) $data['source_type'], $data);

            $lines = [
                ['chart_of_account_id' => $this->account($balance->company_id, '235')->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Customer refund — '.$balance->balance_code],
                ['chart_of_account_id' => $source->id, 'debit' => '0', 'credit' => $cash, 'description' => 'Customer refund — '.$balance->balance_code],
            ];
            if (bccomp($wht, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $this->account($balance->company_id, '142')->id, 'debit' => '0', 'credit' => $wht, 'description' => 'WHT given up on refund — '.$balance->balance_code];
            }

            $refund = CustomerRefund::create([
                'company_id'                 => $balance->company_id,
                'refund_code'                => $this->nextCode($balance->company_id, 'CRF', CustomerRefund::class, 'refund_code'),
                'customer_credit_balance_id' => $balance->id,
                'partner_id'                 => $balance->partner_id,
                'refund_date'                => $date,
                'amount'                     => $amount,
                'wht_adjustment'             => $wht,
                'cash_amount'                => $cash,
                'source_type'                => $data['source_type'],
                'bank_account_id'            => $data['source_type'] === CustomerRefund::SOURCE_BANK_ACCOUNT ? $data['bank_account_id'] : null,
                'safe_account_id'            => $data['source_type'] === CustomerRefund::SOURCE_CASH_SAFE ? $data['safe_account_id'] : null,
                'reference'                  => $data['reference'] ?? null,
                'notes'                      => $data['notes'] ?? null,
                'status'                     => CustomerRefund::STATUS_POSTED,
                'created_by'                 => $actor->id,
            ]);

            $entry = $this->journalEntryService->post(
                companyId: $balance->company_id,
                entryDate: $date,
                lines: $lines,
                sourceType: 'customer_refund',
                sourceId: $refund->id,
                narration: "Customer refund {$refund->refund_code} — credit balance {$balance->balance_code}",
                actor: $actor,
            );
            $refund->update(['journal_entry_id' => $entry->id]);

            $this->moveBalance($balance, refunded: $amount, whtOut: $wht);

            AuditLog::record($actor, 'customer_refund.posted', $refund, null, $refund->fresh()->toArray());

            return $refund->fresh();
        });
    }

    public function reverseRefund(CustomerRefund $refund, User $actor, string $reason): CustomerRefund
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Please give a reason for reversing this refund.');
        }

        return DB::transaction(function () use ($refund, $actor, $reason) {
            $refund = CustomerRefund::withoutGlobalScopes()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== CustomerRefund::STATUS_POSTED) {
                throw new \DomainException('This refund has already been reversed.');
            }

            $balance = CustomerCreditBalance::withoutGlobalScopes()->lockForUpdate()->findOrFail($refund->customer_credit_balance_id);
            if ($balance->status === CustomerCreditBalance::STATUS_REVERSED) {
                throw new \DomainException('The Credit Note behind this balance has been reversed.');
            }

            $before = $refund->toArray();

            $this->journalEntryService->reverse(
                JournalEntry::findOrFail($refund->journal_entry_id),
                $actor,
                'Reversal of customer refund '.$refund->refund_code.' — '.$reason
            );

            $this->moveBalance($balance, refunded: bcmul((string) $refund->amount, '-1', 2), whtOut: bcmul((string) $refund->wht_adjustment, '-1', 2));

            $refund->update([
                'status'          => CustomerRefund::STATUS_REVERSED,
                'reversed_by'     => $actor->id,
                'reversed_at'     => now(),
                'reversal_reason' => $reason,
            ]);

            AuditLog::record($actor, 'customer_refund.reversed', $refund, $before, $refund->fresh()->toArray());

            return $refund->fresh();
        });
    }

    // ── Internals ─────────────────────────────────────────────────

    /**
     * Moves the balance's running figures. A negative argument undoes
     * an earlier move. Status follows the remaining amount.
     */
    private function moveBalance(CustomerCreditBalance $balance, string $applied = '0', string $refunded = '0', string $whtOut = '0'): void
    {
        $remaining = bcsub(bcsub((string) $balance->remaining_amount, $applied, 2), $refunded, 2);
        $wht       = bcsub((string) $balance->wht_remaining, $whtOut, 2);

        if (bccomp($remaining, '0', 2) < 0) {
            throw new \DomainException('This credit balance cannot go below zero.');
        }
        if (bccomp($wht, '0', 2) < 0) {
            $wht = '0.00';
        }
        if (bccomp($remaining, '0', 2) === 0) {
            $wht = '0.00';
        }

        $balance->update([
            'applied_amount'   => bcadd((string) $balance->applied_amount, $applied, 2),
            'refunded_amount'  => bcadd((string) $balance->refunded_amount, $refunded, 2),
            'remaining_amount' => $remaining,
            'wht_remaining'    => $wht,
            'status'           => bccomp($remaining, '0', 2) === 0 ? CustomerCreditBalance::STATUS_CLOSED : CustomerCreditBalance::STATUS_OPEN,
        ]);
    }

    private function assertOpen(CustomerCreditBalance $balance): void
    {
        if ($balance->status === CustomerCreditBalance::STATUS_REVERSED) {
            throw new \DomainException('The Credit Note behind this balance has been reversed.');
        }
        if ($balance->status !== CustomerCreditBalance::STATUS_OPEN || bccomp((string) $balance->remaining_amount, '0', 2) <= 0) {
            throw new \DomainException('This credit balance has nothing left.');
        }
    }

    /** A refund must be made by someone other than whoever posted the Credit Note (Super Admin exempt). */
    private function assertDifferentPerson(CustomerCreditBalance $balance, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $postedBy = $balance->creditNote()->withoutGlobalScopes()->value('posted_by');
        if ($postedBy && (int) $postedBy === (int) $actor->id) {
            throw new \DomainException('You posted the Credit Note behind this balance — a refund must be made by someone else.');
        }
    }

    private function dateOrToday(?string $date, CustomerCreditBalance $balance): string
    {
        $date = $date ? substr($date, 0, 10) : now()->toDateString();

        if ($date < $balance->credit_date->toDateString()) {
            throw new \DomainException('The date cannot be before the Credit Note ('.$balance->credit_date->toDateString().').');
        }

        return $date;
    }

    /** Cash safe (under 131) or a bank account in the functional currency. */
    private function resolveSource(int $companyId, string $sourceType, array $data): ChartOfAccount
    {
        if ($sourceType === CustomerRefund::SOURCE_BANK_ACCOUNT) {
            $bank = BankAccount::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $data['bank_account_id'] ?? 0)
                ->first();

            if (! $bank || ! $bank->is_active) {
                throw new \DomainException('The selected bank account does not belong to this company or is not active.');
            }
            if (! $bank->coa_account_id) {
                throw new \DomainException('The selected bank account is missing its Chart of Accounts link.');
            }

            $functionalId = CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id');
            if ($functionalId && (int) $bank->currency_id !== (int) $functionalId) {
                throw new \DomainException('A refund is paid from a bank account in the company\'s main currency.');
            }

            return ChartOfAccount::withoutGlobalScopes()->findOrFail($bank->coa_account_id);
        }

        if ($sourceType !== CustomerRefund::SOURCE_CASH_SAFE) {
            throw new \DomainException('Choose a cash safe or a bank account to pay the refund from.');
        }

        $cashParent = ChartOfAccount::findByCode($companyId, '131');
        $safe = $cashParent
            ? ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->postable()->active()
                ->where('parent_id', $cashParent->id)->where('id', (int) ($data['safe_account_id'] ?? 0))->first()
            : null;

        if (! $safe) {
            throw new \DomainException('The selected account is not a cash safe. Choose an active safe under 131 Cash.');
        }

        return $safe;
    }

    private function account(int $companyId, string $code): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} — check the seeder.");
    }

    /** Same Local / Export receivable the invoice itself uses. */
    private function arAccount(int $companyId, int $invoiceCurrencyId): ChartOfAccount
    {
        $functionalId = CompanyActiveCurrency::withoutGlobalScopes()->where('company_id', $companyId)->where('is_functional', true)->value('currency_id');

        return $this->account($companyId, ($functionalId && $invoiceCurrencyId === (int) $functionalId) ? '133001' : '133002');
    }

    /** $part of $total, taken from $pool — the whole pool when $part is the whole total. */
    private function shareOf(string $pool, string $part, string $total): string
    {
        if (bccomp($pool, '0', 2) <= 0 || bccomp($total, '0', 2) <= 0) {
            return '0.00';
        }
        if (bccomp($part, $total, 2) >= 0) {
            return $pool;
        }

        return $this->round2(bcdiv(bcmul($pool, $part, 6), $total, 6));
    }

    private function money(mixed $value): string
    {
        $s = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \DomainException('Enter a valid amount.');
        }

        return $this->round2($s);
    }

    /** Half-up to 2 places for positive values; negatives are not expected. */
    private function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }

    private function nextCode(int $companyId, string $prefix, string $model, string $column): string
    {
        // Audit L-7: atomic counter shared with every other document code.
        return \App\Support\DocumentNumber::code($companyId, $model, $column, $prefix);
    }
}
