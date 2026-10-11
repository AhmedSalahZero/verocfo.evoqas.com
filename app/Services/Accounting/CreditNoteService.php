<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CreditNoteService
//
//  All business logic for Credit Notes — Sales Cycle, module 2. Every
//  Credit Note reverses part of a specific, already-posted Customer
//  Invoice (migration 000120-000121) — there is no free-floating
//  credit in Vero CFO.
//
//  Posting shape:
//    Dr revenue_account_id (per line — same account the invoice line
//       originally credited, reversed)
//    Dr 226001 VAT Output (if any line carries VAT, reversed)
//    Cr 133001/133002 Accounts Receivable (Local/Export — same
//       resolution as the invoice being credited)
//
//  The credited amount reduces the invoice's amount_outstanding
//  (via amount_credited — see migration 000124) the same way a
//  Customer Payment allocation would, just without any cash moving.
//  Cannot exceed what's still open on the invoice — checked against
//  the invoice's own amount_outstanding at post time, not
//  recalculated from history.
//
//  AUDIT M9 (Oct 2026):
//   • Each credit line is limited to the invoice line it credits —
//     quantity and unit price may not exceed the original, VAT rate may
//     not exceed the original rate, and everything credited on that
//     line across all of its credit notes (draft or posted) may not
//     exceed the line's value. A line from another invoice is refused.
//   • A posted Credit Note can now be reversed (reverse()): the entry
//     is mirrored, the invoice's open balance is restored, and the
//     note shows as Reversed. A note raised by a Sales Return is the
//     exception — cancel the return's effects through the return flow.
//   • Stock coming back is NOT done here: a goods return goes through
//     Sales Returns, which puts the stock back and raises this credit
//     note itself.
//
//   • Crediting an invoice that is already paid (Audit M9, closed Oct
//     2026): the part of the note that the invoice no longer owes is
//     not refused — it becomes a Customer Credit Balance (Cr 235), which
//     can be applied to another invoice or refunded in cash. See
//     CustomerCreditBalanceService.
//
//  AUDIT M10 — functional totals are the sum of the per-line rounded
//  figures, exactly what the ledger lines carry, so a multi-line
//  foreign-currency note can no longer miss balancing by 0.01.
//
//  @layer    Service
//  @depends  CreditNote, CreditNoteLine, CustomerInvoice,
//            CustomerInvoiceLine, ChartOfAccount, CompanyActiveCurrency,
//            JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\JournalEntry;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private CustomerCreditBalanceService $creditBalances,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditNote::with(['partner:id,name_en,name_ar', 'customerInvoice:id,invoice_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('credit_note_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) — inherits currency/rate from the invoice ───

    public function create(int $companyId, array $data, User $actor): CreditNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $invoice = CustomerInvoice::findOrFail($data['customer_invoice_id']);
            if (! $invoice->isPosted()) {
                throw new \DomainException('A Credit Note can only be raised against a posted Customer Invoice.');
            }

            $this->assertWithinInvoiceLimits($invoice, $lines, null);

            $data['company_id']       = $companyId;
            $data['partner_id']       = $invoice->partner_id;
            $data['currency_id']      = $invoice->currency_id;
            $data['exchange_rate']    = $invoice->exchange_rate;
            $data['created_by']       = $actor->id;
            $data['credit_note_code'] = $this->generateCreditNoteCode($companyId, $data['credit_date'] ?? null);
            $data['status']           = CreditNote::STATUS_DRAFT;

            $creditNote = CreditNote::create($data);

            $this->syncLines($creditNote, $companyId, $lines);
            $this->recalculateTotals($creditNote);

            AuditLog::record($actor, 'credit_note.created', $creditNote, null, $creditNote->fresh('lines')->toArray());

            return $creditNote->fresh(['partner', 'customerInvoice', 'currency', 'lines']);
        });
    }

    public function update(int $companyId, CreditNote $creditNote, array $data, User $actor): CreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $creditNote, $data, $actor) {
            $before = $creditNote->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines'], $data['customer_invoice_id'], $data['currency_id'], $data['exchange_rate']); // immutable after creation — inherited from the invoice

            $this->assertWithinInvoiceLimits(CustomerInvoice::findOrFail($creditNote->customer_invoice_id), $lines, (int) $creditNote->id);

            $data['updated_by'] = $actor->id;
            $creditNote->update($data);

            $this->syncLines($creditNote, $companyId, $lines);
            $this->recalculateTotals($creditNote);

            AuditLog::record($actor, 'credit_note.updated', $creditNote, $before, $creditNote->fresh('lines')->toArray());

            return $creditNote->fresh(['partner', 'customerInvoice', 'currency', 'lines']);
        });
    }

    public function delete(CreditNote $creditNote, User $actor): void
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be deleted.');
        }

        $before = $creditNote->toArray();
        $creditNote->lines()->delete();
        $creditNote->delete();
        AuditLog::record($actor, 'credit_note.deleted', $creditNote, $before, null);
    }

    // ── Post ──────────────────────────────────────────────────────

    public function post(CreditNote $creditNote, User $actor): CreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be posted.');
        }

        $creditNote->loadMissing('lines');

        if ($creditNote->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Credit Note with no lines.');
        }

        // Rule 26.4 extension (confirmed with Mahmoud, September 2026):
        // a Customer Credit Note reduces revenue exactly like a
        // Customer Invoice records it, so it gets the same hard block —
        // cannot be back-dated once a newer fiscal year exists.
        if ($this->fiscalYearService->newerFiscalYearExists($creditNote->company_id, (string) $creditNote->credit_date)) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot post Credit Note [{$creditNote->credit_note_code}] — its date ({$creditNote->credit_date->toDateString()}) falls in a fiscal year that has since been superseded by a newer one. Credit Notes cannot be back-dated once a newer fiscal year exists (Rule 26.4)."
            );
        }

        return DB::transaction(function () use ($creditNote, $actor) {
            $before = $creditNote->toArray();

            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($creditNote->customer_invoice_id);

            // Audit M10 — drafts saved before the per-line rounding fix are
            // re-totalled so the receivable equals the revenue + VAT lines exactly.
            $this->recalculateTotals($creditNote);

            // Audit M9 — re-check the limits at posting time too: another
            // credit note may have been posted since this draft was saved.
            $this->assertWithinInvoiceLimits(
                $invoice,
                $creditNote->lines->map(fn ($l) => [
                    'customer_invoice_line_id' => $l->customer_invoice_line_id,
                    'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'vat_rate' => (string) $l->vat_rate,
                ])->all(),
                (int) $creditNote->id,
                true // at posting only notes already POSTED count; other drafts may be waiting
            );

            // Audit M9 — the invoice absorbs what it still owes; any surplus
            // (it was already paid) becomes a Customer Credit Balance.
            $total  = (string) $creditNote->credit_value_functional;
            $open   = (string) $invoice->amount_outstanding;
            $arPart = bccomp($total, $open, 2) > 0 ? $open : $total;
            $excess = bcsub($total, $arPart, 2);

            $debitBuckets = []; // chart_of_account_id => signed functional amount, debit side (reversing revenue + VAT)

            foreach ($creditNote->lines as $line) {
                $invoiceLine = CustomerInvoiceLine::find($line->customer_invoice_line_id);
                $this->addToBucket($debitBuckets, $invoiceLine->revenue_account_id, bcmul((string) $line->line_subtotal, (string) $creditNote->exchange_rate, 2));
                $this->addLineVat($creditNote, $line, $debitBuckets);
            }

            $journalEntry = $this->postCreditNoteJournalEntry($creditNote, $debitBuckets, $arPart, $excess, $actor);

            $creditNote->update([
                'status'                    => CreditNote::STATUS_POSTED,
                'journal_entry_id'          => $journalEntry->id,
                'credit_balance_functional' => $excess,
                'posted_by'                 => $actor->id,
                'posted_at'                 => now(),
            ]);

            $invoice->update([
                'amount_credited'     => bcadd((string) $invoice->amount_credited, $arPart, 2),
                'amount_outstanding'  => bcsub((string) $invoice->amount_outstanding, $arPart, 2),
            ]);

            if (bccomp($excess, '0', 2) > 0) {
                $this->creditBalances->createFromCreditNote($creditNote->fresh(), $invoice->fresh(), $excess, $journalEntry);
            }

            AuditLog::record($actor, 'credit_note.posted', $creditNote, $before, $creditNote->fresh()->toArray());

            return $creditNote->fresh(['partner', 'customerInvoice', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    // ── Reverse a posted credit note (Audit M9) ──────────────────

    public function reverse(CreditNote $creditNote, User $actor, string $reason): CreditNote
    {
        if (! $creditNote->isPosted()) {
            throw new \DomainException('Only a posted Credit Note can be reversed.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Please give a reason for reversing this Credit Note.');
        }

        $raisedByReturn = SalesReturn::withoutGlobalScopes()
            ->where('company_id', $creditNote->company_id)
            ->whereNotNull('credit_note_ids')
            ->get(['credit_note_ids'])
            ->contains(fn ($r) => in_array((int) $creditNote->id, array_map('intval', (array) $r->credit_note_ids), true));

        if ($raisedByReturn) {
            throw new \DomainException('This Credit Note was raised by a Sales Return and cannot be reversed on its own — the return has already put the goods back.');
        }

        return DB::transaction(function () use ($creditNote, $actor, $reason) {
            $before  = $creditNote->toArray();
            $invoice = CustomerInvoice::lockForUpdate()->findOrFail($creditNote->customer_invoice_id);

            // Dated in the same month as the original (a closed month refuses — period control).
            $entry = JournalEntry::findOrFail($creditNote->journal_entry_id);
            $this->journalEntryService->reverse($entry, $actor, 'Reversal of Credit Note '.$creditNote->credit_note_code.' — '.$reason);

            // Audit M9 — a surplus that became a customer credit balance goes
            // with the note, but only if nothing has been taken out of it.
            $this->creditBalances->cancelForCreditNote($creditNote);

            $arPart = bcsub((string) $creditNote->credit_value_functional, (string) $creditNote->credit_balance_functional, 2);
            $invoice->update([
                'amount_credited'    => bcsub((string) $invoice->amount_credited, $arPart, 2),
                'amount_outstanding' => bcadd((string) $invoice->amount_outstanding, $arPart, 2),
            ]);

            $creditNote->update([
                'status'          => CreditNote::STATUS_REVERSED,
                'reversed_by'     => $actor->id,
                'reversed_at'     => now(),
                'reversal_reason' => $reason,
                'updated_by'      => $actor->id,
            ]);

            AuditLog::record($actor, 'credit_note.reversed', $creditNote, $before, $creditNote->fresh()->toArray());

            return $creditNote->fresh(['partner', 'customerInvoice', 'currency', 'lines']);
        });
    }

    public function cancel(CreditNote $creditNote, User $actor): CreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be cancelled.');
        }

        return DB::transaction(function () use ($creditNote, $actor) {
            $before = $creditNote->toArray();
            $creditNote->update(['status' => CreditNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'credit_note.cancelled', $creditNote, $before, $creditNote->fresh()->toArray());
            return $creditNote->fresh();
        });
    }

    // ── Private — limits against the invoice being credited (Audit M9) ──

    /**
     * Every credit line must point at a line of THIS invoice and may not
     * credit more than that line holds: quantity, unit price and VAT rate
     * each at most the original, and the value credited across all of the
     * line's other credit notes (draft or posted, not cancelled or
     * reversed) plus this one at most the line's own subtotal.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param int|null $ignoreCreditNoteId the note being edited / posted
     * @param bool $postedOnly count only posted notes (used when posting)
     */
    private function assertWithinInvoiceLimits(CustomerInvoice $invoice, array $lines, ?int $ignoreCreditNoteId, bool $postedOnly = false): void
    {
        $invoiceLines = CustomerInvoiceLine::where('customer_invoice_id', $invoice->id)->get()->keyBy('id');
        $thisNote     = []; // invoice line id => subtotal on THIS note

        foreach ($lines as $i => $line) {
            $n   = $i + 1;
            $id  = (int) ($line['customer_invoice_line_id'] ?? 0);
            $src = $invoiceLines->get($id);

            if (! $src) {
                throw new \DomainException("Credit line {$n} does not belong to invoice {$invoice->invoice_code}.");
            }

            $qty   = (string) ($line['quantity'] ?? '0');
            $price = (string) ($line['unit_price'] ?? '0');
            $vat   = (string) ($line['vat_rate'] ?? '0');

            if (bccomp($qty, '0', 4) <= 0) {
                throw new \DomainException("Credit line {$n}: the quantity must be more than zero.");
            }
            if (bccomp($qty, (string) $src->quantity, 4) > 0) {
                throw new \DomainException("Credit line {$n}: quantity {$qty} is more than the {$src->quantity} invoiced.");
            }
            if (bccomp($price, '0', 4) < 0 || bccomp($price, (string) $src->unit_price, 4) > 0) {
                throw new \DomainException("Credit line {$n}: the unit price cannot be more than the invoiced price ({$src->unit_price}).");
            }
            if (bccomp($vat, (string) $src->vat_rate, 4) > 0) {
                throw new \DomainException("Credit line {$n}: the VAT rate cannot be more than the invoiced rate ({$src->vat_rate}%).");
            }

            $thisNote[$id] = bcadd($thisNote[$id] ?? '0', bcmul($qty, $price, 2), 2);
        }

        foreach ($thisNote as $id => $subtotal) {
            $src = $invoiceLines->get($id);

            $already = (string) DB::table('credit_note_lines as l')
                ->join('credit_notes as c', 'c.id', '=', 'l.credit_note_id')
                ->where('l.customer_invoice_line_id', $id)
                ->whereIn('c.status', $postedOnly ? [CreditNote::STATUS_POSTED] : [CreditNote::STATUS_DRAFT, CreditNote::STATUS_POSTED])
                ->when($ignoreCreditNoteId, fn ($q) => $q->where('c.id', '!=', $ignoreCreditNoteId))
                ->sum('l.line_subtotal');

            if (bccomp(bcadd($already, $subtotal, 2), (string) $src->line_subtotal, 2) > 0) {
                $room = bcsub((string) $src->line_subtotal, $already, 2);
                throw new \DomainException(
                    "Credit for \"{$src->description}\" would exceed the invoiced value — only {$room} of its {$src->line_subtotal} is still available to credit."
                );
            }
        }
    }

    // ── Private — journal entry assembly ─────────────────────────

    private function addLineVat(CreditNote $creditNote, CreditNoteLine $line, array &$debitBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatAccount = ChartOfAccount::findByCode($creditNote->company_id, '226001');
        if (! $vatAccount) {
            throw new \DomainException('VAT Output account (226001) is not seeded for this company — cannot post.');
        }

        $vatFunctional = bcmul((string) $line->vat_amount, (string) $creditNote->exchange_rate, 2);
        $this->addToBucket($debitBuckets, $vatAccount->id, $vatFunctional);
    }

    private function postCreditNoteJournalEntry(CreditNote $creditNote, array $debitBuckets, string $arPart, string $excess, User $actor)
    {
        $arAccount = $this->resolveArAccount($creditNote->company_id, $creditNote->currency_id);

        $lines = [];
        foreach ($debitBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Credit Note '.$creditNote->credit_note_code];
        }
        if (bccomp($arPart, '0', 2) > 0) {
            $lines[] = ['chart_of_account_id' => $arAccount->id, 'debit' => '0', 'credit' => $arPart, 'description' => 'Credit Note '.$creditNote->credit_note_code];
        }
        if (bccomp($excess, '0', 2) > 0) {
            $advances = ChartOfAccount::findByCode($creditNote->company_id, '235')
                ?? throw new \DomainException('Chart of Accounts is missing system account 235 (Advances from Customers) — cannot hold the customer credit balance.');
            $lines[] = ['chart_of_account_id' => $advances->id, 'debit' => '0', 'credit' => $excess, 'description' => 'Credit Note '.$creditNote->credit_note_code.' — customer credit balance'];
        }

        return $this->journalEntryService->post(
            companyId: $creditNote->company_id,
            entryDate: $creditNote->credit_date,
            lines: $lines,
            sourceType: 'credit_note',
            sourceId: $creditNote->id,
            narration: "Credit Note {$creditNote->credit_note_code} against Invoice {$creditNote->customerInvoice->invoice_code} — {$creditNote->partner->name_en}",
            actor: $actor,
        );
    }

    private function resolveArAccount(int $companyId, int $creditCurrencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $creditCurrencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

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

    private function syncLines(CreditNote $creditNote, int $companyId, array $lines): void
    {
        $creditNote->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity  = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal  = bcmul($quantity, $unitPrice, 2);
            $vatRate   = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            CreditNoteLine::create([
                'company_id'                 => $companyId,
                'credit_note_id'             => $creditNote->id,
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

    private function recalculateTotals(CreditNote $creditNote): void
    {
        $lines = $creditNote->lines()->get();

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
            $subtotalFunctional = bcadd($subtotalFunctional, bcmul((string) $line->line_subtotal, (string) $creditNote->exchange_rate, 2), 2);
            $vatFunctional      = bcadd($vatFunctional, bcmul((string) $line->vat_amount, (string) $creditNote->exchange_rate, 2), 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $creditNote->update([
            'subtotal_amount'             => $subtotal,
            'vat_amount'                  => $vat,
            'credit_value'                => $total,
            'subtotal_amount_functional'  => $subtotalFunctional,
            'vat_amount_functional'       => $vatFunctional,
            'credit_value_functional'     => bcadd($subtotalFunctional, $vatFunctional, 2),
        ]);
    }

    private function generateCreditNoteCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\CreditNote::class, 'credit_note_code', 'CN', $date);
    }
}
