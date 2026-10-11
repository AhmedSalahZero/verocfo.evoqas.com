<?php

namespace App\Services\Projects;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderService
//
//  All business logic for Sales Order master data.
//  Mandatory gateway to all customer invoicing (Rule 28).
//  SO code format: SO/YYYY/MM/00001.
//
//  ISSUANCE DATE (confirmed by Mahmoud): mirrors
//  PurchaseOrderService's issuance_date handling exactly — see that
//  file's header for the full rationale. so_code's YYYY/MM comes
//  from issuance_date, correctable while isIssuanceDateLocked() is
//  false (no posted Delivery Note or posted Customer Invoice against
//  this SO yet), and renumbers automatically when corrected. NOTE:
//  in practice, update() below already blocks editing an SO entirely
//  once ANY Delivery Note or Customer Invoice exists — even a draft
//  one (pre-existing rule, unchanged here) — so isIssuanceDateLocked()
//  mostly matters for the narrower "no linked documents at all yet"
//  window before that broader block would apply anyway.
//
//  invoiced_amount is updated by the Accounting Engine (Stream 3)
//  when invoices are posted or reversed — not touched here.
//
//  @layer    Service
//  @depends  SalesOrder, SalesOrderLine, DeliveryNote, CustomerInvoice,
//            Project, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CustomerInvoice;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return SalesOrder::with(['partner:id,name_en,name_ar', 'project:id,name_en,contract_code', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) => $q
                    ->where('so_code', 'like', "%{$s}%")
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

    public function create(int $companyId, array $data, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;

            $so = $this->createWithUniqueCode($companyId, $data);

            $this->syncLines($so, $companyId, $lines);
            $this->recalculateSoValue($so);

            AuditLog::record($actor, 'sales_order.created', $so, null, $so->toArray());

            return $so->fresh(['partner', 'project', 'currency', 'lines.product', 'lines.uom']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, SalesOrder $so, array $data, User $actor): SalesOrder
    {
        // Fix (July 2026, per Mahmoud, and a real bug found while
        // investigating his request): editing an SO deletes and
        // recreates ALL its lines from scratch (syncLines() below),
        // including their database ids. Both Delivery Note lines and
        // Customer Invoice lines reference an SO line's id directly —
        // deleting a line something else still points to would
        // previously fail with a raw, unhandled foreign key
        // constraint error instead of a clear message. Checked and
        // blocked here, for both documents (Mahmoud confirmed
        // Customer Invoice should be included alongside Delivery
        // Notes, since the crash risk is identical for both).
        if ($so->deliveryNotes()->exists()) {
            throw new \DomainException('This Sales Order has Delivery Notes against it and can no longer be edited — its lines are now referenced elsewhere.');
        }
        if ($so->customerInvoices()->exists()) {
            throw new \DomainException('This Sales Order has Customer Invoices against it and can no longer be edited — its lines are now referenced elsewhere.');
        }

        return DB::transaction(function () use ($companyId, $so, $data, $actor) {
            $before = $so->toArray();

            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $data['updated_by'] = $actor->id;

            // Blocks the change if locked; sets $data['so_code'] to a
            // renumbered value if the date genuinely changed and is
            // still editable — see class header.
            $this->applyIssuanceDateChange($so, $data);

            $this->saveWithUniqueCode($so, $data);

            $this->syncLines($so, $companyId, $lines);
            $this->recalculateSoValue($so);

            AuditLog::record($actor, 'sales_order.updated', $so, $before, $so->fresh()->toArray());

            return $so->fresh(['partner', 'project', 'currency', 'lines.product', 'lines.uom']);
        });
    }

    /**
     * Whether issuance_date (and therefore so_code) can still be
     * changed. See class header for the note on how this interacts
     * with update()'s broader "any linked document at all" block.
     */
    public function isIssuanceDateLocked(SalesOrder $so): bool
    {
        return DeliveryNote::where('sales_order_id', $so->id)
                ->where('status', DeliveryNote::STATUS_POSTED)
                ->exists()
            || CustomerInvoice::where('sales_order_id', $so->id)
                ->where('status', CustomerInvoice::STATUS_POSTED)
                ->exists();
    }

    // ── Status transitions ────────────────────────────────────────

    public function activate(SalesOrder $so, User $actor): SalesOrder
    {
        if ($so->status !== 'draft') {
            throw new \DomainException('Only draft Sales Orders can be activated.');
        }
        return DB::transaction(function () use ($so, $actor) {
            $before = $so->toArray();
            $so->update(['status' => 'active', 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'sales_order.activated', $so, $before, $so->fresh()->toArray());
            return $so->fresh();
        });
    }

    public function close(SalesOrder $so, User $actor): SalesOrder
    {
        // TODO (Stream 3): check for outstanding invoice balance before closing
        // Audit L-19: only a running (active / on hold) order can be closed.
        if (! in_array($so->status, ['active', 'on_hold'], true)) {
            throw new \DomainException("A Sales Order that is {$so->status} cannot be closed.");
        }

        return DB::transaction(function () use ($so, $actor) {
            $before = $so->toArray();
            $so->update(['status' => 'closed', 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'sales_order.closed', $so, $before, $so->fresh()->toArray());
            return $so->fresh();
        });
    }

    // ── Collateral lookup — for OD Against Contract/SO ───────────

    public function eligibleCollateral(int $companyId, string $afterDate): array
    {
        return SalesOrder::eligibleCollateral($afterDate)
            ->with('partner:id,name_en')
            ->get(['id', 'so_code', 'subject', 'partner_id', 'end_date'])
            ->map(fn ($so) => [
                'id'       => $so->id,
                'type'     => 'sales_order',
                'label'    => $so->so_code . ($so->subject ? ' — ' . $so->subject : '') . ' (' . ($so->partner->name_en ?? '') . ')',
                'end_date' => $so->end_date->format('Y-m-d'),
            ])
            ->toArray();
    }

    // ── Private helpers ───────────────────────────────────────────

    /** Audit L7: locked sequence for the issuance month. Deleting an order does not reuse its number. */
    private function generateSoCode(int $companyId, \Carbon\Carbon $issuanceDate, ?int $excludeSoId = null): string
    {
        return \App\Support\DocumentNumber::code($companyId, SalesOrder::class, 'so_code', 'SO', $issuanceDate);
    }

    /**
     * Saves the Sales Order with a guaranteed-unique so_code.
     * Same self-healing retry as PurchaseOrderService::createWithUniqueCode()
     * — see that method's comment for the full explanation.
     */
    private function createWithUniqueCode(int $companyId, array $data, int $maxAttempts = 5): SalesOrder
    {
        $issuanceDate = \Carbon\Carbon::parse($data['issuance_date']);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data['so_code'] = $this->generateSoCode($companyId, $issuanceDate);

            try {
                return SalesOrder::create($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'so_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Someone else just took this number — loop and try the next one.
            }
        }
    }

    /** Mirrors PurchaseOrderService::applyIssuanceDateChange() — see that method's comment. */
    private function applyIssuanceDateChange(SalesOrder $so, array &$data): void
    {
        if (! array_key_exists('issuance_date', $data)) {
            return;
        }

        $newIssuanceDate = \Carbon\Carbon::parse($data['issuance_date']);

        if ($newIssuanceDate->toDateString() === $so->issuance_date->toDateString()) {
            return;
        }

        if ($this->isIssuanceDateLocked($so)) {
            throw new \DomainException(
                "This Sales Order's Issuance Date cannot be changed — it already has a posted " .
                "Delivery Note or Customer Invoice against it."
            );
        }

        $data['so_code'] = $this->generateSoCode($so->company_id, $newIssuanceDate, excludeSoId: $so->id);
    }

    /** Mirrors PurchaseOrderService::saveWithUniqueCode() — see that method's comment. */
    private function saveWithUniqueCode(SalesOrder $so, array $data, int $maxAttempts = 5): void
    {
        if (! isset($data['so_code'])) {
            $so->update($data);
            return;
        }

        $issuanceDate = \Carbon\Carbon::parse($data['issuance_date'] ?? $so->issuance_date);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $so->update($data);
                return;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $isCodeClash = str_contains($e->getMessage(), 'so_code');
                if (! $isCodeClash || $attempt === $maxAttempts) {
                    throw $e;
                }
                $data['so_code'] = $this->generateSoCode($so->company_id, $issuanceDate, excludeSoId: $so->id);
            }
        }
    }

    private function syncLines(SalesOrder $so, int $companyId, array $lines): void
    {
        $so->lines()->delete();

        foreach ($lines as $i => $line) {
            $lineTotal = $this->calcLineTotal(
                (string) ($line['quantity'] ?? 1),
                (string) ($line['unit_price'] ?? 0),
                (string) ($line['discount_pct'] ?? 0)
            );

            SalesOrderLine::create([
                'company_id'         => $companyId,
                'sales_order_id'     => $so->id,
                'product_id'         => $line['product_id'] ?? null,
                'description'        => $line['description'] ?? '',
                'quantity'           => $line['quantity'] ?? 1,
                'uom_id'             => $line['uom_id'] ?? null,
                'unit_price'         => $line['unit_price'] ?? 0,
                'discount_pct'       => $line['discount_pct'] ?? 0,
                'line_total'         => $lineTotal,
                'revenue_account_id' => $line['revenue_account_id'] ?? null,
                'cost_center_id'     => $line['cost_center_id'] ?? null,
                'sort_order'         => $i + 1,
            ]);
        }
    }

    private function recalculateSoValue(SalesOrder $so): void
    {
        $total = $so->lines()->sum('line_total');
        $so->update(['so_value' => $total]);
    }

    private function calcLineTotal(string $qty, string $price, string $discountPct): string
    {
        $gross    = bcmul($qty, $price, 6);
        $discount = bcdiv(bcmul($gross, $discountPct, 6), '100', 6);
        return bcadd(bcsub($gross, $discount, 6), '0', 2);
    }
}
