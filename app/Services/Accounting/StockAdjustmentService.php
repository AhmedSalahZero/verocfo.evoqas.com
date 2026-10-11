<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockAdjustmentService
//
//  Wraps StockLedgerService::adjust() for the one case it can't
//  handle on its own: writing off a Job Order's own Finished Goods
//  segment (MFG-002 write-off fix, confirmed with Mahmoud, 2026-09).
//
//  A Job Order's output is customer-specific and deliberately kept
//  out of the company-wide pooled balance (see StockLedgerService's
//  own header, "JOB ORDER SEGMENT EXCEPTION") — its cost only ever
//  goes to the one sale it was built for. That rule is about WHICH
//  SALE gets the cost; it has nothing to do with a purely physical
//  loss (damaged, lost, or scrapped before ever being delivered).
//  A racking system custom-built for Customer A that gets damaged in
//  the warehouse still needs to be written off, even though it was
//  never going to be sold to anyone else.
//
//  This mirrors exactly how DeliveryNoteService already draws from a
//  Job Order's own segment: call JobOrderStockBalanceService::issue()
//  first (the cost side, this job's own segment), then
//  StockLedgerService::adjust() with that segment's own unit cost
//  (the physical side — location and audit trail), so nothing pools
//  into the general average that was never meant to be there.
//
//  INV-007 fix (confirmed with Mahmoud, 2026-09) — every adjustment,
//  job-order or not, now posts a journal entry. It used to post none
//  at all: the stock ledger moved, the general ledger did not, and
//  inventory on the Balance Sheet was overstated by the cumulative
//  value of every write-off the company had ever made, for ever.
//
//  The entry is the obvious one, against account 516 (Inventory
//  Adjustment), a Cost of Goods Sold line seeded beside the two
//  manufacturing variance accounts:
//
//      write-off (out):  Dr 516 Inventory Adjustment
//                        Cr <product's inventory account>
//      write-on  (in):   Dr <product's inventory account>
//                        Cr 516 Inventory Adjustment
//
//  Movement and entry are wrapped in ONE transaction, for the same
//  reason MFG-005 was fixed: a stock movement that commits while its
//  journal entry throws is inventory that has left the building with
//  nothing in the accounts to say so.
//
//  @layer    Service
//  @depends  StockLedgerService, JobOrderStockBalanceService, JobOrder,
//            JournalEntryService, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JobOrder;
use App\Models\JobOrderStockBalance;
use App\Models\Product;
use App\Models\StockAdjustmentRequest;
use App\Models\StockMovement;
use App\Models\User;
// StockLedgerService lives in App\Services\Inventory, not this
// namespace. Without this import the constructor's type hint resolved
// to App\Services\Accounting\StockLedgerService, which does not
// exist — so this service could never be built and the whole Stock
// Adjustment screen returned a container error. Found while adding
// the INV-007 journal entry.
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    /** The Cost of Goods Sold line stock adjustments post against by default. */
    private const ADJUSTMENT_ACCOUNT_CODE = '516';

    /**
     * Audit M-18 — why the stock position is being corrected. Every
     * adjustment carries one. Most go to 516 Inventory Adjustment; a
     * write-down for obsolescence goes to 517 so the income statement
     * shows it separately from ordinary count differences.
     */
    public const REASONS = [
        'count_difference' => '516',
        'damaged'          => '516',
        'expired'          => '516',
        'lost_theft'       => '516',
        'found_stock'      => '516',
        'obsolete'         => '517',
        'other'            => '516',
    ];

    public function __construct(
        private StockLedgerService $stockLedgerService,
        private JobOrderStockBalanceService $jobOrderStockBalances,
        private JournalEntryService $journalEntryService,
    ) {}

    public function adjust(
        int $companyId,
        Product $product,
        int $inventoryLocationId,
        string $quantity,
        string $direction,
        User $actor,
        array $options = []
    ): StockMovement {
        if (empty($options['job_order_id']) || $direction !== 'out') {
            return DB::transaction(function () use ($companyId, $product, $inventoryLocationId, $quantity, $direction, $actor, $options) {
                $movement = $this->stockLedgerService->adjust(
                    $companyId, $product, $inventoryLocationId, $quantity, $direction, $actor, $options
                );

                $this->tagMovement($movement, $options);
                $this->postAdjustmentEntry($companyId, $product, $movement, $direction, $actor, $options['reason_code'] ?? 'other');

                return $movement;
            });
        }

        return DB::transaction(function () use ($companyId, $product, $inventoryLocationId, $quantity, $actor, $options) {
            $jobOrder = JobOrder::where('company_id', $companyId)
                ->where('status', JobOrder::STATUS_COMPLETED)
                ->findOrFail($options['job_order_id']);

            $segment = JobOrderStockBalance::where('company_id', $companyId)
                ->where('job_order_id', $jobOrder->id)
                ->where('product_id', $product->id)
                ->first();

            if (! $segment || bccomp($segment->quantity_on_hand, '0', 4) <= 0) {
                throw new \DomainException(
                    "Job Order {$jobOrder->job_code} has no {$product->name_en} left in its own " .
                    "Finished Goods segment to write off."
                );
            }

            // Cost side first — this job's own segment, never the
            // pooled average (that average never held this stock's
            // cost in the first place).
            $this->jobOrderStockBalances->issue($jobOrder, $product, $quantity);

            // Physical side — location and the audit trail. job_order_id
            // tells StockLedgerService::issue() (called internally by
            // adjust()) to skip the pooled balance, exactly as it
            // already does for a Job Order delivery.
            $movement = $this->stockLedgerService->adjust(
                $companyId, $product, $inventoryLocationId, $quantity, 'out', $actor,
                array_merge($options, [
                    'job_order_id' => $jobOrder->id,
                    'unit_cost'    => (string) $segment->unit_cost,
                ])
            );

            // Same entry as any other write-off. The cost came out of
            // the job's own segment rather than the pooled average, but
            // it is still inventory leaving the company at a value, and
            // the general ledger records it identically.
            $this->tagMovement($movement, $options);
            $this->postAdjustmentEntry($companyId, $product, $movement, 'out', $actor, $options['reason_code'] ?? 'other');

            return $movement;
        });
    }

    private function tagMovement(StockMovement $movement, array $options): void
    {
        $movement->update([
            'reason_code'     => $options['reason_code'] ?? 'other',
            'count_reference' => $options['count_reference'] ?? null,
        ]);
    }

    // ── Maker-checker (Audit M-18) ───────────────────────────────────
    //   submit()  — the maker: validates, saves a PENDING request.
    //   approve() — a DIFFERENT user with the Approve right: posts the
    //               real adjustment through adjust().
    //   reject()  — declines with a reason. Nothing posts.
    // The platform Super Admin may approve his own request (support and
    // test operations), exactly as for manual journals.

    public function submit(int $companyId, array $data, User $actor): StockAdjustmentRequest
    {
        $reason = $data['reason_code'] ?? null;
        if (! isset(self::REASONS[$reason])) {
            throw new \DomainException('Choose a reason for the adjustment.');
        }
        if ($reason === 'count_difference' && trim((string) ($data['count_reference'] ?? '')) === '') {
            throw new \DomainException('A count difference needs the stock-count sheet reference.');
        }
        if (($data['direction'] ?? null) === 'in' && $reason !== 'found_stock' && $reason !== 'count_difference' && $reason !== 'other') {
            throw new \DomainException('This reason only applies to stock going out.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $request = StockAdjustmentRequest::create([
                'company_id'            => $companyId,
                'product_id'            => $data['product_id'],
                'inventory_location_id' => $data['inventory_location_id'],
                'job_order_id'          => $data['job_order_id'] ?? null,
                'direction'             => $data['direction'],
                'quantity'              => $data['quantity'],
                'unit_cost'             => $data['unit_cost'] ?? null,
                'movement_date'         => $data['movement_date'] ?? null,
                'reason_code'           => $data['reason_code'],
                'count_reference'       => $data['count_reference'] ?? null,
                'notes'                 => $data['notes'],
                'status'                => StockAdjustmentRequest::STATUS_PENDING,
                'requested_by'          => $actor->id,
            ]);

            AuditLog::record($actor, 'stock_adjustment.submitted', $request, null, $request->toArray());

            return $request;
        });
    }

    public function approve(StockAdjustmentRequest $request, User $actor): StockAdjustmentRequest
    {
        $this->assertDecidable($request, $actor);

        return DB::transaction(function () use ($request, $actor) {
            $locked = StockAdjustmentRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                throw new \DomainException('This request has already been decided.');
            }

            $maker   = User::withoutGlobalScopes()->findOrFail($locked->requested_by);
            $product = Product::withoutGlobalScopes()->where('company_id', $locked->company_id)->findOrFail($locked->product_id);

            $movement = $this->adjust(
                $locked->company_id, $product, (int) $locked->inventory_location_id, (string) $locked->quantity,
                $locked->direction, $maker,
                [
                    'unit_cost'       => $locked->unit_cost,
                    'movement_date'   => $locked->movement_date?->toDateString(),
                    'notes'           => $locked->notes,
                    'job_order_id'    => $locked->job_order_id,
                    'reason_code'     => $locked->reason_code,
                    'count_reference' => $locked->count_reference,
                ]
            );

            $before = $locked->toArray();
            $locked->update([
                'status'            => StockAdjustmentRequest::STATUS_APPROVED,
                'decided_by'        => $actor->id,
                'decided_at'        => now(),
                'stock_movement_id' => $movement->id,
            ]);

            AuditLog::record($actor, 'stock_adjustment.approved', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function reject(StockAdjustmentRequest $request, User $actor, string $reason): StockAdjustmentRequest
    {
        $this->assertDecidable($request, $actor);
        if (trim($reason) === '') {
            throw new \DomainException('A reason is required to reject an adjustment.');
        }

        $before = $request->toArray();
        $request->update([
            'status'           => StockAdjustmentRequest::STATUS_REJECTED,
            'decided_by'       => $actor->id,
            'decided_at'       => now(),
            'rejection_reason' => trim($reason),
        ]);

        AuditLog::record($actor, 'stock_adjustment.rejected', $request, $before, $request->fresh()->toArray());

        return $request->fresh();
    }

    /** Pending requests for the adjustments screen, oldest first. */
    public function pending(int $companyId): array
    {
        return StockAdjustmentRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', StockAdjustmentRequest::STATUS_PENDING)
            ->with(['product:id,code,name_en', 'inventoryLocation:id,name_en', 'requestedBy:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (StockAdjustmentRequest $r) => [
                'id'              => $r->id,
                'product'         => $r->product?->code.' — '.$r->product?->name_en,
                'location'        => $r->inventoryLocation?->name_en,
                'direction'       => $r->direction,
                'quantity'        => (string) $r->quantity,
                'reason_code'     => $r->reason_code,
                'count_reference' => $r->count_reference,
                'notes'           => $r->notes,
                'requested_by'    => $r->requestedBy?->name,
                'is_mine'         => (int) $r->requested_by === (int) auth()->id(),
            ])->all();
    }

    private function assertDecidable(StockAdjustmentRequest $request, User $actor): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('This request has already been decided.');
        }
        if (! $actor->isSuperAdmin() && (int) $request->requested_by === (int) $actor->id) {
            throw new \DomainException('You submitted this adjustment — it must be approved by someone else.');
        }
    }

    /**
     * Dr 516 / Cr inventory on the way out, the mirror on the way in.
     *
     * Valued at the movement's own wac_total_value, which is what the
     * stock ledger actually relieved (or added) — never recomputed
     * here, so the two ledgers can never disagree by a rounding step.
     *
     * A zero-value movement posts nothing: a quantity correction on a
     * product whose cost is genuinely 0.00 is a real thing, and an
     * empty journal entry is not a record of it.
     */
    private function postAdjustmentEntry(
        int $companyId,
        Product $product,
        StockMovement $movement,
        string $direction,
        User $actor,
        string $reasonCode = 'other'
    ): void {
        $value = (string) $movement->wac_total_value;

        if (bccomp($value, '0', 2) === 0) {
            return;
        }

        if (! $product->inventory_account_id) {
            throw new \DomainException(
                "Product \"{$product->name_en}\" has no inventory account assigned — cannot post."
            );
        }

        $accountCode = self::REASONS[$reasonCode] ?? self::ADJUSTMENT_ACCOUNT_CODE;
        $adjustmentAccount = ChartOfAccount::findByCode($companyId, $accountCode)
            ?? throw new \DomainException(
                "Chart of Accounts is missing system account {$accountCode} — check the seeder."
            );

        $inventoryAccountId = (int) $product->inventory_account_id;
        $label = $direction === 'out'
            ? 'Stock written off — '.$product->name_en
            : 'Stock written on — '.$product->name_en;

        $lines = $direction === 'out'
            ? [
                ['chart_of_account_id' => $adjustmentAccount->id, 'debit' => $value, 'credit' => '0',    'description' => $label],
                ['chart_of_account_id' => $inventoryAccountId,    'debit' => '0',    'credit' => $value, 'description' => $label],
            ]
            : [
                ['chart_of_account_id' => $inventoryAccountId,    'debit' => $value, 'credit' => '0',    'description' => $label],
                ['chart_of_account_id' => $adjustmentAccount->id, 'debit' => '0',    'credit' => $value, 'description' => $label],
            ];

        $this->journalEntryService->post(
            companyId:  $companyId,
            // movement_date is cast to a date, so it comes back as a
            // Carbon instance — toDateString() rather than a cast to
            // string, which would hand post() a full datetime.
            entryDate:  $movement->movement_date->toDateString(),
            lines:      $lines,
            sourceType: 'stock_adjustment',
            sourceId:   $movement->id,
            narration:  $label.' ('.$movement->quantity.' @ '.$movement->wac_unit_cost.')',
            actor:      $actor,
        );
    }
}
