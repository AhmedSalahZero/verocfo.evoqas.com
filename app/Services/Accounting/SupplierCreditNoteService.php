<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierCreditNoteService
//
//  All business logic for Supplier Credit Notes — Purchase Cycle,
//  the opposite direction from Supplier Debit Note: increases part of
//  a specific, already-posted Supplier Bill (the supplier charges us
//  more after the fact — extra freight, a price correction, etc.).
//  Same mandatory supplier_bill_id rule (migration 000199-000200), no
//  free-floating note.
//
//  CONTRA ACCOUNT RESOLUTION and the GOODS-LINE BLOCK are identical
//  to SupplierDebitNoteService — see that class's header for the full
//  rationale. This class posts the same buckets in the opposite
//  direction:
//
//    Dr [contra account, resolved per line — same rules as Supplier
//       Debit Note]
//    Cr Accounts Payable (221001/221002 — same resolution as the bill
//       being affected)
//
//  There is no upper cap to check here (unlike Supplier Debit Note,
//  which cannot exceed what's still open) — this document only ever
//  makes the bill's outstanding balance bigger, never smaller, same
//  reasoning as Customer Debit Note on the receivables side.
//
//  @layer    Service
//  @depends  SupplierCreditNote, SupplierCreditNoteLine, SupplierBill,
//            SupplierBillLine, ChartOfAccount, CompanyActiveCurrency,
//            JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierCreditNoteService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SupplierCreditNote::with(['partner:id,name_en,name_ar', 'supplierBill:id,bill_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('credit_note_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) — inherits currency/rate from the bill ───────

    public function create(int $companyId, array $data, User $actor): SupplierCreditNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $bill = SupplierBill::findOrFail($data['supplier_bill_id']);
            if (! $bill->isPosted()) {
                throw new \DomainException('A Credit Note can only be raised against a posted Supplier Bill.');
            }

            $data['company_id']       = $companyId;
            $data['partner_id']       = $bill->partner_id;
            $data['currency_id']      = $bill->currency_id;
            $data['exchange_rate']    = $bill->exchange_rate;
            $data['created_by']       = $actor->id;
            $data['credit_note_code'] = $this->generateCreditNoteCode($companyId, $data['credit_date'] ?? null);
            $data['status']           = SupplierCreditNote::STATUS_DRAFT;

            $this->assertNoGoodsLines($lines);

            $creditNote = SupplierCreditNote::create($data);

            $this->syncLines($creditNote, $companyId, $lines);
            $this->recalculateTotals($creditNote);

            AuditLog::record($actor, 'supplier_credit_note.created', $creditNote, null, $creditNote->fresh('lines')->toArray());

            return $creditNote->fresh(['partner', 'supplierBill', 'currency', 'lines']);
        });
    }

    public function update(int $companyId, SupplierCreditNote $creditNote, array $data, User $actor): SupplierCreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $creditNote, $data, $actor) {
            $before = $creditNote->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines'], $data['supplier_bill_id'], $data['currency_id'], $data['exchange_rate']); // immutable after creation — inherited from the bill

            $this->assertNoGoodsLines($lines);

            $data['updated_by'] = $actor->id;
            $creditNote->update($data);

            $this->syncLines($creditNote, $companyId, $lines);
            $this->recalculateTotals($creditNote);

            AuditLog::record($actor, 'supplier_credit_note.updated', $creditNote, $before, $creditNote->fresh('lines')->toArray());

            return $creditNote->fresh(['partner', 'supplierBill', 'currency', 'lines']);
        });
    }

    public function delete(SupplierCreditNote $creditNote, User $actor): void
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be deleted.');
        }

        $before = $creditNote->toArray();
        $creditNote->lines()->delete();
        $creditNote->delete();
        AuditLog::record($actor, 'supplier_credit_note.deleted', $creditNote, $before, null);
    }

    // ── Post ──────────────────────────────────────────────────────

    public function post(SupplierCreditNote $creditNote, User $actor): SupplierCreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be posted.');
        }

        $creditNote->loadMissing('lines.supplierBillLine');

        if ($creditNote->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Credit Note with no lines.');
        }

        return DB::transaction(function () use ($creditNote, $actor) {
            $before = $creditNote->toArray();

            $bill = SupplierBill::lockForUpdate()->findOrFail($creditNote->supplier_bill_id);

            $debitBuckets = []; // chart_of_account_id => signed functional amount

            foreach ($creditNote->lines as $line) {
                $billLine = $line->supplierBillLine ?? SupplierBillLine::find($line->supplier_bill_line_id);
                $this->assertNotGoodsLine($billLine);

                $contraAccountId = $this->resolveContraAccountId($creditNote->company_id, $billLine);
                $this->addToBucket($debitBuckets, $contraAccountId, bcmul((string) $line->line_subtotal, (string) $creditNote->exchange_rate, 2));
                $this->addLineVat($creditNote, $line, $billLine, $debitBuckets);
            }

            $journalEntry = $this->postCreditNoteJournalEntry($creditNote, $debitBuckets, $actor);

            $creditNote->update([
                'status'           => SupplierCreditNote::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'        => $actor->id,
                'posted_at'        => now(),
            ]);

            $bill->update([
                'amount_debited'      => bcadd((string) $bill->amount_debited, (string) $creditNote->credit_value_functional, 2),
                'amount_outstanding'  => bcadd((string) $bill->amount_outstanding, (string) $creditNote->credit_value_functional, 2),
            ]);

            AuditLog::record($actor, 'supplier_credit_note.posted', $creditNote, $before, $creditNote->fresh()->toArray());

            return $creditNote->fresh(['partner', 'supplierBill', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    public function cancel(SupplierCreditNote $creditNote, User $actor): SupplierCreditNote
    {
        if (! $creditNote->isDraft()) {
            throw new \DomainException('Only a draft Credit Note can be cancelled.');
        }

        return DB::transaction(function () use ($creditNote, $actor) {
            $before = $creditNote->toArray();
            $creditNote->update(['status' => SupplierCreditNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'supplier_credit_note.cancelled', $creditNote, $before, $creditNote->fresh()->toArray());
            return $creditNote->fresh();
        });
    }

    // ── Private — contra account resolution — see class header ────

    private function resolveContraAccountId(int $companyId, SupplierBillLine $billLine): int
    {
        if ($billLine->isAccrualSettlement()) {
            $account = ChartOfAccount::findByCode($companyId, '228');
            if (! $account) {
                throw new \DomainException('Accrued Expenses account (228) is not seeded for this company — cannot post.');
            }
            return $account->id;
        }

        // Fresh expense — same account the bill line itself used.
        if (! $billLine->chart_of_account_id) {
            throw new \DomainException('The referenced bill line has no account to post against.');
        }

        return $billLine->chart_of_account_id;
    }

    private function assertNoGoodsLines(array $lines): void
    {
        foreach ($lines as $line) {
            $billLine = SupplierBillLine::find($line['supplier_bill_line_id'] ?? null);
            $this->assertNotGoodsLine($billLine);
        }
    }

    private function assertNotGoodsLine(?SupplierBillLine $billLine): void
    {
        if (! $billLine) {
            throw new \DomainException('Referenced Supplier Bill line could not be found.');
        }

        if ($billLine->isGoodsLine()) {
            throw new \DomainException(
                'Supplier Credit Notes against a Goods line are not supported yet — this needs a dedicated design ' .
                'for how it interacts with inventory and Weighted Average Cost. Please raise this against a Fresh ' .
                'Expense or Accrual-Settlement bill line instead, or check with Mahmoud before proceeding.'
            );
        }
    }

    // ── Private — journal entry assembly ─────────────────────────

    private function addLineVat(SupplierCreditNote $creditNote, SupplierCreditNoteLine $line, SupplierBillLine $billLine, array &$debitBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatFunctional = bcmul((string) $line->vat_amount, (string) $creditNote->exchange_rate, 2);

        if ($billLine->is_deductible) {
            $vatAccount = ChartOfAccount::findByCode($creditNote->company_id, '137');
            if (! $vatAccount) {
                throw new \DomainException('VAT Input account (137) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($debitBuckets, $vatAccount->id, $vatFunctional);
            return;
        }

        // Non-deductible — folds into the same contra account as the
        // line's own subtotal, mirroring SupplierBillService's own
        // non-deductible VAT rule.
        $contraAccountId = $this->resolveContraAccountId($creditNote->company_id, $billLine);
        $this->addToBucket($debitBuckets, $contraAccountId, $vatFunctional);
    }

    private function postCreditNoteJournalEntry(SupplierCreditNote $creditNote, array $debitBuckets, User $actor)
    {
        $apAccount = $this->resolveApAccount($creditNote->company_id, $creditNote->currency_id);

        $lines = [];
        foreach ($debitBuckets as $accountId => $amount) {
            $lines[] = ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Credit Note '.$creditNote->credit_note_code];
        }

        $lines[] = ['chart_of_account_id' => $apAccount->id, 'debit' => '0', 'credit' => (string) $creditNote->credit_value_functional, 'description' => 'Credit Note '.$creditNote->credit_note_code];

        return $this->journalEntryService->post(
            companyId: $creditNote->company_id,
            entryDate: $creditNote->credit_date,
            lines: $lines,
            sourceType: 'supplier_credit_note',
            sourceId: $creditNote->id,
            narration: "Credit Note {$creditNote->credit_note_code} against Bill {$creditNote->supplierBill->bill_code} — {$creditNote->partner->name_en}",
            actor: $actor,
        );
    }

    private function resolveApAccount(int $companyId, int $creditCurrencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $creditCurrencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        $account = ChartOfAccount::findByCode($companyId, $code);

        if (! $account) {
            throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
        }

        return $account;
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    // ── Private — lines / totals ─────────────────────────────────

    private function syncLines(SupplierCreditNote $creditNote, int $companyId, array $lines): void
    {
        $creditNote->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity  = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal  = bcmul($quantity, $unitPrice, 2);
            $vatRate   = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            SupplierCreditNoteLine::create([
                'company_id'               => $companyId,
                'supplier_credit_note_id'  => $creditNote->id,
                'supplier_bill_line_id'    => $line['supplier_bill_line_id'],
                'description'              => $line['description'],
                'quantity'                 => $quantity,
                'unit_price'               => $unitPrice,
                'line_subtotal'            => $subtotal,
                'vat_rate'                 => $vatRate,
                'vat_amount'               => $vatAmount,
                'line_total'               => $lineTotal,
                'sort_order'               => $i + 1,
            ]);
        }
    }

    private function recalculateTotals(SupplierCreditNote $creditNote): void
    {
        $lines = $creditNote->lines()->get();

        $subtotal = '0';
        $vat      = '0';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $vat      = bcadd($vat, (string) $line->vat_amount, 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $creditNote->update([
            'subtotal_amount'             => $subtotal,
            'vat_amount'                  => $vat,
            'credit_value'                => $total,
            'subtotal_amount_functional'  => bcmul($subtotal, (string) $creditNote->exchange_rate, 2),
            'vat_amount_functional'       => bcmul($vat, (string) $creditNote->exchange_rate, 2),
            'credit_value_functional'     => bcmul($total, (string) $creditNote->exchange_rate, 2),
        ]);
    }

    private function generateCreditNoteCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\SupplierCreditNote::class, 'credit_note_code', 'SCN', $date);
    }
}
