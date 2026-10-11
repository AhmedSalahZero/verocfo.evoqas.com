<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProductionOrderService
//
//  The Mass Production engine. Four responsibilities:
//
//  1. create() — snapshots standard cost per unit (material + labor
//     + overhead) at the moment the order is opened, from the BOM
//     and whichever standard_rates are in effect that day. A later
//     rate change never retroactively changes an order already open
//     — see migration 000090 header.
//
//  2. consumeMaterial() / recordLaborCost() / recordOverheadCost() —
//     the progressive WIP postings while the order runs. Each one
//     now posts its own real journal entry (2026-09 GL Posting fix —
//     see Section "GL Posting" below), in addition to the physical/
//     cost tracking (StockLedgerService for material; a
//     ProductionOrderCostEntry row for labor/overhead — pure cost,
//     no physical movement, see migration 000091 header).
//     2026-09 fix (Manufacturing Variance Closing Design v1.0, Step 1):
//     recordLaborCost()/recordOverheadCost() no longer take a
//     manually-typed actual cost — the caller supplies only hours (or
//     base quantity), and the cost is calculated from the standard
//     rate in effect that month, same mechanism
//     JobOrderService::logPieceOperationCost() already used. The real
//     Payroll-driven actual cost is reconciled separately, once a
//     month, by the (forthcoming) Variance Closing step — never typed
//     in here.
//
//  3. complete() — posts ONE production_receipt into Finished Goods
//     at the order's STANDARD unit cost (snapshotted at creation —
//     see create()), never actual cost (2026-09 fix, Manufacturing
//     Variance Closing Design v1.0, Step 2 — confirmed with Mahmoud:
//     Finished Goods must absorb standard cost, with variance closed
//     separately once a month, never actual cost absorbed directly —
//     reversing this class's previous behavior, which was confirmed
//     to be exactly the flow this redesign exists to fix). WIP no
//     longer nets to zero here — whatever's left (real cost minus
//     standard cost) is this order's own variance, intentionally left
//     in WIP until the monthly Variance Closing step (not yet built)
//     sweeps it out.
//
//  4. variance() — standard (the snapshot × quantity_to_produce) vs
//     actual (everything posted), broken out by material/labor/
//     overhead. Still a REPORTING method only — it does not itself
//     close or dispose of variance; that's the forthcoming monthly
//     Variance Closing step's job, using this same comparison as its
//     starting point.
//
//  GL POSTING (2026-09 fix — confirmed, this app's #1 audited gap).
//  Previously this service never called JournalEntryService at all —
//  physical/cost tracking existed but never reached the GL. Now:
//    consumeMaterial()   Dr 135002 WIP / Cr component's own inventory_account_id
//    recordLaborCost()   Dr 135002 WIP / Cr 237 Direct Labor Clearing
//    recordOverheadCost()Dr 135002 WIP / Cr 238 Manufacturing Overhead Absorbed
//    complete()           Dr FG product's inventory_account_id / Cr 135002 WIP
//  237/238 are liability CLEARING accounts (same family as 228
//  Accrued Expenses / 233 GRNI) — settled later by real Wages /
//  manufacturing-expense postings once those flows exist, not built
//  here. Confirmed with Mahmoud: kept as ONE account each — the
//  electricity/maintenance/spare-parts breakdown comes from
//  OverheadPool (already required on every overhead entry), never
//  from COA sub-accounts. cost_center_id (2026-09) is an optional
//  extra allocation dimension on top of order-level and pool-level
//  detail, same field every other cost document in this app carries.
//
//  Material consumption's journal entry is looked up via
//  JournalEntry::where('source_type', 'production_order_consumption')
//  ->where('source_id', $movement->id) — never a stored column on the
//  shared stock_movements table, same convention
//  StockLedgerService::resolveReferences() already uses for GRN.
//
//  @layer    Service
//  @depends  ProductionOrder, ProductionOrderCostEntry, BillOfMaterial,
//            OverheadPool, StandardRate, FiscalYear, Product, ChartOfAccount,
//            ProductStockBalance, StockMovement, StockLedgerService,
//            StandardRateService, JournalEntryService, FiscalYearService,
//            User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\BillOfMaterial;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OverheadPool;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCostEntry;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductionOrderService
{
    public function __construct(
        private StockLedgerService $stockLedger,
        private StandardRateService $standardRates,
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private BillOfMaterialService $billOfMaterials,
        private OverheadPoolService $overheadPools,
    ) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return ProductionOrder::with(['product:id,code,name_en', 'billOfMaterial:id,version', 'outputLocation:id,name_en'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create — the standard cost snapshot ──────────────────────
    //
    // BOM Draft/Active/Superseded fix (VeroCFO_Manufacturing_
    // Flexibility_Design_v1_0.md, Section 1) — this used to take
    // bill_of_material_id straight from the client's dropdown
    // selection, which meant "which BOM applies" was a manual pick
    // from whichever versions happened to be flagged active, with no
    // connection to the order's own date at all. Now it's resolved
    // automatically from BillOfMaterialService::resolveEffective(),
    // the single source of truth for "which BOM version was actually
    // in effect on this date" — nothing the client sends can override
    // it, closing the gap where effective_date was captured but
    // never actually used.
    public function create(int $companyId, array $data, User $actor): ProductionOrder
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $orderDate = $data['planned_start_date'] ?? now()->toDateString();

            $bom = $this->billOfMaterials->resolveEffective($companyId, (int) $data['product_id'], $orderDate);

            if (! $bom) {
                throw new \DomainException(
                    "No active Bill of Materials is effective for this product on {$orderDate} — activate one before opening this order."
                );
            }

            $fiscalYear = $this->resolveFiscalYear($companyId, $orderDate);
            [$year, $month] = $this->yearMonth($orderDate);

            // The three …CostTotal() methods return the cost of ONE
            // BOM BATCH — output_quantity units — because that is the
            // unit a Bill of Materials is written in. This order
            // produces quantity_to_produce units, which is usually a
            // different number, so each batch total is scaled to the
            // order before it is stored: these columns are the
            // ORDER's totals, which is what complete() credits WIP by.
            //
            // Scaled as (batchTotal * orderQty) / batchQty, in that
            // order, so there is exactly ONE rounding at the end. The
            // whole point of storing totals (MFG-004) is to avoid the
            // divide-then-multiply round trip that loses a cent;
            // dividing first here would reintroduce it.
            $standardMaterialPerUnit = $this->standardMaterialCostPerUnit($bom);
            $standardLaborPerUnit    = $this->standardLaborCostPerUnit($companyId, $bom, $fiscalYear, $year, $month);
            $standardOverheadPerUnit = $this->standardOverheadCostPerUnit($companyId, $bom, $fiscalYear, $year, $month);

            $orderQuantity           = (string) $data['quantity_to_produce'];
            $standardMaterialTotal   = $this->scaleBatchTotalToOrder($this->standardMaterialCostTotal($bom), $bom, $orderQuantity);
            $standardLaborTotal      = $this->scaleBatchTotalToOrder($this->standardLaborCostTotal($companyId, $bom, $fiscalYear, $year, $month), $bom, $orderQuantity);
            $standardOverheadTotal   = $this->scaleBatchTotalToOrder($this->standardOverheadCostTotal($companyId, $bom, $fiscalYear, $year, $month), $bom, $orderQuantity);

            $order = ProductionOrder::create([
                'company_id'                      => $companyId,
                'order_code'                       => $this->generateOrderCode($companyId, $data['planned_start_date'] ?? null),
                'product_id'                       => $data['product_id'],
                'bill_of_material_id'              => $bom->id,
                'quantity_to_produce'              => $data['quantity_to_produce'],
                'raw_material_location_id'         => $data['raw_material_location_id'],
                'output_location_id'               => $data['output_location_id'],
                'status'                           => ProductionOrder::STATUS_DRAFT,
                'planned_start_date'               => $data['planned_start_date'] ?? null,
                'standard_material_cost_per_unit'  => $standardMaterialPerUnit,
                'standard_material_cost_total'     => $standardMaterialTotal,
                'standard_labor_cost_per_unit'     => $standardLaborPerUnit,
                'standard_labor_cost_total'        => $standardLaborTotal,
                'standard_overhead_cost_per_unit'  => $standardOverheadPerUnit,
                'standard_overhead_cost_total'     => $standardOverheadTotal,
                'notes'                            => $data['notes'] ?? null,
                'created_by'                       => $actor->id,
            ]);

            AuditLog::record($actor, 'production_order.created', $order, null, $order->toArray());

            return $order->fresh(['product', 'billOfMaterial', 'rawMaterialLocation', 'outputLocation']);
        });
    }

    public function start(ProductionOrder $order, User $actor): ProductionOrder
    {
        if ($order->status !== ProductionOrder::STATUS_DRAFT) {
            throw new \DomainException('Only a draft Production Order can be started.');
        }

        $order->update(['status' => ProductionOrder::STATUS_IN_PROGRESS, 'actual_start_date' => now()->toDateString()]);
        AuditLog::record($actor, 'production_order.started', $order, null, $order->fresh()->toArray());

        return $order->fresh();
    }

    // ── Progressive WIP postings ──────────────────────────────────

    /**
     * Bug fix: this used to have no $entryDate parameter at all, so
     * every call silently fell through to StockLedgerService::issue()'s
     * own `$options['movement_date'] ?? now()` default — meaning
     * material consumption for a production order ALWAYS posted on
     * today's real date, never the order's own date, unlike every
     * other cost-entry method on this class (recordLaborCost,
     * recordOverheadCost, complete), which all accept one correctly.
     * Harmless for same-day live use; silently wrong for anything
     * backdated (imports, historical seeding) once "today" falls
     * outside the fiscal year being posted into — it then fails with
     * a confusing "does not fall inside any accounting period" error
     * that names today's date, not the date the caller intended.
     */
    // MFG-005 fix (confirmed with Mahmoud, 2026-09) — identical defect
    // and identical fix to JobOrderService::consumeMaterial(); see that
    // method's header for the full reasoning. The stock issue and its
    // journal entry are one operation: either the material left
    // inventory and the ledger says so, or neither happened.
    public function consumeMaterial(ProductionOrder $order, int $componentProductId, string $quantity, User $actor, ?string $entryDate = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($order, $componentProductId, $quantity, $actor, $entryDate, $notes) {
            $this->consumeMaterialWithin($order, $componentProductId, $quantity, $actor, $entryDate, $notes);
        });
    }

    private function consumeMaterialWithin(ProductionOrder $order, int $componentProductId, string $quantity, User $actor, ?string $entryDate, ?string $notes): void
    {
        $this->assertInProgress($order);

        $component = Product::where('company_id', $order->company_id)->findOrFail($componentProductId);

        if (! $component->inventory_account_id) {
            throw new \DomainException("Product \"{$component->name_en}\" has no inventory account assigned — cannot post.");
        }

        $movement = $this->stockLedger->issue($order->company_id, $component, $order->raw_material_location_id, $quantity, $actor, [
            'movement_type'  => StockMovement::TYPE_PRODUCTION_CONSUMPTION,
            'reference_type' => ProductionOrder::class,
            'reference_id'   => $order->id,
            'movement_date'  => $entryDate ?? now()->toDateString(),
            'notes'          => $notes,
        ]);

        $wipAccount = $this->resolveSystemAccount($order->company_id, '135002', 'Manufacturing WIP');

        $this->journalEntryService->post(
            companyId: $order->company_id,
            entryDate: $movement->movement_date,
            lines: [
                ['chart_of_account_id' => $wipAccount->id, 'debit' => (string) $movement->wac_total_value, 'credit' => '0', 'description' => 'Material consumed — '.$order->order_code],
                ['chart_of_account_id' => $component->inventory_account_id, 'debit' => '0', 'credit' => (string) $movement->wac_total_value, 'description' => 'Material consumed — '.$order->order_code],
            ],
            sourceType: 'production_order_consumption',
            sourceId: $movement->id,
            narration: "Production Order {$order->order_code} — material consumed: {$component->name_en}",
            actor: $actor,
        );

        // Fix: audit_log.action is capped at 30 chars — 'production_order.*'
        // strings below were all latent bugs, never yet triggered live.
        AuditLog::record($actor, 'production_order.material_used', $order, null, [
            'component_product_id' => $componentProductId, 'quantity' => $quantity,
        ]);
    }

    // 2026-09 fix (Manufacturing Variance Closing Design v1.0, Step 1):
    // $actualCost is no longer a caller-supplied parameter. Nobody can
    // truly know actual labor cost per entry in real time — it's only
    // known in aggregate once Payroll runs — so this now calculates a
    // cost the same way logPieceOperationCost() already does:
    // hours × the standard rate in effect this month. That figure is
    // stored as BOTH standard_amount and actual_amount for this entry
    // (they're identical at entry time by construction); the true
    // Payroll actual is reconciled separately, once a month, by the
    // Variance Closing step (same document, Section 1) — not here.
    public function recordLaborCost(ProductionOrder $order, string $hours, User $actor, ?string $entryDate = null, ?string $notes = null, ?int $costCenterId = null): ProductionOrderCostEntry
    {
        $this->assertInProgress($order);

        $entryDate = $entryDate ?? now()->toDateString();
        [$year, $month] = $this->yearMonth($entryDate);
        $fiscalYear = $this->resolveFiscalYear($order->company_id, $entryDate);
        $rate = $this->standardRates->effectiveLaborRate($order->company_id, $fiscalYear->id, $year, $month);
        $actualCost = bcmul($hours, $rate, 2);

        return DB::transaction(function () use ($order, $hours, $actualCost, $actor, $entryDate, $notes, $costCenterId, $rate) {
            $entry = ProductionOrderCostEntry::create([
                'company_id'           => $order->company_id,
                'production_order_id' => $order->id,
                'entry_type'           => ProductionOrderCostEntry::TYPE_LABOR,
                'base_quantity'        => $hours,
                'rate_applied'         => $rate,
                'standard_amount'      => bcmul($hours, $rate, 2),
                'actual_amount'        => $actualCost,
                'cost_center_id'       => $costCenterId,
                'entry_date'           => $entryDate,
                'notes'                => $notes,
                'created_by'           => $actor->id,
            ]);

            $wipAccount    = $this->resolveSystemAccount($order->company_id, '135002', 'Manufacturing WIP');
            $laborClearing = $this->resolveSystemAccount($order->company_id, '237', 'Direct Labor Clearing');

            $journalEntry = $this->journalEntryService->post(
                companyId: $order->company_id,
                entryDate: $entryDate,
                lines: [
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => $actualCost, 'credit' => '0', 'cost_center_id' => $costCenterId, 'description' => 'Labor cost — '.$order->order_code],
                    ['chart_of_account_id' => $laborClearing->id, 'debit' => '0', 'credit' => $actualCost, 'cost_center_id' => $costCenterId, 'description' => 'Labor cost — '.$order->order_code],
                ],
                sourceType: 'production_order_cost_entry',
                sourceId: $entry->id,
                narration: "Production Order {$order->order_code} — labor cost recorded",
                actor: $actor,
            );

            $entry->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'production_order.labor_added', $order, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    // Same fix as recordLaborCost() above — $actualCost calculated
    // from $baseQuantity × the pool's standard rate, never typed.
    public function recordOverheadCost(ProductionOrder $order, OverheadPool $pool, string $baseQuantity, User $actor, ?string $entryDate = null, ?string $notes = null, ?int $costCenterId = null): ProductionOrderCostEntry
    {
        $this->assertInProgress($order);

        $entryDate = $entryDate ?? now()->toDateString();
        [$year, $month] = $this->yearMonth($entryDate);
        $fiscalYear = $this->resolveFiscalYear($order->company_id, $entryDate);
        $rate = $this->standardRates->effectiveOverheadRate($order->company_id, $fiscalYear->id, $pool->id, $year, $month);
        $actualCost = bcmul($baseQuantity, $rate, 2);

        return DB::transaction(function () use ($order, $pool, $baseQuantity, $actualCost, $actor, $entryDate, $notes, $costCenterId, $rate) {
            $entry = ProductionOrderCostEntry::create([
                'company_id'           => $order->company_id,
                'production_order_id' => $order->id,
                'entry_type'           => ProductionOrderCostEntry::TYPE_OVERHEAD,
                'overhead_pool_id'     => $pool->id,
                'base_quantity'        => $baseQuantity,
                'rate_applied'         => $rate,
                'standard_amount'      => bcmul($baseQuantity, $rate, 2),
                'actual_amount'        => $actualCost,
                'cost_center_id'       => $costCenterId,
                'entry_date'           => $entryDate,
                'notes'                => $notes,
                'created_by'           => $actor->id,
            ]);

            $wipAccount       = $this->resolveSystemAccount($order->company_id, '135002', 'Manufacturing WIP');
            // Section 7 — credits THIS pool's own dedicated sub-account
            // under 238, not a shared company-wide balance, so
            // PayrollRunService can reconcile Applied vs Actual per
            // pool. ensureAppliedAccount() lazily creates it if this
            // pool predates the fix.
            $overheadAbsorbed = $this->overheadPools->ensureAppliedAccount($pool, $actor);

            $journalEntry = $this->journalEntryService->post(
                companyId: $order->company_id,
                entryDate: $entryDate,
                lines: [
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => $actualCost, 'credit' => '0', 'cost_center_id' => $costCenterId, 'description' => 'Overhead cost — '.$pool->name.' — '.$order->order_code],
                    ['chart_of_account_id' => $overheadAbsorbed->id, 'debit' => '0', 'credit' => $actualCost, 'cost_center_id' => $costCenterId, 'description' => 'Overhead cost — '.$pool->name.' — '.$order->order_code],
                ],
                sourceType: 'production_order_cost_entry',
                sourceId: $entry->id,
                narration: "Production Order {$order->order_code} — overhead cost recorded ({$pool->name})",
                actor: $actor,
            );

            $entry->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'production_order.overhead_add', $order, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    // ── WIP balance — material (stock_movements) + cost entries ──
    //
    // 2026-09 fix: nets TYPE_ISSUE_REVERSAL against TYPE_PRODUCTION_CONSUMPTION.
    // Needed once setToDraft() exists (see below) — reversing a
    // material consumption puts the physical stock back via a NEW
    // receive()-style row (StockLedgerService has no in-place delete
    // for an issue, same reasoning as DeliveryNoteService's own
    // reversal), so the original consumption row is never removed.
    // Without this netting, a reopened-then-redone order would
    // silently double-count the old, reversed consumption alongside
    // the new one.
    public function wipBalance(ProductionOrder $order): string
    {
        $consumed = (string) $order->stockMovements()
            ->where('movement_type', StockMovement::TYPE_PRODUCTION_CONSUMPTION)
            ->sum('wac_total_value');

        $reversed = (string) $order->stockMovements()
            ->where('movement_type', StockMovement::TYPE_ISSUE_REVERSAL)
            ->sum('wac_total_value');

        $materialValue = bcsub($consumed, $reversed, 2);

        $costValue = (string) $order->costEntries()->sum('actual_amount');

        return bcadd($materialValue, $costValue, 2);
    }

    // ── Complete — post Finished Goods at STANDARD cost (2026-09 fix,
    // Manufacturing Variance Closing Design v1.0, Step 2). Previously
    // this summed everything actually spent (wipBalance()) and posted
    // THAT into Finished Goods — meaning Finished Goods silently
    // absorbed whatever the batch really cost, and "variance" never
    // meant anything because there was never a difference to close.
    // Confirmed with Mahmoud as the wrong flow — the very thing this
    // whole Manufacturing redesign exists to fix. Now: Finished Goods
    // receives at the STANDARD unit cost snapshotted when the order
    // was opened (standard_material/labor/overhead_cost_per_unit —
    // already calculated in create(), just unused until now). WIP is
    // credited by that same standard total, NOT by wipBalance() — so
    // WIP no longer nets to zero here. Whatever's left in WIP (this
    // order's real cost minus its standard cost) is exactly this
    // order's own variance, and is intentionally left sitting in WIP
    // until the monthly Variance Closing step (Step 3 — not yet
    // built) sweeps it out into COGS/Finished Goods. This is expected,
    // not a bug — see the design document's Section 1 for where that
    // balance goes next.

    public function complete(ProductionOrder $order, User $actor, ?string $completionDate = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $actor, $completionDate) {
            $this->assertInProgress($order);

            // MFG-004 fix (confirmed with Mahmoud, 2026-09) — sums the
            // exact totals captured at creation time (before any of
            // them were divided into a per-unit rate), so nothing is
            // lost to rounding. Falls back to the old combine-then-
            // multiply calculation only for a Production Order created
            // before these columns existed.
            if ($order->standard_material_cost_total !== null) {
                $standardTotalCost = bcadd(
                    bcadd((string) $order->standard_material_cost_total, (string) $order->standard_labor_cost_total, 2),
                    (string) $order->standard_overhead_cost_total, 2
                );
                $standardUnitCost = bccomp($order->quantity_to_produce, '0', 4) > 0
                    ? bcdiv($standardTotalCost, $order->quantity_to_produce, 4)
                    : '0.0000';
            } else {
                $standardUnitCost = bcadd(
                    bcadd((string) $order->standard_material_cost_per_unit, (string) $order->standard_labor_cost_per_unit, 4),
                    (string) $order->standard_overhead_cost_per_unit, 4
                );
                $standardTotalCost = bcmul($standardUnitCost, $order->quantity_to_produce, 2);
            }

            $product = Product::where('company_id', $order->company_id)->findOrFail($order->product_id);

            if (! $product->inventory_account_id) {
                throw new \DomainException("Product \"{$product->name_en}\" has no inventory account assigned — cannot post.");
            }

            $completionDate = $completionDate ?? now()->toDateString();

            // Rule 26.5 extension (confirmed with Mahmoud, September
            // 2026): completing a Production Order receives Finished
            // Goods into stock at actual cost — the same WAC-integrity
            // concern the Goods Receipt Note hard block exists to
            // prevent, just on the manufacturing side instead of the
            // purchasing side.
            if ($this->fiscalYearService->newerFiscalYearExists($order->company_id, $completionDate)) {
                throw new BackdatedPastNewerFiscalYearException(
                    "Cannot complete Production Order [{$order->order_code}] — its completion date ({$completionDate}) falls in a fiscal year that has since been superseded by a newer one. Production Order completion cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
                );
            }

            $this->stockLedger->receive($order->company_id, $product, $order->output_location_id, $order->quantity_to_produce, $standardUnitCost, $actor, [
                'movement_type'  => StockMovement::TYPE_PRODUCTION_RECEIPT,
                'reference_type' => ProductionOrder::class,
                'reference_id'   => $order->id,
                'movement_date'  => $completionDate,
            ]);

            $wipAccount = $this->resolveSystemAccount($order->company_id, '135002', 'Manufacturing WIP');

            $journalEntry = $this->journalEntryService->post(
                companyId: $order->company_id,
                entryDate: $completionDate,
                lines: [
                    ['chart_of_account_id' => $product->inventory_account_id, 'debit' => $standardTotalCost, 'credit' => '0', 'description' => 'Production completed — '.$order->order_code],
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => '0', 'credit' => $standardTotalCost, 'description' => 'Production completed — '.$order->order_code],
                ],
                sourceType: 'production_order',
                sourceId: $order->id,
                narration: "Production Order {$order->order_code} completed — {$order->quantity_to_produce} units received into Finished Goods at standard cost",
                actor: $actor,
            );

            $order->update([
                'status'                 => ProductionOrder::STATUS_COMPLETED,
                'actual_completion_date' => $completionDate,
                'journal_entry_id'       => $journalEntry->id,
            ]);

            AuditLog::record($actor, 'production_order.completed', $order, null, [
                'standard_total_cost' => $standardTotalCost, 'standard_unit_cost' => $standardUnitCost,
            ]);

            return $order->fresh();
        });
    }

    // ── Set to Draft — full reversal, same "automatic-cascade" ────
    // philosophy as everywhere else in this app (Supplier Payment,
    // Customer Payment, GRN, Delivery Note). 2026-09 — this order
    // never had ANY undo path before today, not even the physical/
    // cost tracking; now that real money posts, that gap is closed.
    //
    // Reverses everything back to a clean DRAFT, in this order:
    //   1. If completed — the Finished Goods receipt. Blocked with a
    //      clear message if that stock has already moved on
    //      (sold/transferred/consumed elsewhere) — StockLedgerService::
    //      reverseReceipt()'s own built-in check, same protection
    //      GoodsReceiptNoteService::setToDraft() relies on.
    //   2. Every labor/overhead cost entry — its own journal entry
    //      reversed, then the entry row itself deleted (this table is
    //      this app's own internal cost tracking, not a shared ledger
    //      — safe to delete once its JE is reversed, same reasoning
    //      as SupplierPaymentAllocation::delete() in
    //      SupplierPaymentService::reverseEverything()).
    //   3. Every material consumption — put back via the SAME
    //      "issue_reversal" trick DeliveryNoteService::setToDraft()
    //      already uses (reusing receive() rather than deleting the
    //      original row, since StockLedgerService pools WAC and has
    //      no in-place delete for an issue), then its own journal
    //      entry reversed.
    //
    // Only allowed from in_progress or completed — nothing to undo
    // from draft. Period-closed check uses the order's own latest
    // real date (completion date if completed, else start date) —
    // same spirit as GRN/Payment's own single-document-date check,
    // simplified for an order that can span several posting dates.
    public function setToDraft(ProductionOrder $order, User $actor): ProductionOrder
    {
        if (! in_array($order->status, [ProductionOrder::STATUS_IN_PROGRESS, ProductionOrder::STATUS_COMPLETED], true)) {
            throw new \DomainException('Only an in-progress or completed Production Order can be set back to draft.');
        }

        if ($order->material_variance_closing_id) {
            throw new \DomainException('This Production Order\'s material variance was already cleared by a monthly Variance Closing — reverse that closing first, then set the order back to draft.');
        }

        $checkDate = $order->actual_completion_date?->toDateString() ?? $order->actual_start_date?->toDateString() ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($order->company_id, $checkDate)) {
            throw new \DomainException('This Production Order\'s accounting period is closed — it cannot be reopened.');
        }

        $order->loadMissing('costEntries', 'stockMovements');

        return DB::transaction(function () use ($order, $actor) {
            $before = $order->toArray();

            // 1. Finished Goods receipt (only if completed).
            if ($order->status === ProductionOrder::STATUS_COMPLETED) {
                $receipt = $order->stockMovements
                    ->firstWhere('movement_type', StockMovement::TYPE_PRODUCTION_RECEIPT);

                if ($receipt) {
                    $this->stockLedger->reverseReceipt(
                        $order->company_id,
                        $receipt->product,
                        $receipt->inventory_location_id,
                        (string) $receipt->quantity,
                        (string) $receipt->wac_total_value,
                        $actor,
                        [
                            'reference_type'       => ProductionOrder::class,
                            'reference_id'         => $order->id,
                            'movement_date'        => $receipt->movement_date->toDateString(),
                            'original_movement_id' => $receipt->id,
                        ]
                    );
                }

                if ($order->journal_entry_id) {
                    $entry = JournalEntry::findOrFail($order->journal_entry_id);
                    $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$order->order_code);
                }
            }

            // 2. Every labor/overhead cost entry.
            foreach ($order->costEntries as $costEntry) {
                if ($costEntry->journal_entry_id) {
                    $entry = JournalEntry::findOrFail($costEntry->journal_entry_id);
                    $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$order->order_code);
                }
                $costEntry->delete();
            }

            // 3. Every material consumption.
            $consumptions = $order->stockMovements
                ->where('movement_type', StockMovement::TYPE_PRODUCTION_CONSUMPTION);

            foreach ($consumptions as $movement) {
                $this->stockLedger->receive(
                    $order->company_id,
                    $movement->product,
                    $movement->inventory_location_id,
                    (string) $movement->quantity,
                    (string) $movement->wac_unit_cost,
                    $actor,
                    [
                        'movement_type'  => StockMovement::TYPE_ISSUE_REVERSAL,
                        'reference_type' => ProductionOrder::class,
                        'reference_id'   => $order->id,
                        'movement_date'  => $movement->movement_date->toDateString(),
                        'notes'          => 'Reversal of an earlier consumption — Production Order reopened to draft.',
                    ]
                );

                $consumptionEntry = JournalEntry::where('company_id', $order->company_id)
                    ->where('source_type', 'production_order_consumption')
                    ->where('source_id', $movement->id)
                    ->first();

                if ($consumptionEntry) {
                    $this->journalEntryService->reverse($consumptionEntry, $actor, 'Reopened to draft — '.$order->order_code);
                }
            }

            $order->update([
                'status'                 => ProductionOrder::STATUS_DRAFT,
                'actual_start_date'      => null,
                'actual_completion_date' => null,
                'journal_entry_id'       => null,
            ]);

            AuditLog::record($actor, 'production_order.set_to_draft', $order, $before, $order->fresh()->toArray());

            return $order->fresh(['product', 'billOfMaterial', 'rawMaterialLocation', 'outputLocation']);
        });
    }

    // ── Variance — reporting only, does not close/dispose ────────

    public function variance(ProductionOrder $order): array
    {
        $qty = $order->quantity_to_produce;

        $standardMaterial = bcmul($order->standard_material_cost_per_unit, $qty, 2);
        $standardLabor    = bcmul($order->standard_labor_cost_per_unit, $qty, 2);
        $standardOverhead = bcmul($order->standard_overhead_cost_per_unit, $qty, 2);

        $actualMaterial = (string) $order->stockMovements()
            ->where('movement_type', StockMovement::TYPE_PRODUCTION_CONSUMPTION)
            ->sum('wac_total_value');
        $actualLabor    = (string) $order->costEntries()->where('entry_type', ProductionOrderCostEntry::TYPE_LABOR)->sum('actual_amount');
        $actualOverhead = (string) $order->costEntries()->where('entry_type', ProductionOrderCostEntry::TYPE_OVERHEAD)->sum('actual_amount');

        return [
            'material' => ['standard' => $standardMaterial, 'actual' => $actualMaterial, 'variance' => bcsub($actualMaterial, $standardMaterial, 2)],
            'labor'    => ['standard' => $standardLabor, 'actual' => $actualLabor, 'variance' => bcsub($actualLabor, $standardLabor, 2)],
            'overhead' => ['standard' => $standardOverhead, 'actual' => $actualOverhead, 'variance' => bcsub($actualOverhead, $standardOverhead, 2)],
        ];
    }

    // ── Standard cost snapshot calculation ───────────────────────

    // MFG-004 fix (confirmed with Mahmoud, 2026-09) — each of these
    // three per-unit methods is split into a "…Total" method (the
    // exact per-batch figure, before any division) and a thin
    // per-unit wrapper around it, so create() can store the exact
    // totals and complete() never has to multiply a rounded per-unit
    // rate back out to reconstruct them (that round trip is what was
    // losing a cent — see the two new *_cost_total columns).

    /**
     * One BOM batch's cost, scaled to the quantity this order
     * actually produces.
     *
     * A Bill of Materials states its component quantities per batch
     * of output_quantity units, so every …CostTotal() below is a
     * per-BATCH figure. An order that produces a different number of
     * units needs that figure restated for its own quantity before
     * it can be stored as the order's standard total.
     */
    private function scaleBatchTotalToOrder(string $batchTotal, BillOfMaterial $bom, string $orderQuantity): string
    {
        if (bccomp((string) $bom->output_quantity, '0', 4) <= 0) {
            return '0.00';
        }

        return $this->bcround(
            bcdiv(bcmul($batchTotal, $orderQuantity, 6), (string) $bom->output_quantity, 6),
            2
        );
    }

    private function standardMaterialCostTotal(BillOfMaterial $bom): string
    {
        $totalPerBatch = '0.00';

        foreach ($bom->lines as $line) {
            $balance = ProductStockBalance::where('company_id', $bom->company_id)
                ->where('product_id', $line->component_product_id)
                ->first();
            $wac = $balance?->wac_unit_cost ?? '0.0000';
            $totalPerBatch = bcadd($totalPerBatch, bcmul($line->quantity_per_batch, $wac, 2), 2);
        }

        return $totalPerBatch;
    }

    private function standardMaterialCostPerUnit(BillOfMaterial $bom): string
    {
        $totalPerBatch = $this->standardMaterialCostTotal($bom);

        return bccomp($bom->output_quantity, '0', 4) > 0 ? bcdiv($totalPerBatch, $bom->output_quantity, 4) : '0.0000';
    }

    private function standardLaborCostTotal(int $companyId, BillOfMaterial $bom, FiscalYear $fiscalYear, int $year, int $month): string
    {
        $totalPerBatch = '0.00';

        foreach ($bom->operations as $operation) {
            if (bccomp((string) $operation->standard_labor_hours, '0', 4) <= 0) {
                continue;
            }

            $rate = $this->standardRates->effectiveLaborCategoryRate(
                $companyId, $fiscalYear->id, $operation->labor_category_id, $year, $month
            );

            $totalPerBatch = bcadd($totalPerBatch, bcmul($operation->standard_labor_hours, $rate, 2), 2);
        }

        return $totalPerBatch;
    }

    private function standardLaborCostPerUnit(int $companyId, BillOfMaterial $bom, FiscalYear $fiscalYear, int $year, int $month): string
    {
        $totalPerBatch = $this->standardLaborCostTotal($companyId, $bom, $fiscalYear, $year, $month);

        return bccomp($bom->output_quantity, '0', 4) > 0 ? bcdiv($totalPerBatch, $bom->output_quantity, 4) : '0.0000';
    }

    // Every Operation's overhead cost is the SUM across every Overhead
    // Pool linked to its own default Machine (Section 4.4, Option A —
    // one machine can feed several pools at once, e.g. its own
    // maintenance contract plus a shared factory-electricity pool).
    // This is what actually fixes the original complaint every
    // product used to absorb every active pool in the company
    // regardless of which machine it used — see the Routing worked
    // example, Section 5.3.
    private function standardOverheadCostTotal(int $companyId, BillOfMaterial $bom, FiscalYear $fiscalYear, int $year, int $month): string
    {
        $totalPerBatch = '0.00';

        foreach ($bom->operations as $operation) {
            $machine = $operation->defaultMachine;
            if (! $machine) {
                continue;
            }

            foreach ($machine->machineOverheadPools as $link) {
                $pool = $link->overheadPool;
                if (! $pool) {
                    continue;
                }

                $baseQuantity = match ($pool->allocation_base) {
                    OverheadPool::BASE_LABOR_HOURS      => $operation->standard_labor_hours,
                    OverheadPool::BASE_MACHINE_HOURS    => $operation->standard_machine_hours,
                    OverheadPool::BASE_PRODUCTION_UNITS => $bom->output_quantity,
                };

                if (! $baseQuantity || bccomp((string) $baseQuantity, '0', 4) <= 0) {
                    continue;
                }

                $rate = $this->standardRates->effectiveOverheadRate($companyId, $fiscalYear->id, $pool->id, $year, $month);
                $totalPerBatch = bcadd($totalPerBatch, bcmul((string) $baseQuantity, $rate, 2), 2);
            }
        }

        return $totalPerBatch;
    }

    private function standardOverheadCostPerUnit(int $companyId, BillOfMaterial $bom, FiscalYear $fiscalYear, int $year, int $month): string
    {
        $totalPerBatch = $this->standardOverheadCostTotal($companyId, $bom, $fiscalYear, $year, $month);

        return bccomp($bom->output_quantity, '0', 4) > 0 ? bcdiv($totalPerBatch, $bom->output_quantity, 4) : '0.0000';
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function assertInProgress(ProductionOrder $order): void
    {
        if ($order->status !== ProductionOrder::STATUS_IN_PROGRESS) {
            throw new \DomainException('This action requires the Production Order to be in progress.');
        }
    }

    // 2026-09 GL Posting fix — resolved fresh every time, never
    // cached, same pattern Rule 34.5/35.4 already establish for GRNI
    // (233) and every other system-level COA account this app resolves.
    private function resolveSystemAccount(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }

    private function resolveFiscalYear(int $companyId, string $date): FiscalYear
    {
        $fy = FiscalYear::where('company_id', $companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if (! $fy) {
            throw new \DomainException("No fiscal year covers {$date} — create one before opening a Production Order.");
        }

        return $fy;
    }

    private function yearMonth(string $date): array
    {
        $carbon = \Carbon\Carbon::parse($date);
        return [(int) $carbon->format('Y'), (int) $carbon->format('n')];
    }

    private function generateOrderCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\ProductionOrder::class, 'order_code', 'PROD', $date);
    }

    /**
     * Round half up at $scale. bcmath only ever truncates, which
     * biases every rounded figure one direction — the same reasoning
     * as the INV-005 fix in StockLedgerService, and the same
     * implementation, so both sides of a manufacturing entry round
     * identically.
     */
    private function bcround(string $number, int $scale = 2): string
    {
        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        return bccomp($number, '0', $scale + 10) >= 0
            ? bcadd($number, $halfUnit, $scale)
            : bcsub($number, $halfUnit, $scale);
    }
}
