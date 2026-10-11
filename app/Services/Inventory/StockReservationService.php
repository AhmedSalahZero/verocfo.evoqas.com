<?php

namespace App\Services\Inventory;

// ══════════════════════════════════════════════════════════════════
//  StockReservationService
//
//  The ONLY writer to stock_reservations — same "one gatekeeper
//  service" pattern as StockLedgerService/JournalEntryService.
//
//  Deliberately simple scope (confirmed with Mahmoud, July 2026):
//  - General reservation only — company-wide against the product,
//    never tied to a specific Inventory Location. An Internal
//    Transfer between locations is completely unaffected by a
//    reservation existing.
//  - Handled entirely from its own screen, by a warehouse person, for
//    an already-ACTIVATED Sales Order — nothing on the Sales Order
//    form itself references this.
//  - One reservation per Sales Order Line, for that line's full
//    ordered quantity — no partial-quantity tracking, since there's
//    no Delivery Note yet to drive a partial release. When Delivery
//    Note is eventually built, release() below is the method it
//    should call automatically instead of a person doing it by hand.
//  - Never changes stock_movements / product_stock_balances /
//    product_location_stock — a reservation is a soft hold for
//    visibility ("don't oversell this"), not a physical movement.
//    StockLedgerService's balances are completely unaffected; only
//    the Stock Balance page's display subtracts the active
//    reservation total to show "Available."
//
//  @layer    Service
//  @depends  StockReservation, SalesOrder, SalesOrderLine, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockReservationService
{
    /**
     * Activated Sales Orders that still have at least one line with
     * no reservation yet — the warehouse person picks from here.
     */
    public function eligibleSalesOrders(int $companyId): Collection
    {
        return SalesOrder::where('company_id', $companyId)
            ->where('status', 'active')
            ->whereHas('lines', fn ($q) => $q->whereDoesntHave('activeReservation'))
            ->with(['lines' => fn ($q) => $q->whereDoesntHave('activeReservation')->with('product:id,code,name_en', 'uom:id,code')])
            ->orderByDesc('created_at')
            ->get(['id', 'so_code', 'partner_id']);
    }

    /** Every reservation currently active — the "release" side of the screen. */
    public function paginateActive(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return StockReservation::where('company_id', $companyId)
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->with(['product:id,code,name_en', 'salesOrder:id,so_code,partner_id', 'salesOrder.partner:id,name_en', 'salesOrderLine:id,uom_id', 'salesOrderLine.uom:id,code', 'reservedBy:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($q) => $q
                    ->whereHas('product', fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhere('name_en', 'like', "%{$s}%"))
                    ->orWhereHas('salesOrder', fn ($q) => $q->where('so_code', 'like', "%{$s}%"))
                )
            )
            ->orderByDesc('reserved_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** One reservation per line, for that line's full ordered quantity. */
    public function reserve(int $companyId, SalesOrderLine $line, User $actor): StockReservation
    {
        if ($line->salesOrder->status !== 'active') {
            throw new \DomainException('Only a line on an ACTIVE Sales Order can be reserved.');
        }

        // Bug fix (audit, Aug 2026): this was only ever enforced by
        // the UI hiding already-reserved lines from the "Reserve"
        // list — nothing stopped this method itself from being
        // called twice on the same line (e.g. two browser tabs, a
        // stale page). Checked here directly now, the same
        // defense-in-depth already used elsewhere in this app rather
        // than trusting the frontend alone.
        if ($line->activeReservation()->exists()) {
            throw new \DomainException('This Sales Order line already has an active reservation.');
        }

        return DB::transaction(function () use ($companyId, $line, $actor) {
            $reservation = StockReservation::create([
                'company_id'          => $companyId,
                'sales_order_id'      => $line->sales_order_id,
                'sales_order_line_id' => $line->id,
                'product_id'          => $line->product_id,
                'quantity'            => $line->quantity,
                'status'              => StockReservation::STATUS_ACTIVE,
                'reserved_by'         => $actor->id,
                'reserved_at'         => now(),
            ]);

            AuditLog::record($actor, 'stock_reservation.created', $reservation, null, $reservation->toArray());

            return $reservation;
        });
    }

    /**
     * Manual release — confirmed: intended to happen once the line is
     * fully delivered. There's no Delivery Note yet to trigger this
     * automatically, so a warehouse person does it by hand today.
     */
    public function release(StockReservation $reservation, User $actor, ?string $notes = null): StockReservation
    {
        if (! $reservation->isActive()) {
            throw new \DomainException('This reservation is already released.');
        }

        DB::transaction(function () use ($reservation, $actor, $notes) {
            $before = $reservation->toArray();

            $reservation->update([
                'status'      => StockReservation::STATUS_RELEASED,
                'released_by' => $actor->id,
                'released_at' => now(),
                'notes'       => $notes,
            ]);

            AuditLog::record($actor, 'stock_reservation.released', $reservation, $before, $reservation->fresh()->toArray());
        });

        return $reservation->fresh();
    }

    /**
     * Total actively-reserved quantity per product — used by the
     * Stock Balance page to show "Reserved" / "Available" alongside
     * the existing "Quantity on Hand" it already computes.
     */
    public function reservedQuantitiesByProduct(int $companyId): array
    {
        return StockReservation::where('company_id', $companyId)
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($v) => (string) $v)
            ->toArray();
    }
}
