<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PurchaseOrderService
//
//  All business logic for Purchase Order master data. Mirrors
//  SalesOrderService closely — see that file for the pattern this
//  was built from. PO code format: PO/YYYY/MM/00001.
//
//  ISSUANCE DATE (confirmed by Mahmoud): the YYYY/MM in po_code comes
//  from issuance_date — the real business date the PO was issued —
//  not from when the row happens to be saved. issuance_date can be
//  corrected after creation, but ONLY while isIssuanceDateLocked()
//  is false (no posted Goods Receipt Note against this PO yet); once
//  locked, the GRN must be set back to draft first
//  (GoodsReceiptNoteService::setToDraft()) before the date can be
//  touched again. Correcting the date while unlocked automatically
//  renumbers po_code to match the corrected month (confirmed by
//  Mahmoud — the number is not left stale).
//
//  billed_amount / receipt_status / billing_status are updated by
//  the future Goods Receipt Note and Supplier Bill modules
//  (Accounting Engine Phase 2) — NOT touched here. This service's
//  job stops at the PO document itself.
//
//  forceClose() is the one action Sales Order doesn't have — a
//  supplier can simply stop supplying before a PO is fulfilled,
//  and the company needs to close it anyway with a documented
//  reason (confirmed by Mahmoud).
//
//  @layer    Service
//  @depends  PurchaseOrder, PurchaseOrderLine, GoodsReceiptNote,
//            Project, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return PurchaseOrder::with(['partner:id,name_en,name_ar', 'project:id,name_en,contract_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('po_code', 'like', "%{$s}%")
                    ->orWhere('subject', 'like', "%{$s}%")
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($filters['project_id'] ?? null, fn ($q, $p) => $q->where('project_id', $p))
            ->when(isset($filters['standalone']) && $filters['standalone'], fn ($q) => $q->standalone())
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['company_id']     = $companyId;
            $data['exchange_rate']  = $this->resolveExchangeRate($companyId, $data);
            $data['created_by']     = $actor->id;
            $data['receipt_status'] = 'not_received'; // future GRN — see class header
            $data['billing_status'] = 'not_billed';   // future Supplier Bill — see class header

            $po = $this->createWithUniqueCode($companyId, $data);

            $this->syncLines($po, $companyId, $lines);
            $this->recalculatePoValue($po);

            AuditLog::record($actor, 'purchase_order.created', $po, null, $po->toArray());

            return $po->fresh(['partner', 'project', 'currency', 'lines.product', 'lines.uom']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, PurchaseOrder $po, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $po, $data, $actor) {
            $before = $po->toArray();

            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $data['updated_by'] = $actor->id;
            $data['exchange_rate'] = $this->resolveExchangeRate($companyId, $data, $po);

            // Never allow the update() form to touch these — they
            // belong exclusively to the future GRN/Supplier Bill
            // services, not to editing the PO document itself.
            unset($data['receipt_status'], $data['billing_status'], $data['billed_amount']);

            // Blocks the change if locked; sets $data['po_code'] to a
            // renumbered value if the date genuinely changed and is
            // still editable — see class header.
            $this->applyIssuanceDateChange($po, $data);

            $this->saveWithUniqueCode($po, $data);

            $this->syncLines($po, $companyId, $lines);
            $this->recalculatePoValue($po);

            AuditLog::record($actor, 'purchase_order.updated', $po, $before, $po->fresh()->toArray());

            return $po->fresh(['partner', 'project', 'currency', 'lines.product', 'lines.uom']);
        });
    }

    /**
     * Whether issuance_date (and therefore po_code) can still be
     * changed. Locked the moment a real Goods Receipt Note has been
     * posted against this PO — reopening it (GoodsReceiptNoteService
     * ::setToDraft()) removes the lock again. Public: the controller
     * uses this to tell the Vue form whether to show the field as
     * editable or read-only, before the user even tries.
     */
    public function isIssuanceDateLocked(PurchaseOrder $po): bool
    {
        return GoodsReceiptNote::where('purchase_order_id', $po->id)
            ->where('status', GoodsReceiptNote::STATUS_POSTED)
            ->exists();
    }

    // ── Status transitions ────────────────────────────────────────

    public function activate(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new \DomainException('Only draft Purchase Orders can be activated.');
        }
        return DB::transaction(function () use ($po, $actor) {
            $before = $po->toArray();
            $po->update(['status' => 'active', 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'purchase_order.activated', $po, $before, $po->fresh()->toArray());
            return $po->fresh();
        });
    }

    /**
     * Normal close — used once a PO is fully received/billed and
     * there's nothing left to reconcile. No reason required.
     */
    public function close(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        // Audit L-19: only a running (active / on hold) order can be closed.
        if (! in_array($po->status, ['active', 'on_hold'], true)) {
            throw new \DomainException("A Purchase Order that is {$po->status} cannot be closed.");
        }

        return DB::transaction(function () use ($po, $actor) {
            $before = $po->toArray();
            $po->update(['status' => 'closed', 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'purchase_order.closed', $po, $before, $po->fresh()->toArray());
            return $po->fresh();
        });
    }

    /**
     * Force-close — confirmed by Mahmoud: a supplier can simply stop
     * supplying before the PO is fulfilled. This closes it anyway,
     * with a mandatory documented reason, regardless of how much of
     * receipt_status / billing_status is still outstanding.
     */
    public function forceClose(PurchaseOrder $po, string $reason, User $actor): PurchaseOrder
    {
        if (trim($reason) === '') {
            throw new \DomainException('A reason is required to force-close a Purchase Order early.');
        }

        if (! in_array($po->status, ['active', 'on_hold'], true)) {
            throw new \DomainException("A Purchase Order that is {$po->status} cannot be force-closed.");
        }

        return DB::transaction(function () use ($po, $reason, $actor) {
            $before = $po->toArray();
            $po->update(['status' => 'closed', 'closure_reason' => $reason, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'purchase_order.force_closed', $po, $before, $po->fresh()->toArray());
            return $po->fresh();
        });
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * po_code's YYYY/MM comes from issuanceDate — the real business
     * date the PO was issued — not from "now." The number is the next
     * locked sequence for that month, so a deleted PO is never reused.
     */
    private function generatePoCode(int $companyId, \Carbon\Carbon $issuanceDate, ?int $excludePoId = null): string
    {
        // Audit L7: locked sequence for the issuance month. Deleting an order does not reuse its number.
        return \App\Support\DocumentNumber::code($companyId, PurchaseOrder::class, 'po_code', 'PO', $issuanceDate);
    }

    /**
     * Saves a brand new Purchase Order with a guaranteed-unique
     * po_code. If two save attempts happen close together (a form
     * re-submit on a slow connection, two people saving at nearly
     * the same second), both can count the same "next number" before
     * either is actually saved — the second one then fails with a
     * "Duplicate entry" error from the database.
     *
     * Instead of letting that error reach the user, we catch it and
     * automatically try the next number. This is safe to retry
     * inside the transaction because MySQL does not abort a
     * transaction on a duplicate-key error the way some other
     * databases do.
     */
    /**
     * A foreign-currency PO takes the typed rate if it is above 0, otherwise
     * the rate table for the issuance date — and refuses with a clear message
     * when none exists. Before, a PO could be saved at rate 0 (or a default
     * 1 for USD), which broke every "PO value in EGP" check downstream.
     */
    private function resolveExchangeRate(int $companyId, array $data, ?PurchaseOrder $po = null): string
    {
        $currencyId = (int) ($data['currency_id'] ?? $po?->currency_id ?? 0);
        $date       = substr((string) ($data['issuance_date'] ?? $po?->issuance_date ?? now()->toDateString()), 0, 10);

        return app(\App\Services\Accounting\FxService::class)->resolveRate(
            $companyId, $currencyId ?: null, $date, $data['exchange_rate'] ?? null
        );
    }

    private function createWithUniqueCode(int $companyId, array $data, int $maxAttempts = 5): PurchaseOrder
    {
        $issuanceDate = \Carbon\Carbon::parse($data['issuance_date']);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['po_code'] = $this->generatePoCode($companyId, $issuanceDate);

            try {
                return PurchaseOrder::create($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'po_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Someone else just took this number — loop and try the next one.
            }
        }
    }

    /**
     * Checks whether the incoming update() payload is actually
     * changing issuance_date. If it isn't, does nothing. If it is:
     * blocks the change outright when isIssuanceDateLocked() is
     * true; otherwise sets $data['po_code'] to a renumbered value
     * matching the corrected month (confirmed by Mahmoud — the
     * number follows the corrected date, it is not left stale).
     * The actual DB write happens afterward in saveWithUniqueCode().
     */
    private function applyIssuanceDateChange(PurchaseOrder $po, array &$data): void
    {
        if (! array_key_exists('issuance_date', $data)) {
            return; // this update isn't touching the date at all
        }

        $newIssuanceDate = \Carbon\Carbon::parse($data['issuance_date']);

        if ($newIssuanceDate->toDateString() === $po->issuance_date->toDateString()) {
            return; // same date submitted back — nothing actually changed
        }

        if ($this->isIssuanceDateLocked($po)) {
            throw new \DomainException(
                "This Purchase Order's Issuance Date cannot be changed — it already has a posted " .
                "Goods Receipt Note against it. Set that receipt back to draft first if this " .
                "genuinely needs correcting."
            );
        }

        $data['po_code'] = $this->generatePoCode($po->company_id, $newIssuanceDate, excludePoId: $po->id);
    }

    /**
     * Saves an update, retrying with the next available number if
     * po_code was just regenerated by applyIssuanceDateChange() and
     * happens to collide — same reasoning as createWithUniqueCode().
     * If po_code isn't part of this update at all, this is just a
     * plain save.
     */
    private function saveWithUniqueCode(PurchaseOrder $po, array $data, int $maxAttempts = 5): void
    {
        if (! isset($data['po_code'])) {
            $po->update($data);
            return;
        }

        $issuanceDate = \Carbon\Carbon::parse($data['issuance_date'] ?? $po->issuance_date);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $po->update($data);
                return;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'po_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                $data['po_code'] = $this->generatePoCode($po->company_id, $issuanceDate, excludePoId: $po->id);
            }
        }
    }

    private function syncLines(PurchaseOrder $po, int $companyId, array $lines): void
    {
        $po->lines()->delete();

        foreach ($lines as $i => $line) {
            $lineTotal = $this->calcLineTotal(
                (string) ($line['quantity'] ?? 1),
                (string) ($line['unit_price'] ?? 0),
                (string) ($line['discount_pct'] ?? 0)
            );

            PurchaseOrderLine::create([
                'company_id'        => $companyId,
                'purchase_order_id' => $po->id,
                'product_id'        => $line['product_id'] ?? null,
                'description'       => $line['description'] ?? '',
                'quantity'          => $line['quantity'] ?? 1,
                'uom_id'            => $line['uom_id'] ?? null,
                'unit_price'        => $line['unit_price'] ?? 0,
                'discount_pct'      => $line['discount_pct'] ?? 0,
                'line_total'        => $lineTotal,
                'tolerance_pct'     => $line['tolerance_pct'] ?? 0,
                'sort_order'        => $i + 1,
                // received_quantity intentionally omitted — defaults
                // to 0, only the future GRN module ever writes to it.
            ]);
        }
    }

    private function recalculatePoValue(PurchaseOrder $po): void
    {
        $total = $po->lines()->sum('line_total');
        $po->update(['po_value' => $total]);
    }

    private function calcLineTotal(string $qty, string $price, string $discountPct): string
    {
        $gross    = bcmul($qty, $price, 6);
        $discount = bcdiv(bcmul($gross, $discountPct, 6), '100', 6);
        return bcadd(bcsub($gross, $discount, 6), '0', 2);
    }
}
