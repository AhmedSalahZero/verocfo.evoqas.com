<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SalesReturnService
//
//  Audit H16 (Sept 2026) — goods accepted back from a customer against
//  a posted Delivery Note. Before this, a delivery could only be
//  reopened as a whole (and not at all once invoiced).
//
//  Confirmed with Mahmoud: returned goods go back into stock at their
//  ORIGINAL delivery cost — the exact reverse of the cost of sales
//  booked when they left.
//
//  POSTING (one step — a return is posted when it is saved):
//    1. Stock back in: StockLedgerService::receive() at the delivery
//       line's own unit cost (re-averages the WAC like any receipt),
//       into the chosen location — or back into the Job Order segment
//       the goods originally came from. Movement type 'issue_reversal'.
//         Dr Inventory / Cr COGS                    (entry 1)
//    2. Revenue, per Sales Order line, based on how much of it was
//       invoiced at the moment of the return:
//       • NOT YET INVOICED part — the accrued revenue booked at
//         delivery is reversed, pro rata from the SO line's own 143
//         balance:
//           Dr Revenue / Cr 143 Accrued Revenue     (entry 2)
//       • ALREADY INVOICED part — chosen on the return:
//           'credit'  → a Customer Credit Note is raised and posted
//                       automatically (latest invoice first, at the
//                       invoice price and VAT): Dr Revenue + VAT /
//                       Cr AR. The SO line's invoiced quantity is
//                       reduced by the same amount.
//           'replace' → nothing is credited; the goods will be sent
//                       again. The customer stays billed, so the revenue
//                       the delivery recognised for them goes back to
//                       deferred revenue:  Dr Revenue / Cr 236
//                       and the replacement delivery releases it again
//                       (the normal "invoiced before delivered" path).
//    3. Counters: delivery line returned_quantity, SO line
//       delivered_quantity and accrued-revenue balance.
//
//  CANCEL — reverses a return that raised no credit note (a posted
//  credit note cannot be undone — correct it with a Customer Debit
//  Note): the stock goes out again at the cost it came back at, both
//  entries are reversed, the counters restored.
//
//  A Delivery Note with returns cannot be reopened to draft, and an
//  invoice whose SO lines have invoiced returns cannot be reopened —
//  both would unwind figures the return relies on.
//
//  bcmath (Rule 4), AuditLog (Rule 5), company_id explicit (H9).
//
//  @layer    Service
//  @depends  SalesReturn, SalesReturnLine, DeliveryNote,
//            DeliveryNoteLine, SalesOrder, SalesOrderLine,
//            CustomerInvoiceLine, CreditNoteService, StockLedgerService,
//            JobOrderStockBalanceService, JournalEntryService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CreditNote;
use App\Models\CustomerInvoiceLine;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\JobOrder;
use App\Models\JournalEntry;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesReturnService
{
    public function __construct(
        private StockLedgerService $stockLedger,
        private JournalEntryService $journalEntryService,
        private JobOrderStockBalanceService $jobOrderStockBalances,
        private CreditNoteService $creditNoteService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── Read ─────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SalesReturn::where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('return_code', 'like', "%{$s}%"))
            ->with(['partner:id,name_en', 'deliveryNote:id,delivery_code', 'salesOrder:id,so_code',
                    'inventoryJournalEntry:id,entry_number', 'lines.product:id,name_en,code'])
            ->orderByDesc('return_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();
    }

    /** Posted Delivery Notes that still have something that can come back. */
    public function returnableDeliveries(int $companyId): array
    {
        return DeliveryNote::where('company_id', $companyId)
            ->where('status', DeliveryNote::STATUS_POSTED)
            ->whereHas('lines', fn ($q) => $q->whereColumn('returned_quantity', '<', 'quantity'))
            ->with(['partner:id,name_en', 'lines' => fn ($q) => $q->whereColumn('returned_quantity', '<', 'quantity'), 'lines.product:id,name_en,code,has_stock'])
            ->orderByDesc('delivery_date')
            ->limit(300)
            ->get()
            ->map(fn ($dn) => [
                'id' => $dn->id, 'code' => $dn->delivery_code, 'date' => $dn->delivery_date?->toDateString(),
                'partner' => $dn->partner?->name_en,
                'lines' => $dn->lines->map(fn ($l) => [
                    'id' => $l->id, 'description' => $l->description, 'product' => $l->product?->name_en,
                    'location_id' => $l->location_id, 'unit_cost' => (string) $l->wac_unit_cost,
                    'returnable' => bcsub((string) $l->quantity, (string) $l->returned_quantity, 4),
                ])->values(),
            ])->values()->all();
    }

    // ── Create (= post) ──────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): SalesReturn
    {
        $treatment = $data['invoiced_treatment'] ?? SalesReturn::TREATMENT_CREDIT;
        $date = substr((string) $data['return_date'], 0, 10);

        // Same rule as Delivery Notes (Rule 26.5): stock and WAC cannot be
        // changed back-dated once a newer fiscal year exists.
        if ($this->fiscalYearService->newerFiscalYearExists($companyId, $date)) {
            throw new \DomainException('This return date falls in a fiscal year that has since been superseded by a newer one — stock movements cannot be back-dated.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor, $treatment, $date) {
            $dn = DeliveryNote::where('company_id', $companyId)->lockForUpdate()->findOrFail($data['delivery_note_id']);
            if (! $dn->isPosted()) {
                throw new \DomainException('Goods can only be returned against a posted Delivery Note.');
            }
            if ($date < $dn->delivery_date->toDateString()) {
                throw new \DomainException('The return cannot be dated before the delivery ('.$dn->delivery_date->toDateString().').');
            }
            $so = SalesOrder::lockForUpdate()->findOrFail($dn->sales_order_id);

            $return = SalesReturn::create([
                'company_id' => $companyId, 'return_code' => $this->nextCode($companyId, $date),
                'partner_id' => $dn->partner_id, 'sales_order_id' => $so->id, 'delivery_note_id' => $dn->id,
                'return_date' => $date, 'invoiced_treatment' => $treatment, 'status' => SalesReturn::STATUS_POSTED,
                'reason' => $data['reason'] ?? null, 'created_by' => $actor->id,
            ]);

            $inventory = [];  // account => amount (Dr)
            $cogs      = [];  // account => amount (Cr)
            $revenue   = [];  // account => amount (Dr) — accrual reversal
            $accrualTotal = '0.00';
            $deferredTotal = '0.00';
            $totalCost    = '0.00';
            $toCredit     = []; // so_line_id => quantity already invoiced to credit

            foreach ($data['lines'] as $input) {
                $qty = bcadd((string) $input['quantity'], '0', 4);
                if (bccomp($qty, '0', 4) <= 0) continue;

                $dnLine = DeliveryNoteLine::where('delivery_note_id', $dn->id)->lockForUpdate()->findOrFail($input['delivery_note_line_id']);
                $returnable = bcsub((string) $dnLine->quantity, (string) $dnLine->returned_quantity, 4);
                if (bccomp($qty, $returnable, 4) > 0) {
                    throw new \DomainException("Only {$returnable} of \"{$dnLine->description}\" can still be returned.");
                }

                $product  = $dnLine->product;
                $soLine   = SalesOrderLine::lockForUpdate()->findOrFail($dnLine->sales_order_line_id);
                // The delivery's own booked value, pro rata — its wac_unit_cost
                // column is only 2 decimals, the stock average is 4.
                $unitCost = bccomp((string) $dnLine->quantity, '0', 4) > 0
                    ? bcdiv((string) $dnLine->wac_total_value, (string) $dnLine->quantity, 4)
                    : (string) $dnLine->wac_unit_cost;
                $lineCost = bccomp($qty, (string) $dnLine->quantity, 4) === 0
                    ? bcadd((string) $dnLine->wac_total_value, '0', 2)
                    : $this->round2(bcdiv(bcmul((string) $dnLine->wac_total_value, $qty, 8), (string) $dnLine->quantity, 8));
                $location = (int) ($input['location_id'] ?? $dnLine->location_id);

                // 1. Stock back in — into the Job Order segment it came from, if any.
                $original = $dnLine->stock_movement_id ? StockMovement::find($dnLine->stock_movement_id) : null;
                $jobOrder = $original?->job_order_id ? JobOrder::with('stockBalance')->find($original->job_order_id) : null;
                $projectId = $jobOrder ? null : $so->project_id;

                $movement = $this->stockLedger->receive($companyId, $product, $location, $qty, $unitCost, $actor, array_filter([
                    'movement_type'    => StockMovement::TYPE_ISSUE_REVERSAL,
                    'total_value'      => $lineCost,
                    'project_id'       => $projectId,
                    'actual_unit_cost' => $projectId ? $unitCost : null,
                    'job_order_id'     => $jobOrder?->id,
                    'reference_type'   => 'sales_return_line',
                    'reference_id'     => $dnLine->id,
                    'movement_date'    => $date,
                    'notes'            => "Sales Return {$return->return_code}",
                ], fn ($v) => $v !== null));
                if ($jobOrder) {
                    $this->jobOrderStockBalances->receive($jobOrder, $product, $qty, $unitCost);
                }
                $lineCost = bcadd((string) $movement->wac_total_value, '0', 2); // exactly what the stock ledger booked

                $inventory[$product->inventory_account_id] = bcadd($inventory[$product->inventory_account_id] ?? '0', $lineCost, 2);
                $cogs[$product->cogs_account_id]           = bcadd($cogs[$product->cogs_account_id] ?? '0', $lineCost, 2);
                $totalCost = bcadd($totalCost, $lineCost, 2);

                // 2. Revenue — split by what was invoiced at this moment.
                $net = bcsub((string) $soLine->delivered_quantity, (string) $soLine->invoiced_quantity, 4);
                $uninvoiced = bccomp($net, '0', 4) > 0 ? (bccomp($qty, $net, 4) <= 0 ? $qty : $net) : '0';
                $invoiced = bcsub($qty, $uninvoiced, 4);

                $accrual = bccomp($uninvoiced, '0', 4) > 0
                    ? $this->round2(bcdiv(bcmul((string) $soLine->accrued_revenue_functional, $uninvoiced, 8), $net, 8))
                    : '0.00';
                if (bccomp($accrual, '0', 2) !== 0) {
                    $revenue[$soLine->revenue_account_id] = bcadd($revenue[$soLine->revenue_account_id] ?? '0', $accrual, 2);
                    $accrualTotal = bcadd($accrualTotal, $accrual, 2);
                }

                // Invoiced part: 'credit' → credit note below.
                // 'replace' → the customer stays billed for goods no longer
                // with them, so the revenue this delivery recognised for
                // them goes back to 236 Deferred Revenue; the replacement
                // delivery releases it again (DeliveryNoteService, netBefore < 0).
                $deferred = '0.00';
                if (bccomp($invoiced, '0', 4) > 0) {
                    if ($treatment === SalesReturn::TREATMENT_CREDIT) {
                        $toCredit[$soLine->id] = bcadd($toCredit[$soLine->id] ?? '0', $invoiced, 4);
                    } else {
                        $recognised = bcadd((string) $dnLine->accrued_created_functional, (string) $dnLine->deferred_cleared_functional, 2);
                        $deferred = $this->round2(bcdiv(bcmul($recognised, $invoiced, 8), (string) $dnLine->quantity, 8));
                        $revenue[$soLine->revenue_account_id] = bcadd($revenue[$soLine->revenue_account_id] ?? '0', $deferred, 2);
                        $deferredTotal = bcadd($deferredTotal, $deferred, 2);
                    }
                }

                $soLine->update([
                    'delivered_quantity'          => bcsub((string) $soLine->delivered_quantity, $qty, 4),
                    'accrued_revenue_functional'  => bcsub((string) $soLine->accrued_revenue_functional, $accrual, 2),
                    'deferred_revenue_functional' => bcadd((string) $soLine->deferred_revenue_functional, $deferred, 2),
                ]);
                $dnLine->update(['returned_quantity' => bcadd((string) $dnLine->returned_quantity, $qty, 4)]);

                SalesReturnLine::create([
                    'company_id' => $companyId, 'sales_return_id' => $return->id, 'delivery_note_line_id' => $dnLine->id,
                    'sales_order_line_id' => $soLine->id, 'product_id' => $product->id, 'location_id' => $location,
                    'quantity' => $qty, 'unit_cost' => $unitCost, 'total_cost' => $lineCost,
                    'uninvoiced_quantity' => $uninvoiced, 'invoiced_quantity' => $invoiced,
                    'accrual_reversed_functional' => $accrual, 'deferred_created_functional' => $deferred, 'stock_movement_id' => $movement->id, 'job_order_id' => $jobOrder?->id,
                ]);
            }

            if (bccomp($totalCost, '0', 2) === 0 && $return->lines()->count() === 0) {
                throw new \DomainException('Enter the quantity returned on at least one line.');
            }

            // Entry 1 — Dr Inventory / Cr COGS
            $label = "Sales Return {$return->return_code} — {$dn->delivery_code}";
            $invEntry = null;
            if (bccomp($totalCost, '0', 2) > 0) {
                $lines = [];
                foreach ($inventory as $acc => $amt) $lines[] = ['chart_of_account_id' => $acc, 'debit' => $amt, 'credit' => '0', 'description' => $label.' — back into stock'];
                foreach ($cogs as $acc => $amt)      $lines[] = ['chart_of_account_id' => $acc, 'debit' => '0', 'credit' => $amt, 'description' => $label.' — cost of sales reversed'];
                $invEntry = $this->journalEntryService->post($companyId, $date, $lines, 'sales_return', $return->id, $label.' — stock / COGS', $actor);
            }

            // Entry 2 — Dr Revenue / Cr 143 (not yet invoiced) and Cr 236 ('replace')
            $revEntry = null;
            if (bccomp(bcadd($accrualTotal, $deferredTotal, 2), '0', 2) > 0) {
                $lines = [];
                foreach ($revenue as $acc => $amt) $lines[] = ['chart_of_account_id' => $acc, 'debit' => $amt, 'credit' => '0', 'description' => $label.' — revenue reversed'];
                if (bccomp($accrualTotal, '0', 2) > 0) {
                    $lines[] = ['chart_of_account_id' => $this->account($companyId, '143'), 'debit' => '0', 'credit' => $accrualTotal, 'description' => $label.' — accrued revenue reversed'];
                }
                if (bccomp($deferredTotal, '0', 2) > 0) {
                    $lines[] = ['chart_of_account_id' => $this->account($companyId, '236'), 'debit' => '0', 'credit' => $deferredTotal, 'description' => $label.' — billed, to be replaced'];
                }
                $revEntry = $this->journalEntryService->post($companyId, $date, $lines, 'sales_return', $return->id, $label.' — revenue', $actor);
            }

            // Part already invoiced — credit notes (treatment 'credit').
            $creditNoteIds = $toCredit === [] ? [] : $this->raiseCreditNotes($companyId, $so, $toCredit, $date, $return, $actor);

            $return->update([
                'total_cost' => $totalCost, 'accrual_reversed' => $accrualTotal, 'revenue_deferred' => $deferredTotal,
                'inventory_journal_entry_id' => $invEntry?->id, 'revenue_journal_entry_id' => $revEntry?->id,
                'credit_note_ids' => $creditNoteIds ?: null,
            ]);

            AuditLog::record($actor, 'sales_return.posted', $return, null, $return->fresh('lines')->toArray());

            return $return->fresh(['lines', 'partner', 'deliveryNote']);
        });
    }

    // ── Cancel ───────────────────────────────────────────────────

    public function cancel(int $companyId, SalesReturn $return, string $reason, User $actor): SalesReturn
    {
        abort_unless((int) $return->company_id === $companyId, 404);
        if ($return->status !== SalesReturn::STATUS_POSTED) {
            throw new \DomainException('This return is already cancelled.');
        }
        if (! empty($return->credit_note_ids)) {
            throw new \DomainException('This return raised a posted Customer Credit Note, which cannot be undone — correct it with a Customer Debit Note and a new delivery instead.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling.');
        }

        $this->assertNothingPostedSince($companyId, $return);

        return DB::transaction(function () use ($companyId, $return, $reason, $actor) {
            $return = SalesReturn::lockForUpdate()->findOrFail($return->id);
            $before = $return->toArray();
            $so = SalesOrder::find($return->sales_order_id);
            $drift = []; // [inventory account, cogs account] => stock value taken out − value booked back in

            foreach ($return->lines as $line) {
                $product = $line->product;
                $jobOrder = $line->job_order_id ? JobOrder::with('stockBalance')->find($line->job_order_id) : null;
                $projectId = $jobOrder ? null : $so?->project_id;

                if ($jobOrder) {
                    $this->jobOrderStockBalances->issue($jobOrder, $product, (string) $line->quantity);
                }
                $out = $this->stockLedger->issue($companyId, $product, (int) $line->location_id, (string) $line->quantity, $actor, array_filter([
                    'movement_type'      => StockMovement::TYPE_ISSUE,
                    'unit_cost_override' => $jobOrder ? null : (string) $line->unit_cost,
                    'unit_cost'          => $jobOrder ? (string) $line->unit_cost : null,
                    'job_order_id'       => $jobOrder?->id,
                    'project_id'         => $projectId,
                    'actual_unit_cost'   => $projectId ? (string) $line->unit_cost : null,
                    'reference_type'     => 'sales_return_line',
                    'reference_id'       => $line->delivery_note_line_id,
                    'movement_date'      => $return->return_date->toDateString(),
                    'notes'              => "Cancelled Sales Return {$return->return_code}",
                ], fn ($v) => $v !== null));
                $diff = bcsub((string) $out->wac_total_value, (string) $line->total_cost, 2);
                if (bccomp($diff, '0', 2) !== 0) {
                    $key = $product->inventory_account_id.'|'.$product->cogs_account_id;
                    $drift[$key] = bcadd($drift[$key] ?? '0', $diff, 2);
                }

                $soLine = SalesOrderLine::lockForUpdate()->find($line->sales_order_line_id);
                $soLine?->update([
                    'delivered_quantity'         => bcadd((string) $soLine->delivered_quantity, (string) $line->quantity, 4),
                    'accrued_revenue_functional'  => bcadd((string) $soLine->accrued_revenue_functional, (string) $line->accrual_reversed_functional, 2),
                    'deferred_revenue_functional' => bcsub((string) $soLine->deferred_revenue_functional, (string) $line->deferred_created_functional, 2),
                ]);
                $dnLine = DeliveryNoteLine::lockForUpdate()->find($line->delivery_note_line_id);
                $dnLine?->update(['returned_quantity' => bcsub((string) $dnLine->returned_quantity, (string) $line->quantity, 4)]);
            }

            foreach ([$return->inventory_journal_entry_id, $return->revenue_journal_entry_id] as $jeId) {
                $je = $jeId ? JournalEntry::find($jeId) : null;
                if ($je && $je->status === JournalEntry::STATUS_POSTED) {
                    $this->journalEntryService->reverse($je, $actor, "Cancelled Sales Return {$return->return_code} — {$reason}");
                }
            }

            // The stock average moved since the return, so the goods left at
            // a slightly different value than they came back at — keep the
            // ledger equal to the stock records (difference to cost of sales).
            $adjust = [];
            foreach ($drift as $key => $diff) {
                [$inv, $cogs] = explode('|', $key);
                $abs = ltrim($diff, '-');
                $out = bccomp($diff, '0', 2) > 0;
                $adjust[] = ['chart_of_account_id' => (int) $cogs, 'debit' => $out ? $abs : '0', 'credit' => $out ? '0' : $abs, 'description' => 'Stock value difference on cancelling '.$return->return_code];
                $adjust[] = ['chart_of_account_id' => (int) $inv, 'debit' => $out ? '0' : $abs, 'credit' => $out ? $abs : '0', 'description' => 'Stock value difference on cancelling '.$return->return_code];
            }
            if ($adjust !== []) {
                $this->journalEntryService->post($companyId, $return->return_date->toDateString(), $adjust, 'sales_return', $return->id, "Cancelled Sales Return {$return->return_code} — stock value difference", $actor);
            }

            $return->update(['status' => SalesReturn::STATUS_CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            AuditLog::record($actor, 'sales_return.cancelled', $return, $before, $return->fresh()->toArray());

            return $return->fresh();
        });
    }

    // ── Private ──────────────────────────────────────────────────

    /**
     * Credits the already-invoiced quantity, latest invoice first, at
     * each invoice line's own price and VAT — one Credit Note per
     * invoice, posted immediately. Reduces the SO line's invoiced
     * quantity and the SO's invoiced amount by what was credited.
     */
    private function raiseCreditNotes(int $companyId, SalesOrder $so, array $toCredit, string $date, SalesReturn $return, User $actor): array
    {
        $byInvoice = []; // invoice_id => [lines]
        $returnNoteIds = SalesReturn::where('company_id', $companyId)->where('status', SalesReturn::STATUS_POSTED)
            ->whereNotNull('credit_note_ids')->pluck('credit_note_ids')->flatten()->map(fn ($id) => (int) $id)->all();

        foreach ($toCredit as $soLineId => $qtyNeeded) {
            $invoiceLines = CustomerInvoiceLine::where('sales_order_line_id', $soLineId)
                ->whereHas('customerInvoice', fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted'))
                ->with('customerInvoice:id,invoice_date,exchange_rate')
                ->get()
                ->sortByDesc(fn ($l) => $l->customerInvoice->invoice_date->toDateString().sprintf('%012d', $l->id));

            $remaining = $qtyNeeded;
            foreach ($invoiceLines as $invLine) {
                if (bccomp($remaining, '0', 4) <= 0) break;

                // Only quantity credited by earlier RETURNS counts — a price-only
                // credit note (a discount) does not mean goods came back.
                $alreadyCredited = $returnNoteIds === [] ? '0' : (string) DB::table('credit_note_lines as l')
                    ->join('credit_notes as c', 'c.id', '=', 'l.credit_note_id')
                    ->where('c.status', CreditNote::STATUS_POSTED)
                    ->whereIn('c.id', $returnNoteIds)
                    ->where('l.customer_invoice_line_id', $invLine->id)
                    ->sum('l.quantity');
                $available = bcsub((string) $invLine->quantity, $alreadyCredited, 4);
                if (bccomp($available, '0', 4) <= 0) continue;

                $take = bccomp($remaining, $available, 4) <= 0 ? $remaining : $available;
                $byInvoice[$invLine->customer_invoice_id][] = [
                    'customer_invoice_line_id' => $invLine->id,
                    'description'              => $invLine->description.' — returned ('.$return->return_code.')',
                    'quantity'                 => $take,
                    'unit_price'               => (string) $invLine->unit_price,
                    'vat_rate'                 => (string) $invLine->vat_rate,
                ];
                $remaining = bcsub($remaining, $take, 4);
            }

            if (bccomp($remaining, '0', 4) > 0) {
                throw new \DomainException('Part of the returned quantity was invoiced but has already been credited — choose "Replace" for it, or return less.');
            }

            $soLine = SalesOrderLine::lockForUpdate()->find($soLineId);
            $soLine->update(['invoiced_quantity' => bcsub((string) $soLine->invoiced_quantity, $qtyNeeded, 4)]);
        }

        $ids = [];
        $creditedSubtotalFunctional = '0.00';
        foreach ($byInvoice as $invoiceId => $lines) {
            $note = $this->creditNoteService->create($companyId, [
                'customer_invoice_id' => $invoiceId, 'credit_date' => $date,
                'credit_reason' => "Goods returned — Sales Return {$return->return_code}", 'lines' => $lines,
            ], $actor);
            try {
                $this->creditNoteService->post($note, $actor);
            } catch (\DomainException $e) {
                throw new \DomainException($e->getMessage().' The customer has already paid for these goods — choose "Replace" instead, or agree a refund and record it separately.', 0, $e);
            }
            $ids[] = $note->id;
            $posted = $note->fresh();
            // AUDIT M-11: the Sales Order keeps invoiced_amount in its own currency.
            $creditedSubtotalFunctional = bcadd($creditedSubtotalFunctional, $so->inOrderCurrency(
                (string) $posted->subtotal_amount, (string) $posted->subtotal_amount_functional, $posted->currency_id
            ), 2);
        }

        $so->update(['invoiced_amount' => bcsub((string) $so->invoiced_amount, $creditedSubtotalFunctional, 2)]);

        return $ids;
    }

    /**
     * A return can be cancelled only while nothing has since been
     * delivered or invoiced on its Sales Order lines — those documents
     * read the counters the return changed, and would be left
     * inconsistent (e.g. delivered above the order, 143 / 236 stranded).
     */
    private function assertNothingPostedSince(int $companyId, SalesReturn $return): void
    {
        $soLineIds = $return->lines()->pluck('sales_order_line_id');
        // Journal entry ids only ever grow, so "posted after the return"
        // = its entry id is higher than the return's own first entry.
        $since = (int) max(0, (int) ($return->inventory_journal_entry_id ?: $return->revenue_journal_entry_id));

        $laterDelivery = DeliveryNoteLine::whereIn('sales_order_line_id', $soLineIds)
            ->whereHas('deliveryNote', fn ($q) => $q->where('company_id', $companyId)->where('status', DeliveryNote::STATUS_POSTED)->where('inventory_journal_entry_id', '>', $since))
            ->exists();
        $laterInvoice = CustomerInvoiceLine::whereIn('sales_order_line_id', $soLineIds)
            ->whereHas('customerInvoice', fn ($q) => $q->where('company_id', $companyId)->where('status', 'posted')->where('journal_entry_id', '>', $since))
            ->exists();

        if ($laterDelivery || $laterInvoice) {
            throw new \DomainException('A delivery or invoice has been posted on this order since the return — it can no longer be cancelled. Record the correction with a new delivery or a Credit / Debit Note.');
        }
    }

    private function nextCode(int $companyId, string $date): string
    {
        // Audit L-7: atomic yearly counter (code shape SR-YYYY-NNNNN kept).
        $year   = substr($date, 0, 4);
        $prefix = 'SR-'.$year.'-';
        $build  = fn (int $n) => $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $n      = \App\Support\DocumentNumber::nextNumber(
            $companyId, \App\Models\SalesReturn::class, 'return_code', 'SR', $year, $prefix.'%', $build, '-'
        );

        return $build($n);
    }

    private function account(int $companyId, string $code): int
    {
        return (int) (ChartOfAccount::findByCode($companyId, $code)?->id
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code}."));
    }

    private function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
