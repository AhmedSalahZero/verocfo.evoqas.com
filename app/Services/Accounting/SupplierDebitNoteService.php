<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierDebitNoteService
//
//  All business logic for Supplier Debit Notes — Purchase Cycle,
//  companion to Supplier Bill. Every Debit Note decreases part of a
//  specific, already-posted Supplier Bill (migration 000197-000198) —
//  there is no free-floating debit note, same rule as every other
//  note in this project (confirmed with Mahmoud).
//
//  CONTRA ACCOUNT RESOLUTION — per line, read from the original
//  SupplierBillLine's own shape (see SupplierBillLine::isGoodsLine()
//  / isAccrualSettlement() / isFreshExpense(), migration 000107):
//
//    FRESH EXPENSE    Cr [the same chart_of_account_id the bill line
//                     originally debited]
//    ACCRUAL SETTLE   Cr 228 Accrued Expenses (the same account the
//                     bill line originally debited when it settled
//                     the accrual)
//    GOODS            Audit H16 (Sept 2026) — allowed ONLY for goods
//                     physically sent back on a posted Purchase Return
//                     (PurchaseReturnService raises these notes itself).
//                     The return already took the goods out of stock and
//                     re-opened 233 GRNI at the receipt cost, so the note:
//                       Cr 233 GRNI at the GRN line's unit cost × qty
//                       Cr/Dr product's COGS account for any price
//                          difference between bill and receipt (the goods
//                          have gone, so the variance cannot sit in stock)
//                     A goods line with no matching return is still
//                     refused — a pure price reduction on goods still in
//                     stock needs its own WAC design.
//
//  Posting shape (fresh expense / accrual settle only):
//    Dr Accounts Payable (221001/221002 — same resolution as the
//       bill being debited)
//    Cr [contra account, resolved per line above]
//    Cr 226001... no — VAT here mirrors the bill's own is_deductible
//       flag per line (137 VAT Input if deductible, folded into the
//       contra account if not — same rule SupplierBillService uses).
//
//  @layer    Service
//  @depends  SupplierDebitNote, SupplierDebitNoteLine, SupplierBill,
//            SupplierBillLine, ChartOfAccount, CompanyActiveCurrency,
//            JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierDebitNoteService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SupplierDebitNote::with(['partner:id,name_en,name_ar', 'supplierBill:id,bill_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('debit_note_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create (draft) — inherits currency/rate from the bill ───────

    public function create(int $companyId, array $data, User $actor): SupplierDebitNote
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $bill = SupplierBill::findOrFail($data['supplier_bill_id']);
            if (! $bill->isPosted()) {
                throw new \DomainException('A Debit Note can only be raised against a posted Supplier Bill.');
            }

            $data['company_id']      = $companyId;
            $data['partner_id']      = $bill->partner_id;
            $data['currency_id']     = $bill->currency_id;
            $data['exchange_rate']   = $bill->exchange_rate;
            $data['created_by']      = $actor->id;
            $data['debit_note_code'] = $this->generateDebitNoteCode($companyId, $data['debit_date'] ?? null);
            $data['status']          = SupplierDebitNote::STATUS_DRAFT;

            $this->assertGoodsLinesReturned($lines);

            $debitNote = SupplierDebitNote::create($data);

            $this->syncLines($debitNote, $companyId, $lines);
            $this->recalculateTotals($debitNote);

            AuditLog::record($actor, 'supplier_debit_note.created', $debitNote, null, $debitNote->fresh('lines')->toArray());

            return $debitNote->fresh(['partner', 'supplierBill', 'currency', 'lines']);
        });
    }

    public function update(int $companyId, SupplierDebitNote $debitNote, array $data, User $actor): SupplierDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be edited.');
        }

        return DB::transaction(function () use ($companyId, $debitNote, $data, $actor) {
            $before = $debitNote->toArray();
            $lines  = $data['lines'] ?? [];
            unset($data['lines'], $data['supplier_bill_id'], $data['currency_id'], $data['exchange_rate']); // immutable after creation — inherited from the bill

            $this->assertGoodsLinesReturned($lines, $debitNote->id);

            $data['updated_by'] = $actor->id;
            $debitNote->update($data);

            $this->syncLines($debitNote, $companyId, $lines);
            $this->recalculateTotals($debitNote);

            AuditLog::record($actor, 'supplier_debit_note.updated', $debitNote, $before, $debitNote->fresh('lines')->toArray());

            return $debitNote->fresh(['partner', 'supplierBill', 'currency', 'lines']);
        });
    }

    public function delete(SupplierDebitNote $debitNote, User $actor): void
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be deleted.');
        }

        $before = $debitNote->toArray();
        $debitNote->lines()->delete();
        $debitNote->delete();
        AuditLog::record($actor, 'supplier_debit_note.deleted', $debitNote, $before, null);
    }

    // ── Post ──────────────────────────────────────────────────────

    public function post(SupplierDebitNote $debitNote, User $actor): SupplierDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be posted.');
        }

        $debitNote->loadMissing('lines.supplierBillLine');

        if ($debitNote->lines->isEmpty()) {
            throw new \DomainException('Cannot post a Debit Note with no lines.');
        }

        return DB::transaction(function () use ($debitNote, $actor) {
            $before = $debitNote->toArray();

            $bill = SupplierBill::lockForUpdate()->findOrFail($debitNote->supplier_bill_id);

            if (bccomp((string) $debitNote->debit_value_functional, (string) $bill->amount_outstanding, 2) > 0) {
                throw new \DomainException(
                    "Debit Note total ({$debitNote->debit_value_functional}) exceeds what's still open on Bill {$bill->bill_code} ({$bill->amount_outstanding})."
                );
            }

            $creditBuckets = []; // chart_of_account_id => signed functional amount

            foreach ($debitNote->lines as $line) {
                $billLine = $line->supplierBillLine ?? SupplierBillLine::find($line->supplier_bill_line_id);
                if (! $billLine) {
                    throw new \DomainException('Referenced Supplier Bill line could not be found.');
                }
                $subtotalFunctional = bcmul((string) $line->line_subtotal, (string) $debitNote->exchange_rate, 2);

                if ($billLine->isGoodsLine()) {
                    // Audit H16 — returned goods: clear 233 at receipt cost, variance to COGS.
                    $this->assertGoodsLinesReturned([['supplier_bill_line_id' => $billLine->id, 'quantity' => (string) $line->quantity]], $debitNote->id);
                    $grnLine = \App\Models\GoodsReceiptNoteLine::findOrFail($billLine->goods_receipt_note_line_id);
                    $grniAmount = bcmul((string) $grnLine->unit_cost, (string) $line->quantity, 2);
                    $this->addToBucket($creditBuckets, $this->grniAccountId($debitNote->company_id), $grniAmount);

                    // Undo the bill's price variance the way the bill booked
                    // it: variance_inventory_pct went to inventory (the
                    // return took the goods out at receipt cost, so that
                    // share is still sitting in inventory), the rest to COGS.
                    $variance = bcsub($subtotalFunctional, $grniAmount, 2);
                    if (bccomp($variance, '0', 2) !== 0) {
                        $product = \App\Models\Product::findOrFail($grnLine->product_id);
                        $pct = (string) ($billLine->variance_inventory_pct ?? '100');
                        $toInventory = bcdiv(bcmul($variance, $pct, 4), '100', 2);
                        if (bccomp($toInventory, '0', 2) !== 0) {
                            $this->addToBucket($creditBuckets, (int) $product->inventory_account_id, $toInventory);
                        }
                        $this->addToBucket($creditBuckets, $this->resolveContraAccountId($debitNote->company_id, $billLine), bcsub($variance, $toInventory, 2));
                    }
                } else {
                    $contraAccountId = $this->resolveContraAccountId($debitNote->company_id, $billLine);
                    $this->addToBucket($creditBuckets, $contraAccountId, $subtotalFunctional);
                }
                $this->addLineVat($debitNote, $line, $billLine, $creditBuckets);
            }

            $journalEntry = $this->postDebitNoteJournalEntry($debitNote, $creditBuckets, $actor);

            $debitNote->update([
                'status'           => SupplierDebitNote::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'        => $actor->id,
                'posted_at'        => now(),
            ]);

            $bill->update([
                'amount_credited'     => bcadd((string) $bill->amount_credited, (string) $debitNote->debit_value_functional, 2),
                'amount_outstanding'  => bcsub((string) $bill->amount_outstanding, (string) $debitNote->debit_value_functional, 2),
            ]);

            AuditLog::record($actor, 'supplier_debit_note.posted', $debitNote, $before, $debitNote->fresh()->toArray());

            return $debitNote->fresh(['partner', 'supplierBill', 'currency', 'lines', 'journalEntry.lines']);
        });
    }

    public function cancel(SupplierDebitNote $debitNote, User $actor): SupplierDebitNote
    {
        if (! $debitNote->isDraft()) {
            throw new \DomainException('Only a draft Debit Note can be cancelled.');
        }

        return DB::transaction(function () use ($debitNote, $actor) {
            $before = $debitNote->toArray();
            $debitNote->update(['status' => SupplierDebitNote::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'supplier_debit_note.cancelled', $debitNote, $before, $debitNote->fresh()->toArray());
            return $debitNote->fresh();
        });
    }

    // ── Private — contra account resolution (see class header) ────

    private function resolveContraAccountId(int $companyId, SupplierBillLine $billLine): int
    {
        // Audit H16 — returned goods: price differences and non-deductible
        // VAT go to the product's cost of sales (the goods have left stock).
        if ($billLine->isGoodsLine()) {
            $productId = \App\Models\GoodsReceiptNoteLine::find($billLine->goods_receipt_note_line_id)?->product_id ?? $billLine->product_id;
            $cogs = \App\Models\Product::find($productId)?->cogs_account_id;
            if (! $cogs) {
                throw new \DomainException('The returned product has no COGS account assigned — cannot post.');
            }
            return (int) $cogs;
        }

        if ($billLine->isAccrualSettlement()) {
            $account = ChartOfAccount::findByCode($companyId, '228');
            if (! $account) {
                throw new \DomainException('Accrued Expenses account (228) is not seeded for this company — cannot post.');
            }
            return $account->id;
        }

        // Fresh expense — same account the bill line itself used.
        if (! $billLine->chart_of_account_id) {
            throw new \DomainException('The referenced bill line has no account to reverse against.');
        }

        return $billLine->chart_of_account_id;
    }

    /**
     * Audit H16 — a goods line may be debited only for quantity that has
     * physically gone back on a posted Purchase Return: the GRN line's
     * returned_billed_quantity must cover what posted debit notes (other
     * than $ignoreNoteId) already took, plus this line.
     */
    private function assertGoodsLinesReturned(array $lines, ?int $ignoreNoteId = null): void
    {
        foreach ($lines as $line) {
            $billLine = SupplierBillLine::find($line['supplier_bill_line_id'] ?? null);
            if (! $billLine) {
                throw new \DomainException('Referenced Supplier Bill line could not be found.');
            }
            if (! $billLine->isGoodsLine()) {
                continue;
            }

            $grnLine = \App\Models\GoodsReceiptNoteLine::find($billLine->goods_receipt_note_line_id);
            $returned = (string) ($grnLine?->returned_billed_quantity ?? '0');
            $alreadyDebited = (string) DB::table('supplier_debit_note_lines as l')
                ->join('supplier_debit_notes as n', 'n.id', '=', 'l.supplier_debit_note_id')
                ->join('supplier_bill_lines as b', 'b.id', '=', 'l.supplier_bill_line_id')
                ->where('n.status', SupplierDebitNote::STATUS_POSTED)
                ->when($ignoreNoteId, fn ($q) => $q->where('n.id', '!=', $ignoreNoteId))
                ->where('b.goods_receipt_note_line_id', $billLine->goods_receipt_note_line_id)
                ->sum('l.quantity');

            if (bccomp(bcadd($alreadyDebited, (string) ($line['quantity'] ?? '0'), 4), $returned, 4) > 0) {
                throw new \DomainException(
                    'A Debit Note on a goods line is only possible for goods sent back to the supplier — record a Purchase Return first ' .
                    '(it raises the Debit Note automatically). A price reduction on goods still in stock is not supported yet.'
                );
            }
        }
    }

    private function grniAccountId(int $companyId): int
    {
        return (int) (ChartOfAccount::findByCode($companyId, '233')?->id
            ?? throw new \DomainException('GRNI account (233) is not seeded for this company — cannot post.'));
    }

    // ── Private — journal entry assembly ─────────────────────────

    private function addLineVat(SupplierDebitNote $debitNote, SupplierDebitNoteLine $line, SupplierBillLine $billLine, array &$creditBuckets): void
    {
        if (bccomp((string) $line->vat_amount, '0', 2) <= 0) {
            return;
        }

        $vatFunctional = bcmul((string) $line->vat_amount, (string) $debitNote->exchange_rate, 2);

        if ($billLine->is_deductible) {
            $vatAccount = ChartOfAccount::findByCode($debitNote->company_id, '137');
            if (! $vatAccount) {
                throw new \DomainException('VAT Input account (137) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($creditBuckets, $vatAccount->id, $vatFunctional);
            return;
        }

        // Non-deductible — folds into the same contra account as the
        // line's own subtotal, mirroring SupplierBillService's own
        // non-deductible VAT rule.
        $contraAccountId = $this->resolveContraAccountId($debitNote->company_id, $billLine);
        $this->addToBucket($creditBuckets, $contraAccountId, $vatFunctional);
    }

    private function postDebitNoteJournalEntry(SupplierDebitNote $debitNote, array $creditBuckets, User $actor)
    {
        $apAccount = $this->resolveApAccount($debitNote->company_id, $debitNote->currency_id);

        $lines = [];
        $lines[] = ['chart_of_account_id' => $apAccount->id, 'debit' => (string) $debitNote->debit_value_functional, 'credit' => '0', 'description' => 'Debit Note '.$debitNote->debit_note_code];

        foreach ($creditBuckets as $accountId => $amount) {
            $cmp = bccomp((string) $amount, '0', 2);
            if ($cmp === 0) continue;
            // Audit H16 — a goods-line price variance can be negative (billed
            // below the receipt cost): it then sits on the debit side.
            $lines[] = $cmp > 0
                ? ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Debit Note '.$debitNote->debit_note_code]
                : ['chart_of_account_id' => $accountId, 'debit' => ltrim((string) $amount, '-'), 'credit' => '0', 'description' => 'Debit Note '.$debitNote->debit_note_code.' — price variance'];
        }

        return $this->journalEntryService->post(
            companyId: $debitNote->company_id,
            entryDate: $debitNote->debit_date,
            lines: $lines,
            sourceType: 'supplier_debit_note',
            sourceId: $debitNote->id,
            narration: "Debit Note {$debitNote->debit_note_code} against Bill {$debitNote->supplierBill->bill_code} — {$debitNote->partner->name_en}",
            actor: $actor,
        );
    }

    private function resolveApAccount(int $companyId, int $debitCurrencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $debitCurrencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

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

    private function syncLines(SupplierDebitNote $debitNote, int $companyId, array $lines): void
    {
        $debitNote->lines()->delete();

        foreach ($lines as $i => $line) {
            $quantity  = (string) ($line['quantity'] ?? '1');
            $unitPrice = (string) ($line['unit_price'] ?? '0');
            $subtotal  = bcmul($quantity, $unitPrice, 2);
            $vatRate   = (string) ($line['vat_rate'] ?? '0');
            $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
            $lineTotal = bcadd($subtotal, $vatAmount, 2);

            SupplierDebitNoteLine::create([
                'company_id'              => $companyId,
                'supplier_debit_note_id'  => $debitNote->id,
                'supplier_bill_line_id'   => $line['supplier_bill_line_id'],
                'description'             => $line['description'],
                'quantity'                => $quantity,
                'unit_price'              => $unitPrice,
                'line_subtotal'           => $subtotal,
                'vat_rate'                => $vatRate,
                'vat_amount'              => $vatAmount,
                'line_total'              => $lineTotal,
                'sort_order'              => $i + 1,
            ]);
        }
    }

    private function recalculateTotals(SupplierDebitNote $debitNote): void
    {
        $lines = $debitNote->lines()->get();

        $subtotal = '0';
        $vat      = '0';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $vat      = bcadd($vat, (string) $line->vat_amount, 2);
        }

        $total = bcadd($subtotal, $vat, 2);

        $debitNote->update([
            'subtotal_amount'             => $subtotal,
            'vat_amount'                  => $vat,
            'debit_value'                 => $total,
            'subtotal_amount_functional'  => bcmul($subtotal, (string) $debitNote->exchange_rate, 2),
            'vat_amount_functional'       => bcmul($vat, (string) $debitNote->exchange_rate, 2),
            'debit_value_functional'      => bcmul($total, (string) $debitNote->exchange_rate, 2),
        ]);
    }

    private function generateDebitNoteCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\SupplierDebitNote::class, 'debit_note_code', 'SDN', $date);
    }
}
