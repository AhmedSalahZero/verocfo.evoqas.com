<?php

namespace App\Services\Inventory;

// ══════════════════════════════════════════════════════════════════
//  ProjectInventoryService
//
//  Projects > Project Inventory Statement (Project Module Structure
//  v1.1, Section 6.5) — the reporting layer that was confirmed
//  missing entirely (no controller, route, or service method) while
//  the underlying data model (dual-valuation stock_movements) was
//  already built. Built together, 2026-09, with the two data-model
//  fixes this report depends on to be honest about both cases it
//  covers:
//
//    - A Project's tagged inventory (project_id on stock_movements)
//    - A standalone Sales Order's tagged inventory (sales_order_id —
//      previously silently untracked; see migration 000220 and
//      StockLedgerService header)
//
//  Exactly one of $projectId / $salesOrderId is ever passed — the
//  controller enforces that; this service trusts it and filters on
//  whichever is given.
//
//  Per product tagged to the project/SO:
//    - receipts    — every 'receipt' movement, actual cost, in date order
//    - totalReceived — qty + actual cost sum across those receipts
//    - transfers   — every transfer_out/transfer_in movement that
//      carries the SAME tag (2026-09 — transfers now carry the tag
//      forward; see StockLedgerService::transfer()'s header for why
//      this is a deliberate, user-declared field, not derived)
//    - remaining   — net quantity still tagged to this project/SO,
//      computed from EVERY movement type that carries the tag
//      (StockMovement::INBOUND_TYPES minus OUTBOUND_TYPES), not just
//      receipts and transfers — so a future project-tagged
//      consumption or adjustment is correctly reflected too, without
//      this service needing to know about it in advance.
//
//  Read-only, like every report in this codebase — never writes.
//
//  @layer    Service
//  @depends  StockMovement, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\StockMovement;
use Illuminate\Support\Collection;

class ProjectInventoryService
{
    public function statement(int $companyId, ?int $projectId, ?int $salesOrderId): array
    {
        $movements = StockMovement::query()
            ->where('company_id', $companyId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when(! $projectId && $salesOrderId, fn ($q) => $q->where('sales_order_id', $salesOrderId))
            ->with(['product:id,code,name_en,name_ar', 'inventoryLocation:id,name_en,name_ar'])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        $items = $movements->groupBy('product_id')->map(function (Collection $rows) {
            $product = $rows->first()->product;

            $receipts = $rows->where('movement_type', StockMovement::TYPE_RECEIPT)->map(fn ($m) => [
                'date'         => $m->movement_date->format('Y-m-d'),
                'quantity'     => (string) $m->quantity,
                // Defensive fallback: a receipt tagged BEFORE this
                // 2026-09 fix (standalone-SO lines especially) may
                // have null actual cost in already-posted history —
                // bcadd() throws on null/empty strings, so this
                // never passes anything but a well-formed number
                // through. Shown as 0.00 in that case, not hidden.
                'unit_cost'    => $m->actual_unit_cost !== null ? (string) $m->actual_unit_cost : '0.0000',
                'total_cost'   => $m->actual_total_value !== null ? (string) $m->actual_total_value : '0.00',
                'location'     => $m->inventoryLocation?->name_en,
            ])->values();

            $totalReceivedQty  = $receipts->reduce(fn ($c, $r) => bcadd($c, $r['quantity'], 4), '0.0000');
            $totalReceivedCost = $receipts->reduce(fn ($c, $r) => bcadd($c, $r['total_cost'], 2), '0.00');

            $transfers = $rows->whereIn('movement_type', [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN])
                ->map(fn ($m) => [
                    'date'      => $m->movement_date->format('Y-m-d'),
                    'direction' => $m->movement_type === StockMovement::TYPE_TRANSFER_OUT ? 'out' : 'in',
                    'quantity'  => $m->movement_type === StockMovement::TYPE_TRANSFER_OUT
                        ? bcmul((string) $m->quantity, '-1', 4)
                        : (string) $m->quantity,
                    'location'  => $m->inventoryLocation?->name_en,
                ])->values();

            $remaining = $rows->reduce(function ($carry, $m) {
                if ($m->isInbound()) {
                    return bcadd($carry, (string) $m->quantity, 4);
                }
                if ($m->isOutbound()) {
                    return bcsub($carry, (string) $m->quantity, 4);
                }
                return $carry;
            }, '0.0000');

            return [
                'product_id'          => $product->id,
                'product_code'        => $product->code,
                'product_name_en'     => $product->name_en,
                'product_name_ar'     => $product->name_ar,
                'receipts'            => $receipts->all(),
                'total_received_qty'  => $totalReceivedQty,
                'total_received_cost' => $totalReceivedCost,
                'transfers'           => $transfers->all(),
                'remaining_qty'       => $remaining,
            ];
        })->values();

        return [
            'items' => $items->all(),
            'summary' => [
                'total_items'        => $items->count(),
                'total_received_cost' => (string) $items->reduce(fn ($c, $i) => bcadd($c, $i['total_received_cost'], 2), '0.00'),
            ],
        ];
    }
}
