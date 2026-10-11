<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerDebitNoteService
//
//  All business logic for Customer Debit Notes — Sales Cycle,
//  companion to Credit Note. Every Debit Note increases part of a
//  specific, already-posted Customer Invoice (migration 000194-000195)
//  — there is no free-floating debit note, mirroring Credit Note's own
//  rule exactly (confirmed with Mahmoud).
//
//  Posting shape:
//    Dr 133001/133002 Accounts Receivable (Local/Export — same
//       resolution as the invoice being debited)
//    Cr revenue_account_id (per line — same account the invoice line
//       originally credited)
//    Cr 226001 VAT Output (if any line carries VAT)
//
//  This is the exact mirror of CreditNoteService's journal entry,
//  with every debit/credit swapped.
//
//  The debited amount increases the invoice's amount_outstanding (via
//  amount_debited — see migration 000196) — there is no upper cap to
//  check here (unlike Credit Note, which cannot exceed what's still
//  open), since this document only ever makes the invoice's balance
//  bigger, never smaller.
//
//  @layer    Service
//  @depends  CustomerDebitNote, CustomerDebitNoteLine, CustomerInvoice,
//            CustomerInvoiceLine, ChartOfAccount, CompanyActiveCurrency,
//            JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteLine;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerDebitNoteService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CustomerDebitNote::with(['partner:id,name_en,name_ar', 'customerInvoice:id,invoice_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('debit_note_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) — inherits currency/rate from the invoice ───

    public function create(int $companyId, array $data, User $actor): CustomerDebitNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $invoice = CustomerInvoice::findOrFail($data['customer_invoice_id']);
            if (! $invoice->isPosted()) {
                throw new \DomainException('A Debit Note can only be raised against a posted Customer Invoice.');
            }

            $data['company_id']      = $companyId;
            $data['partner_id']      = $invoice->partner_id;
            $data['currency_id']     = $invoice->currency_id;
            $data['exchange_rate']   = $invoice->exchange_rate;
            $data['created_by']      = $actor->id;
            $data['debit_note_code'] = $this->generateDebitNoteCode($companyId, $data['debit_date'] ?? null);
            $data['status']          = CustomerDebitNote::STATUS_DRAFT;

            $debitNote = CustomerDebitNote::create($data);

            $this->syncLines($debitNote, $companyId, $lines);
            $this->recalculateTotals($debitNote);

            AuditLog::record($actor, 'customer_debit_note.created', $debitNote, null, $debitNote->fresh('lines')->toArray());

            return $debitNote->fresh(['partner', 'customerInvoice', 'currency', 'lines']);
        });
    }

    public function update(int $companyId, CustomerDebitNote $debitNote, array $data, User $actor): CustomerDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $debitNote, $data, $actor) {
            $before = $debitNote->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines'], $data['customer_invoice_id'], $data['currency_id'], $data['exchange_rate']); // immutable after creation — inherited from the invoice

            $data['updated_by'] = $actor->id;
            $debitNote->update($data);

            $this->syncLines($debitNote, $companyId, $lines);
            $this->recalculateTotals($debitNote);

            AuditLog::record($actor, 'customer_debit_note.updated', $debitNote, $before, $debitNote->fresh('lines')->toArray());

            return $debitNote->fresh(['partner', 'customerInvoice', 'currency', 'lines']);
        });
    }

    public function delete(CustomerDebitNote $debitNote, User $actor): void
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be deleted.');
        }

        $before = $debitNote->toArray();
        $debitNote->lines()->delete();
        $debitNote->delete();
        AuditLog::record($actor, 'customer_debit_note.deleted', $debitNote, $before, null);
    }

    // ── Post ──────────────────────────────────────────────────────

    public function post(CustomerDebitNote $debitNote, User $actor): CustomerDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be posted.');
        }

        $debitNote->loadMissing('lines');

        if ($debitNote->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Debit Note with no lines.');
        }

        // Rule 26.4 extension (confirmed with Mahmoud, September 2026):
        // a Customer Debit Note increases revenue exactly like a
        // Customer Invoice does, so it gets the same hard block —
        // cannot be back-dated once a newer fiscal year exists.
        if ($this->fiscalYearService->newerFiscalYearExists($debitNote->company_id, (string) $debitNote->debit_date)) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Debit Note [{$debitNote->debit_note_code}] — its date ({$debitNote->debit_date->toDateString()}) falls in a fiscal year that has since been superseded by a newer one. Debit Notes cannot be back-dated once a newer fiscal year exists (Rule 26.4)."
            );
        }

        return DB::transaction(function () use ($debitNote, $actor) {
            $before = $debitNote->toArray();

            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($debitNote->customer_invoice_id);

            // Audit M10 — drafts saved before the per-line rounding fix
            // are re-totalled so the receivable equals the revenue + VAT lines exactly.
            $this->recalculateTotals($debitNote);

            $creditBuckets = []; // chart_of_account_id => signed functional amount, credit side (revenue + VAT)

            foreach ($debitNote->lines as $line) {
                $invoiceLine = CustomerInvoiceLine::find($line->customer_invoice_line_id);
                $this->addToBucket($creditBuckets, $invoiceLine->revenue_account_id, bcmul((string) $line->line_subtotal, (string) $debitNote->exchange_rate, 2));
                $this->addLineVat($debitNote, $line, $creditBuckets);
            }

            $journalEntry = $this->postDebitNoteJournalEntry($debitNote, $creditBuckets, $actor);

            $debitNote->update([
                'status'           => CustomerDebitNote::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'        => $actor->id,
                'posted_at'        => now(),
            ]);

            $invoice->update([
                'amount_debited'      => bcadd((string) $invoice->amount_debited, (string) $debitNote->debit_value_functional, 2),
                'amount_outstanding'  => bcadd((string) $invoice->amount_outstanding, (string) $debitNote->debit_value_functional, 2),
            ]);

            AuditLog::record($actor, 'customer_debit_note.posted', $debitNote, $before, $debitNote->fresh()->toArray());

            return $debitNote->fresh(['partner', 'customerInvoice', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    public function cancel(CustomerDebitNote $debitNote, User $actor): CustomerDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be cancelled.');
        }

        return DB::transaction(function () use ($debitNote, $actor) {
            $before = $debitNote->toArray();
            $debitNote->update(['status' => CustomerDebitNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'customer_debit_note.cancelled', $debitNote, $before, $debitNote->fresh()->toArray());
            return $debitNote->fresh();
        });
    }

    // ── Private — journal entry assembly ─────────────────────────

    private function addLineVat(CustomerDebitNote $debitNote, CustomerDebitNoteLine $line, array &$creditBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatAccount = ChartOfAccount::findByCode($debitNote->company_id, '226001');
        if (! $vatAccount) {
            throw new \DomainException('VAT Output account (226001) is not seeded for this company — cannot post.');
        }

        $vatFunctional = bcmul((string) $line->vat_amount, (string) $debitNote->exchange_rate, 2);
        $this->addToBucket($creditBuckets, $vatAccount->id, $vatFunctional);
    }

    private function postDebitNoteJournalEntry(CustomerDebitNote $debitNote, array $creditBuckets, User $actor)
    {
        $arAccount = $this->resolveArAccount($debitNote->company_id, $debitNote->currency_id);

        $lines = [];
        $lines[] = ['chart_of_account_id' => $arAccount->id, 'debit' => (string) $debitNote->debit_value_functional, 'credit' => '0', 'description' => 'Debit Note '.$debitNote->debit_note_code];

        foreach ($creditBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Debit Note '.$debitNote->debit_note_code];
        }

        return $this->journalEntryService->post(
            companyId: $debitNote->company_id,
            entryDate: $debitNote->debit_date,
            lines: $lines,
            sourceType: 'customer_debit_note',
            sourceId: $debitNote->id,
            narration: "Debit Note {$debitNote->debit_note_code} against Invoice {$debitNote->customerInvoice->invoice_code} — {$debitNote->partner->name_en}",
            actor: $actor,
        );
    }

    private function resolveArAccount(int $companyId, int $debitCurrencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $debitCurrencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

        $account = ChartOfAccount::findByCode($companyId, $code);

        if (! $account) {
            throw new \DomainException("Accounts Receivable account ({$code}) is not seeded for this company — cannot post.");
        }

        return $account;
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    // ── Private — lines / totals ─────────────────────────────────

    private function syncLines(CustomerDebitNote $debitNote, int $companyId, array $lines): void
    {
        $debitNote->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity  = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal  = bcmul($quantity, $unitPrice, 2);
            $vatRate   = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            CustomerDebitNoteLine::create([
                'company_id'                 => $companyId,
                'customer_debit_note_id'     => $debitNote->id,
                'customer_invoice_line_id'   => $line['customer_invoice_line_id'],
                'description'                => $line['description'],
                'quantity'                   => $quantity,
                'unit_price'                 => $unitPrice,
                'line_subtotal'              => $subtotal,
                'vat_rate'                   => $vatRate,
                'vat_amount'                 => $vatAmount,
                'line_total'                 => $lineTotal,
                'sort_order'                 => $i + 1,
            ]);
        }
    }

    private function recalculateTotals(CustomerDebitNote $debitNote): void
    {
        $lines = $debitNote->lines()->get();

        $subtotal = '0';
        $vat      = '0';
        // Audit M10 — the ledger posts each line converted on its own
        // (rounded to 2 places), so the document's functional totals are
        // the SUM of those same per-line figures. Converting the total
        // once could differ by 0.01 and make the entry "not balance".
        $subtotalFunctional = '0';
        $vatFunctional      = '0';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $vat      = bcadd($vat, (string) $line->vat_amount, 2);
            $subtotalFunctional = bcadd($subtotalFunctional, bcmul((string) $line->line_subtotal, (string) $debitNote->exchange_rate, 2), 2);
            $vatFunctional      = bcadd($vatFunctional, bcmul((string) $line->vat_amount, (string) $debitNote->exchange_rate, 2), 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $debitNote->update([
            'subtotal_amount'             => $subtotal,
            'vat_amount'                  => $vat,
            'debit_value'                 => $total,
            'subtotal_amount_functional'  => $subtotalFunctional,
            'vat_amount_functional'       => $vatFunctional,
            'debit_value_functional'      => bcadd($subtotalFunctional, $vatFunctional, 2),
        ]);
    }

    private function generateDebitNoteCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\CustomerDebitNote::class, 'debit_note_code', 'CDN', $date);
    }
}
