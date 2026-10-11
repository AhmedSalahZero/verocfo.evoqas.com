<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderService
//
//  The Job Order engine — same four-responsibility shape as
//  ProductionOrderService (create/postings/complete/variance), but a
//  genuinely separate implementation, per Mahmoud's explicit
//  instruction to keep Job Order apart from Mass Production for
//  clarity in fixing and debugging. The only code this service
//  shares with ProductionOrderService is StockLedgerService and
//  StandardRateService — both already shared, deliberate points of
//  reuse. The fiscal-year/code-generation helpers below are
//  duplicated rather than extracted into a shared base class,
//  intentionally — a change to one module's helper should never risk
//  silently altering the other's behavior.
//
//  1. create() — accepts EITHER a job_order_template_id (lines
//     copied from the template's per-unit figures × quantity_to_
//     produce, then fully independent) OR an ad-hoc `lines` array
//     (confirmed: support both). estimated_material_cost_per_unit is
//     auto-calculated from those lines × current WAC — the same
//     mechanism Mass Production uses for its standard cost, just not
//     locked to a governed BOM version. estimated_labor_cost_per_unit
//     / estimated_overhead_cost_per_unit are direct user input — a
//     one-off job has no repeatable routing figure to calculate them
//     from (see migration 000094 header).
//
//  2. consumeMaterial() / recordLaborCost() / recordOverheadCost() —
//     identical mechanics to Mass Production's progressive WIP
//     postings, but writing job_consumption/job_receipt movement
//     types and JobOrderCostEntry rows (a separate table from
//     ProductionOrderCostEntry). Overhead's rate still resolves
//     through the SAME StandardRateService/OverheadPool mechanism —
//     the one deliberate point of reuse. Each one now posts its own
//     real journal entry too (2026-09 GL Posting fix — identical GL
//     shape to ProductionOrderService, see that class's header for
//     the full explanation; not repeated here per Rule 35.5's
//     "duplicated intentionally, not shared" reasoning).
//
//  3. complete() — posts Finished Goods at STANDARD cost (2026-09
//     fix, Manufacturing Variance Closing Design v1.0, Steps 2 and
//     6), into this job's OWN valuation segment — never blended into
//     ProductStockBalance's company-wide pooled WAC, since every Job
//     Order's build is genuinely different even when it shares a
//     Product code (confirmed precedent: SAP "Individual Valuation" /
//     Oracle Project Manufacturing). See complete()'s own header for
//     the full mechanism and JobOrderStockBalanceService for the
//     segment itself. Also posts its own completion journal entry
//     (2026-09).
//
//  4. variance() — estimated (captured at job opening × quantity_to_
//     produce) vs actual, broken out by material/labor/overhead.
//     Reporting only — never closes/disposes; that's the forthcoming
//     monthly Variance Closing step's job.
//
//  GL POSTING (2026-09 fix) — same shape as ProductionOrderService:
//    consumeMaterial()   Dr 135002 WIP / Cr component's own inventory_account_id
//    recordLaborCost()   Dr 135002 WIP / Cr 237 Direct Labor Clearing
//    recordOverheadCost()Dr 135002 WIP / Cr 238 Manufacturing Overhead Absorbed
//    complete()           Dr FG product's inventory_account_id / Cr 135002 WIP
//  cost_center_id (2026-09) — optional allocation dimension on every
//  cost entry, same as ProductionOrderCostEntry.
//
//  setToDraft() (2026-09) — same full-reversal shape as
//  ProductionOrderService::setToDraft(), duplicated not shared per
//  Rule 35.5.
//
//  JOB ORDER ROUTING (Section 6, Sept 2026) — a job can OPTIONALLY be
//  broken into Pieces, each with its own sequence of Operations
//  (Process/Machine/Labor Category — same building blocks as BOM
//  Routing). Confirmed with Mahmoud:
//    - Pieces are NOT sequential — several can be in_progress at once.
//    - Cost is ALWAYS automatic once a job uses Pieces — logging hours
//      against an Operation (logPieceOperationCost()) computes cost
//      from the Labor Category / Machine's Overhead Pool rate(s),
//      exactly like BOM Routing's standard cost calculation, but
//      posting as real (not standard) cost immediately — never a
//      manually-typed number. The original flat recordLaborCost()/
//      recordOverheadCost() methods above are UNCHANGED and still the
//      right tool for a simple job that never creates a Piece.
//    - complete() now requires every Piece be Completed first, when
//      the job has any — but still receives Finished Goods as ONE
//      product (job.product_id), never separate piece-products.
//
//  @layer    Service
//  @depends  JobOrder, JobOrderLine, JobOrderCostEntry, JobOrderPiece,
//            JobOrderOperation, JobOrderTemplate, OverheadPool,
//            StandardRate, FiscalYear, Product, ChartOfAccount,
//            ProductStockBalance, StockMovement, StockLedgerService,
//            StandardRateService, JournalEntryService, FiscalYearService,
//            User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\JobOrder;
use App\Models\JobOrderCostEntry;
use App\Models\JobOrderLine;
use App\Models\JobOrderOperation;
use App\Models\JobOrderPiece;
use App\Models\JobOrderTemplate;
use App\Models\JournalEntry;
use App\Models\OverheadPool;
use App\Models\Product;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JobOrderService
{
    public function __construct(
        private StockLedgerService $stockLedger,
        private StandardRateService $standardRates,
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private OverheadPoolService $overheadPools,
        private JobOrderStockBalanceService $jobOrderStockBalances,
    ) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return JobOrder::with(['product:id,code,name_en', 'salesOrder:id,so_code', 'outputLocation:id,name_en'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create — supports template-sourced OR ad-hoc lines ───────

    public function create(int $companyId, array $data, User $actor): JobOrder
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $templateId = $data['job_order_template_id'] ?? null;
            $lines      = $data['lines'] ?? [];

            if ($templateId) {
                $template = JobOrderTemplate::with('lines')
                    ->where('company_id', $companyId)
                    ->findOrFail($templateId);

                if ($template->product_id && (int) $template->product_id !== (int) $data['product_id']) {
                    throw new \DomainException('This template was made for a different product — pick a template for this product, or a generic one.');
                }

                $lines = $template->lines->map(fn ($l) => [
                    'component_product_id' => $l->component_product_id,
                    'quantity'              => bcmul($l->quantity_per_unit, $data['quantity_to_produce'], 4),
                    'uom_id'                => $l->uom_id,
                ])->all();
            }

            if (empty($lines)) {
                throw new \DomainException('A Job Order needs at least one material line — from a template or entered directly.');
            }

            $estimatedMaterialTotal   = $this->materialCostTotal($companyId, $lines);
            $estimatedMaterialPerUnit = $this->estimatedMaterialCostPerUnit($companyId, $lines, $data['quantity_to_produce']);

            $job = JobOrder::create([
                'company_id'                        => $companyId,
                'job_code'                           => $this->generateJobCode($companyId, $data['planned_start_date'] ?? null),
                'job_order_template_id'              => $templateId,
                'sales_order_id'                     => $data['sales_order_id'] ?? null,
                'product_id'                         => $data['product_id'],
                'quantity_to_produce'                => $data['quantity_to_produce'],
                'raw_material_location_id'           => $data['raw_material_location_id'],
                'output_location_id'                 => $data['output_location_id'],
                'status'                              => JobOrder::STATUS_DRAFT,
                'planned_start_date'                  => $data['planned_start_date'] ?? null,
                'estimated_material_cost_per_unit'   => $estimatedMaterialPerUnit,
                'estimated_material_cost_total'      => $estimatedMaterialTotal,
                'estimated_labor_cost_per_unit'      => $data['estimated_labor_cost_per_unit'] ?? null,
                'estimated_overhead_cost_per_unit'   => $data['estimated_overhead_cost_per_unit'] ?? null,
                'notes'                               => $data['notes'] ?? null,
                'created_by'                          => $actor->id,
            ]);

            $this->syncLines($job, $companyId, $lines);

            AuditLog::record($actor, 'job_order.created', $job, null, $job->toArray());

            return $job->fresh(['product', 'template', 'salesOrder', 'rawMaterialLocation', 'outputLocation', 'lines.componentProduct']);
        });
    }

    public function start(JobOrder $job, User $actor): JobOrder
    {
        if ($job->status !== JobOrder::STATUS_DRAFT) {
            throw new \DomainException('Only a draft Job Order can be started.');
        }

        $job->update(['status' => JobOrder::STATUS_IN_PROGRESS, 'actual_start_date' => now()->toDateString()]);
        AuditLog::record($actor, 'job_order.started', $job, null, $job->fresh()->toArray());

        return $job->fresh();
    }

    // ── Progressive WIP postings ──────────────────────────────────

    // MFG-005 fix (confirmed with Mahmoud, 2026-09) — the stock issue
    // and its journal entry are ONE operation, not two.
    //
    // StockLedgerService::issue() opens and COMMITS a transaction of
    // its own. Before this, the journal entry was posted afterwards in
    // a separate statement, so anything that made the posting throw —
    // a closed accounting period, a product with no inventory account,
    // an unresolvable WIP account — left the stock already gone from
    // inventory with no accounting entry behind it, permanently and
    // with nothing to roll it back. The cycle hit this 6 times across
    // two years.
    //
    // Wrapping both in an OUTER transaction makes issue()'s own
    // commit a savepoint inside this one, so a failure anywhere below
    // takes the movement with it. Either the material left inventory
    // and the ledger says so, or neither happened.
    // MFG-006 fix (2026-09) — $entryDate, in the same position as
    // ProductionOrderService::consumeMaterial()'s.
    //
    // The controller and the form request were updated for this in an
    // earlier pass; this signature was not. PHP silently discards
    // arguments a userland function does not declare, so the call
    //
    //     consumeMaterial($job, $id, $qty, $user, $entryDate, $notes)
    //
    // bound $entryDate to $notes and dropped the real note on the
    // floor. The consumption was still dated today — the defect this
    // was meant to close — AND the movement's note became a date
    // string. A missing parameter is not an inert omission when the
    // caller is already passing the argument.
    public function consumeMaterial(JobOrder $job, int $componentProductId, string $quantity, User $actor, ?string $entryDate = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($job, $componentProductId, $quantity, $actor, $entryDate, $notes) {
            $this->consumeMaterialWithin($job, $componentProductId, $quantity, $actor, $entryDate, $notes);
        });
    }

    private function consumeMaterialWithin(JobOrder $job, int $componentProductId, string $quantity, User $actor, ?string $entryDate, ?string $notes): void
    {
        $this->assertInProgress($job);

        $component = Product::where('company_id', $job->company_id)->findOrFail($componentProductId);

        if (! $component->inventory_account_id) {
            throw new \DomainException("Product \"{$component->name_en}\" has no inventory account assigned — cannot post.");
        }

        $movement = $this->stockLedger->issue($job->company_id, $component, $job->raw_material_location_id, $quantity, $actor, [
            'movement_type'  => StockMovement::TYPE_JOB_CONSUMPTION,
            'reference_type' => JobOrder::class,
            'reference_id'   => $job->id,
            'movement_date'  => $entryDate ?? now()->toDateString(),
            'notes'          => $notes,
        ]);

        $wipAccount = $this->resolveSystemAccount($job->company_id, '135002', 'Manufacturing WIP');

        $this->journalEntryService->post(
            companyId: $job->company_id,
            entryDate: $movement->movement_date,
            lines: [
                ['chart_of_account_id' => $wipAccount->id, 'debit' => (string) $movement->wac_total_value, 'credit' => '0', 'description' => 'Material consumed — '.$job->job_code],
                ['chart_of_account_id' => $component->inventory_account_id, 'debit' => '0', 'credit' => (string) $movement->wac_total_value, 'description' => 'Material consumed — '.$job->job_code],
            ],
            sourceType: 'job_order_consumption',
            sourceId: $movement->id,
            narration: "Job Order {$job->job_code} — material consumed: {$component->name_en}",
            actor: $actor,
        );

        AuditLog::record($actor, 'job_order.material_consumed', $job, null, [
            'component_product_id' => $componentProductId, 'quantity' => $quantity,
        ]);
    }

    // 2026-09 fix (Manufacturing Variance Closing Design v1.0, Step 1):
    // $actualCost is calculated from hours × the standard rate, same
    // as ProductionOrderService::recordLaborCost() and
    // logPieceOperationCost() below — no more manual typing on this,
    // the flat (non-Piece) path, either.
    public function recordLaborCost(JobOrder $job, string $hours, User $actor, ?string $entryDate = null, ?string $notes = null, ?int $costCenterId = null): JobOrderCostEntry
    {
        $this->assertInProgress($job);

        $entryDate  = $entryDate ?? now()->toDateString();
        [$year, $month] = $this->yearMonth($entryDate);
        $fiscalYear = $this->resolveFiscalYear($job->company_id, $entryDate);
        $rate       = $this->standardRates->effectiveLaborRate($job->company_id, $fiscalYear->id, $year, $month);
        $actualCost = bcmul($hours, $rate, 2);

        return DB::transaction(function () use ($job, $hours, $actualCost, $actor, $entryDate, $notes, $costCenterId, $rate) {
            $entry = JobOrderCostEntry::create([
                'company_id'     => $job->company_id,
                'job_order_id'   => $job->id,
                'entry_type'     => JobOrderCostEntry::TYPE_LABOR,
                'base_quantity'  => $hours,
                'rate_applied'   => $rate,
                'actual_amount'  => $actualCost,
                'cost_center_id' => $costCenterId,
                'entry_date'     => $entryDate,
                'notes'          => $notes,
                'created_by'     => $actor->id,
            ]);

            $wipAccount    = $this->resolveSystemAccount($job->company_id, '135002', 'Manufacturing WIP');
            $laborClearing = $this->resolveSystemAccount($job->company_id, '237', 'Direct Labor Clearing');

            $journalEntry = $this->journalEntryService->post(
                companyId: $job->company_id,
                entryDate: $entryDate,
                lines: [
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => $actualCost, 'credit' => '0', 'cost_center_id' => $costCenterId, 'description' => 'Labor cost — '.$job->job_code],
                    ['chart_of_account_id' => $laborClearing->id, 'debit' => '0', 'credit' => $actualCost, 'cost_center_id' => $costCenterId, 'description' => 'Labor cost — '.$job->job_code],
                ],
                sourceType: 'job_order_cost_entry',
                sourceId: $entry->id,
                narration: "Job Order {$job->job_code} — labor cost recorded",
                actor: $actor,
            );

            $entry->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'job_order.labor_recorded', $job, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    // Same fix as recordLaborCost() immediately above.
    public function recordOverheadCost(JobOrder $job, OverheadPool $pool, string $baseQuantity, User $actor, ?string $entryDate = null, ?string $notes = null, ?int $costCenterId = null): JobOrderCostEntry
    {
        $this->assertInProgress($job);

        $entryDate  = $entryDate ?? now()->toDateString();
        [$year, $month] = $this->yearMonth($entryDate);
        $fiscalYear = $this->resolveFiscalYear($job->company_id, $entryDate);
        $rate       = $this->standardRates->effectiveOverheadRate($job->company_id, $fiscalYear->id, $pool->id, $year, $month);
        $actualCost = bcmul($baseQuantity, $rate, 2);

        return DB::transaction(function () use ($job, $pool, $baseQuantity, $actualCost, $actor, $entryDate, $notes, $costCenterId, $rate) {
            $entry = JobOrderCostEntry::create([
                'company_id'       => $job->company_id,
                'job_order_id'     => $job->id,
                'entry_type'       => JobOrderCostEntry::TYPE_OVERHEAD,
                'overhead_pool_id' => $pool->id,
                'base_quantity'    => $baseQuantity,
                'rate_applied'     => $rate,
                'actual_amount'    => $actualCost,
                'cost_center_id'   => $costCenterId,
                'entry_date'       => $entryDate,
                'notes'            => $notes,
                'created_by'       => $actor->id,
            ]);

            $wipAccount       = $this->resolveSystemAccount($job->company_id, '135002', 'Manufacturing WIP');
            // Section 7 — this pool's own dedicated sub-account, not a
            // shared balance — see ProductionOrderService's identical
            // fix for the full reasoning.
            $overheadAbsorbed = $this->overheadPools->ensureAppliedAccount($pool, $actor);

            $journalEntry = $this->journalEntryService->post(
                companyId: $job->company_id,
                entryDate: $entryDate,
                lines: [
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => $actualCost, 'credit' => '0', 'cost_center_id' => $costCenterId, 'description' => 'Overhead cost — '.$pool->name.' — '.$job->job_code],
                    ['chart_of_account_id' => $overheadAbsorbed->id, 'debit' => '0', 'credit' => $actualCost, 'cost_center_id' => $costCenterId, 'description' => 'Overhead cost — '.$pool->name.' — '.$job->job_code],
                ],
                sourceType: 'job_order_cost_entry',
                sourceId: $entry->id,
                narration: "Job Order {$job->job_code} — overhead cost recorded ({$pool->name})",
                actor: $actor,
            );

            $entry->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'job_order.overhead_recorded', $job, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    // ── WIP balance — material (stock_movements) + cost entries ──
    // 2026-09 fix: nets TYPE_ISSUE_REVERSAL — same reasoning as
    // ProductionOrderService::wipBalance().
    public function wipBalance(JobOrder $job): string
    {
        $consumed = (string) $job->stockMovements()
            ->where('movement_type', StockMovement::TYPE_JOB_CONSUMPTION)
            ->sum('wac_total_value');

        $reversed = (string) $job->stockMovements()
            ->where('movement_type', StockMovement::TYPE_ISSUE_REVERSAL)
            ->sum('wac_total_value');

        $materialValue = bcsub($consumed, $reversed, 2);

        $costValue = (string) $job->costEntries()->sum('actual_amount');

        return bcadd($materialValue, $costValue, 2);
    }

    // ── Complete — post Finished Goods at STANDARD cost, into this
    // job's OWN valuation segment (2026-09 fix, Manufacturing Variance
    // Closing Design v1.0, Steps 2 and 6 combined). Two changes from
    // the original version of this method:
    //
    //   1. Standard Cost, not actual. Material uses the same
    //      pre-set snapshot Mass Production uses (estimated_material_
    //      cost_per_unit × quantity — calculated at job creation, see
    //      create()). Labor and overhead use the sum of this job's
    //      own cost entries — which, since the 2026-09 Step 1 fix,
    //      are ALREADY calculated as hours × the standard rate for
    //      BOTH the flat path (recordLaborCost/recordOverheadCost)
    //      and the Piece/Operation path (logPieceOperationCost) — no
    //      separate calculation needed, no branching between the two
    //      paths required here.
    //   2. Segment, not the pooled Product balance. Every Job Order's
    //      output is genuinely different even when it shares a
    //      Product code (confirmed with Mahmoud — SAP "Individual
    //      Valuation" / Oracle Project Manufacturing precedent), so it
    //      must never blend into ProductStockBalance's company-wide
    //      average. stockLedger->receive() is called WITH job_order_id
    //      set, which makes it skip the pooled balance entirely (see
    //      that method's own header); JobOrderStockBalanceService
    //      then records this job's own segment separately.
    //
    // WIP no longer nets to zero here — same reasoning as
    // ProductionOrderService::complete(). Whatever's left (this job's
    // real cost minus its standard cost) is its own variance,
    // intentionally left in WIP until the monthly Variance Closing
    // step (not yet built) sweeps it out.

    public function complete(JobOrder $job, User $actor, ?string $completionDate = null): JobOrder
    {
        return DB::transaction(function () use ($job, $actor, $completionDate) {
            $this->assertInProgress($job);
            $this->assertAllPiecesCompleted($job);

            // MFG-004 fix (confirmed with Mahmoud, 2026-09) — uses the
            // exact total captured at creation time (before it was
            // ever divided into a per-unit rate), so nothing is lost
            // to rounding. Falls back to the old multiply-back
            // calculation only for a Job Order created before this
            // column existed (estimated_material_cost_total is null).
            $materialStandardTotal = $job->estimated_material_cost_total !== null
                ? (string) $job->estimated_material_cost_total
                : bcmul((string) $job->estimated_material_cost_per_unit, $job->quantity_to_produce, 2);
            $laborOverheadTotal    = (string) $job->costEntries()
                ->whereIn('entry_type', [JobOrderCostEntry::TYPE_LABOR, JobOrderCostEntry::TYPE_OVERHEAD])
                ->sum('actual_amount');
            $standardTotalCost = bcadd($materialStandardTotal, $laborOverheadTotal, 2);
            $standardUnitCost  = bccomp($job->quantity_to_produce, '0', 4) > 0
                ? bcdiv($standardTotalCost, $job->quantity_to_produce, 4)
                : '0.0000';

            $product = Product::where('company_id', $job->company_id)->findOrFail($job->product_id);

            if (! $product->inventory_account_id) {
                throw new \DomainException("Product \"{$product->name_en}\" has no inventory account assigned — cannot post.");
            }

            $completionDate = $completionDate ?? now()->toDateString();

            // Rule 26.5 extension (confirmed with Mahmoud, September
            // 2026): completing a Job Order receives Finished Goods
            // into stock at actual cost — the same WAC-integrity
            // concern the Goods Receipt Note hard block exists to
            // prevent, just on the manufacturing side instead of the
            // purchasing side.
            if ($this->fiscalYearService->newerFiscalYearExists($job->company_id, $completionDate)) {
                throw new BackdatedPastNewerFiscalYearException(
                    "Cannot complete Job Order [{$job->job_code}] — its completion date ({$completionDate}) falls in a fiscal year that has since been superseded by a newer one. Job Order completion cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
                );
            }

            $this->stockLedger->receive($job->company_id, $product, $job->output_location_id, $job->quantity_to_produce, $standardUnitCost, $actor, [
                'movement_type'  => StockMovement::TYPE_JOB_RECEIPT,
                'reference_type' => JobOrder::class,
                'reference_id'   => $job->id,
                'job_order_id'   => $job->id,
                'movement_date'  => $completionDate,
            ]);

            $this->jobOrderStockBalances->receive($job, $product, $job->quantity_to_produce, $standardUnitCost, $standardTotalCost);

            $wipAccount = $this->resolveSystemAccount($job->company_id, '135002', 'Manufacturing WIP');

            $journalEntry = $this->journalEntryService->post(
                companyId: $job->company_id,
                entryDate: $completionDate,
                lines: [
                    ['chart_of_account_id' => $product->inventory_account_id, 'debit' => $standardTotalCost, 'credit' => '0', 'description' => 'Job Order completed — '.$job->job_code],
                    ['chart_of_account_id' => $wipAccount->id, 'debit' => '0', 'credit' => $standardTotalCost, 'description' => 'Job Order completed — '.$job->job_code],
                ],
                sourceType: 'job_order',
                sourceId: $job->id,
                narration: "Job Order {$job->job_code} completed — {$job->quantity_to_produce} units received into its own Finished Goods segment at standard cost",
                actor: $actor,
            );

            $job->update([
                'status'                 => JobOrder::STATUS_COMPLETED,
                'actual_completion_date' => $completionDate,
                'journal_entry_id'       => $journalEntry->id,
            ]);

            AuditLog::record($actor, 'job_order.completed', $job, null, [
                'standard_total_cost' => $standardTotalCost, 'standard_unit_cost' => $standardUnitCost,
            ]);

            return $job->fresh();
        });
    }

    // ── Job Order Routing (Section 6) — optional Piece breakdown ──

    // A Piece can be added any time the job is Draft or In Progress —
    // mirrors how material lines stay editable throughout a job's
    // life, not just at creation. Starts at not_started; nothing here
    // requires the job to already have any Operations defined.
    public function addPiece(int $companyId, JobOrder $job, array $data, User $actor): JobOrderPiece
    {
        if (! in_array($job->status, [JobOrder::STATUS_DRAFT, JobOrder::STATUS_IN_PROGRESS], true)) {
            throw new \DomainException('Pieces can only be added to a Draft or In-Progress Job Order.');
        }

        $nextSort = (int) JobOrderPiece::where('job_order_id', $job->id)->max('sort_order') + 1;

        $piece = JobOrderPiece::create([
            'company_id'   => $companyId,
            'job_order_id' => $job->id,
            'name'         => $data['name'],
            'quantity'     => $data['quantity'] ?? null,
            'sort_order'   => $nextSort,
            'status'       => JobOrderPiece::STATUS_NOT_STARTED,
        ]);

        AuditLog::record($actor, 'job_order.piece_added', $job, null, $piece->toArray());

        return $piece;
    }

    public function addOperation(int $companyId, JobOrderPiece $piece, array $data, User $actor): JobOrderOperation
    {
        if ($piece->isCompleted()) {
            throw new \DomainException('This piece is already completed — its routing can no longer be changed.');
        }

        $nextSequence = (int) JobOrderOperation::where('job_order_piece_id', $piece->id)->max('sequence') + 1;

        $operation = JobOrderOperation::create([
            'company_id'         => $companyId,
            'job_order_piece_id' => $piece->id,
            'sequence'           => $nextSequence,
            'process_id'         => $data['process_id'],
            'machine_id'         => $data['machine_id'],
            'labor_category_id'  => $data['labor_category_id'],
        ]);

        AuditLog::record($actor, 'job_order.operation_added', $piece, null, $operation->toArray());

        return $operation;
    }

    // THE key new mechanism — confirmed with Mahmoud: no manual cost
    // entry at all once a job uses Pieces. Labor cost = hours × this
    // Operation's Labor Category rate. Overhead cost = SUM across
    // every Overhead Pool linked to this Operation's Machine (Section
    // 4.4, Option A) — same multi-pool logic as
    // ProductionOrderService::standardOverheadCostPerUnit(), just
    // posting real cost immediately instead of a per-unit standard.
    // Automatically moves the piece from not_started to in_progress
    // the first time this is called — never inferred any further than
    // that; reaching 'completed' is always an explicit user action
    // (completePiece() below).
    public function logPieceOperationCost(
        JobOrderOperation $operation,
        string $laborHours,
        string $machineHours,
        User $actor,
        ?string $entryDate = null,
        ?string $notes = null,
        ?int $costCenterId = null
    ): array {
        $piece = $operation->piece;
        $job   = $piece->jobOrder;

        $this->assertInProgress($job);

        if ($piece->isCompleted()) {
            throw new \DomainException('This piece is already completed — no further cost can be logged against it.');
        }

        $entryDate  = $entryDate ?? now()->toDateString();
        [$year, $month] = $this->yearMonth($entryDate);
        $fiscalYear = $this->resolveFiscalYear($job->company_id, $entryDate);

        return DB::transaction(function () use ($job, $piece, $operation, $laborHours, $machineHours, $actor, $entryDate, $notes, $costCenterId, $fiscalYear, $year, $month) {
            $entries = [];

            if (bccomp($laborHours, '0', 4) > 0) {
                $rate = $this->standardRates->effectiveLaborCategoryRate(
                    $job->company_id, $fiscalYear->id, $operation->labor_category_id, $year, $month
                );
                $entries[] = $this->postJobOperationCost(
                    $job, $operation, JobOrderCostEntry::TYPE_LABOR, null,
                    $laborHours, $rate, $actor, $entryDate, $notes, $costCenterId,
                    creditAccount: $this->resolveSystemAccount($job->company_id, '237', 'Direct Labor Clearing'),
                    narrationSuffix: 'labor cost logged'
                );
            }

            if (bccomp($machineHours, '0', 4) > 0) {
                $machine = $operation->machine()->with('machineOverheadPools.overheadPool')->first();
                foreach ($machine->machineOverheadPools as $link) {
                    $pool = $link->overheadPool;
                    if (! $pool) continue;

                    $baseQuantity = match ($pool->allocation_base) {
                        OverheadPool::BASE_LABOR_HOURS      => $laborHours,
                        OverheadPool::BASE_MACHINE_HOURS    => $machineHours,
                        OverheadPool::BASE_PRODUCTION_UNITS => $piece->quantity ?? '0',
                    };

                    if (! $baseQuantity || bccomp((string) $baseQuantity, '0', 4) <= 0) continue;

                    $rate = $this->standardRates->effectiveOverheadRate($job->company_id, $fiscalYear->id, $pool->id, $year, $month);
                    $entries[] = $this->postJobOperationCost(
                        $job, $operation, JobOrderCostEntry::TYPE_OVERHEAD, $pool->id,
                        (string) $baseQuantity, $rate, $actor, $entryDate, $notes, $costCenterId,
                        // Section 7 — this pool's own sub-account, not
                        // a shared balance, same reasoning as every
                        // other overhead-crediting call site.
                        creditAccount: $this->overheadPools->ensureAppliedAccount($pool, $actor),
                        narrationSuffix: 'overhead cost logged — '.$pool->name
                    );
                }
            }

            if ($piece->status === JobOrderPiece::STATUS_NOT_STARTED) {
                $piece->update(['status' => JobOrderPiece::STATUS_IN_PROGRESS]);
            }

            return $entries;
        });
    }

    public function completePiece(JobOrderPiece $piece, User $actor): JobOrderPiece
    {
        if ($piece->isCompleted()) {
            throw new \DomainException('This piece is already completed.');
        }

        $before = $piece->toArray();
        $piece->update(['status' => JobOrderPiece::STATUS_COMPLETED]);

        AuditLog::record($actor, 'job_order.piece_completed', $piece->jobOrder, $before, $piece->fresh()->toArray());

        return $piece->fresh();
    }

    // ── Set to Draft — full reversal, identical shape to ──────────
    // ProductionOrderService::setToDraft() — see that method's header
    // for the full reasoning (Rule 35.5: duplicated intentionally,
    // not shared).
    public function setToDraft(JobOrder $job, User $actor): JobOrder
    {
        if (! in_array($job->status, [JobOrder::STATUS_IN_PROGRESS, JobOrder::STATUS_COMPLETED], true)) {
            throw new \DomainException('Only an in-progress or completed Job Order can be set back to draft.');
        }

        if ($job->material_variance_closing_id) {
            throw new \DomainException('This Job Order\'s material variance was already cleared by a monthly Variance Closing — reverse that closing first, then set the order back to draft.');
        }

        $checkDate = $job->actual_completion_date?->toDateString() ?? $job->actual_start_date?->toDateString() ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($job->company_id, $checkDate)) {
            throw new \DomainException('This Job Order\'s accounting period is closed — it cannot be reopened.');
        }

        $job->loadMissing('costEntries', 'stockMovements');

        return DB::transaction(function () use ($job, $actor) {
            $before = $job->toArray();

            // 1. Finished Goods receipt (only if completed).
            if ($job->status === JobOrder::STATUS_COMPLETED) {
                $receipt = $job->stockMovements
                    ->firstWhere('movement_type', StockMovement::TYPE_JOB_RECEIPT);

                if ($receipt) {
                    $this->stockLedger->reverseReceipt(
                        $job->company_id,
                        $receipt->product,
                        $receipt->inventory_location_id,
                        (string) $receipt->quantity,
                        (string) $receipt->wac_total_value,
                        $actor,
                        [
                            'reference_type'       => JobOrder::class,
                            'reference_id'         => $job->id,
                            'job_order_id'         => $job->id,
                            'movement_date'        => $receipt->movement_date->toDateString(),
                            'original_movement_id' => $receipt->id,
                        ]
                    );

                    // Mirrors the pooled-balance skip above — this
                    // receipt never touched ProductStockBalance, so
                    // its OWN segment (JobOrderStockBalance) is what
                    // needs reversing here instead.
                    $this->jobOrderStockBalances->reverse(
                        $job,
                        $receipt->product,
                        (string) $receipt->quantity,
                        (string) $receipt->wac_total_value
                    );
                }

                if ($job->journal_entry_id) {
                    $entry = JournalEntry::findOrFail($job->journal_entry_id);
                    $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$job->job_code);
                }
            }

            // 2. Every labor/overhead cost entry.
            foreach ($job->costEntries as $costEntry) {
                if ($costEntry->journal_entry_id) {
                    $entry = JournalEntry::findOrFail($costEntry->journal_entry_id);
                    $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$job->job_code);
                }
                $costEntry->delete();
            }

            // 3. Every material consumption.
            $consumptions = $job->stockMovements
                ->where('movement_type', StockMovement::TYPE_JOB_CONSUMPTION);

            foreach ($consumptions as $movement) {
                $this->stockLedger->receive(
                    $job->company_id,
                    $movement->product,
                    $movement->inventory_location_id,
                    (string) $movement->quantity,
                    (string) $movement->wac_unit_cost,
                    $actor,
                    [
                        'movement_type'  => StockMovement::TYPE_ISSUE_REVERSAL,
                        'reference_type' => JobOrder::class,
                        'reference_id'   => $job->id,
                        'movement_date'  => $movement->movement_date->toDateString(),
                        'notes'          => 'Reversal of an earlier consumption — Job Order reopened to draft.',
                    ]
                );

                $consumptionEntry = JournalEntry::where('company_id', $job->company_id)
                    ->where('source_type', 'job_order_consumption')
                    ->where('source_id', $movement->id)
                    ->first();

                if ($consumptionEntry) {
                    $this->journalEntryService->reverse($consumptionEntry, $actor, 'Reopened to draft — '.$job->job_code);
                }
            }

            $job->update([
                'status'                 => JobOrder::STATUS_DRAFT,
                'actual_start_date'      => null,
                'actual_completion_date' => null,
                'journal_entry_id'       => null,
            ]);

            AuditLog::record($actor, 'job_order.set_to_draft', $job, $before, $job->fresh()->toArray());

            return $job->fresh(['product', 'template', 'salesOrder', 'rawMaterialLocation', 'outputLocation']);
        });
    }

    // ── Variance — estimated vs actual, reporting only ───────────

    public function variance(JobOrder $job): array
    {
        $qty = $job->quantity_to_produce;

        $estimatedMaterial = bcmul($job->estimated_material_cost_per_unit ?? '0', $qty, 2);
        $estimatedLabor    = bcmul($job->estimated_labor_cost_per_unit ?? '0', $qty, 2);
        $estimatedOverhead = bcmul($job->estimated_overhead_cost_per_unit ?? '0', $qty, 2);

        $actualMaterial = (string) $job->stockMovements()
            ->where('movement_type', StockMovement::TYPE_JOB_CONSUMPTION)
            ->sum('wac_total_value');
        $actualLabor    = (string) $job->costEntries()->where('entry_type', JobOrderCostEntry::TYPE_LABOR)->sum('actual_amount');
        $actualOverhead = (string) $job->costEntries()->where('entry_type', JobOrderCostEntry::TYPE_OVERHEAD)->sum('actual_amount');

        return [
            'material' => ['estimated' => $estimatedMaterial, 'actual' => $actualMaterial, 'variance' => bcsub($actualMaterial, $estimatedMaterial, 2)],
            'labor'    => ['estimated' => $estimatedLabor, 'actual' => $actualLabor, 'variance' => bcsub($actualLabor, $estimatedLabor, 2)],
            'overhead' => ['estimated' => $estimatedOverhead, 'actual' => $actualOverhead, 'variance' => bcsub($actualOverhead, $estimatedOverhead, 2)],
        ];
    }

    // ── Estimated material cost — auto-calculated from lines × current WAC ──

    // MFG-004 fix (confirmed with Mahmoud, 2026-09) — split out so the
    // exact total (before it's ever divided) can be captured and
    // stored separately from the per-unit rate. complete() uses the
    // stored total directly instead of multiplying the per-unit rate
    // back out, which is what was losing a cent to rounding.
    private function materialCostTotal(int $companyId, array $lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $balance = ProductStockBalance::where('company_id', $companyId)
                ->where('product_id', $line['component_product_id'])
                ->first();
            $wac = $balance?->wac_unit_cost ?? '0.0000';
            $total = bcadd($total, bcmul($line['quantity'], $wac, 2), 2);
        }

        return $total;
    }

    private function estimatedMaterialCostPerUnit(int $companyId, array $lines, string $quantityToProduce): string
    {
        $total = $this->materialCostTotal($companyId, $lines);

        return bccomp($quantityToProduce, '0', 4) > 0 ? bcdiv($total, $quantityToProduce, 4) : '0.0000';
    }

    private function syncLines(JobOrder $job, int $companyId, array $lines): void
    {
        foreach ($lines as $i => $line) {
            JobOrderLine::create([
                'company_id'            => $companyId,
                'job_order_id'          => $job->id,
                'component_product_id'  => $line['component_product_id'],
                'quantity'              => $line['quantity'],
                'uom_id'                => $line['uom_id'] ?? null,
                'sort_order'            => $i,
            ]);
        }
    }

    // ── Internal helpers — deliberately duplicated from ProductionOrderService, not shared ──

    private function assertInProgress(JobOrder $job): void
    {
        if ($job->status !== JobOrder::STATUS_IN_PROGRESS) {
            throw new \DomainException('This action requires the Job Order to be in progress.');
        }
    }

    // Section 6 — a Job Order with any Pieces cannot complete (and
    // therefore cannot receive Finished Goods) until every one of
    // them is Completed. A job with zero Pieces (the simple, flat
    // case) is completely unaffected.
    private function assertAllPiecesCompleted(JobOrder $job): void
    {
        $incomplete = JobOrderPiece::where('job_order_id', $job->id)
            ->where('status', '!=', JobOrderPiece::STATUS_COMPLETED)
            ->exists();

        if ($incomplete) {
            throw new \DomainException('Every piece must be marked Completed before this Job Order can be completed.');
        }
    }

    // Shared by both branches of logPieceOperationCost() — same
    // JobOrderCostEntry + journal entry shape as recordLaborCost()/
    // recordOverheadCost() above, just with job_order_operation_id
    // set (so this entry is recognizable as system-computed) and no
    // manually-supplied actual_amount — it's always base × rate here.
    private function postJobOperationCost(
        JobOrder $job,
        JobOrderOperation $operation,
        string $entryType,
        ?int $overheadPoolId,
        string $baseQuantity,
        string $rate,
        User $actor,
        string $entryDate,
        ?string $notes,
        ?int $costCenterId,
        ChartOfAccount $creditAccount,
        string $narrationSuffix
    ): JobOrderCostEntry {
        $actualCost = bcmul($baseQuantity, $rate, 2);

        $entry = JobOrderCostEntry::create([
            'company_id'             => $job->company_id,
            'job_order_id'           => $job->id,
            'job_order_operation_id' => $operation->id,
            'entry_type'             => $entryType,
            'overhead_pool_id'       => $overheadPoolId,
            'base_quantity'          => $baseQuantity,
            'rate_applied'           => $rate,
            'actual_amount'          => $actualCost,
            'cost_center_id'         => $costCenterId,
            'entry_date'             => $entryDate,
            'notes'                  => $notes,
            'created_by'             => $actor->id,
        ]);

        $wipAccount = $this->resolveSystemAccount($job->company_id, '135002', 'Manufacturing WIP');

        $journalEntry = $this->journalEntryService->post(
            companyId: $job->company_id,
            entryDate: $entryDate,
            lines: [
                ['chart_of_account_id' => $wipAccount->id, 'debit' => $actualCost, 'credit' => '0', 'cost_center_id' => $costCenterId, 'description' => 'Job Order '.$job->job_code.' — '.$narrationSuffix],
                ['chart_of_account_id' => $creditAccount->id, 'debit' => '0', 'credit' => $actualCost, 'cost_center_id' => $costCenterId, 'description' => 'Job Order '.$job->job_code.' — '.$narrationSuffix],
            ],
            sourceType: 'job_order_cost_entry',
            sourceId: $entry->id,
            narration: "Job Order {$job->job_code} — {$narrationSuffix}",
            actor: $actor,
        );

        $entry->update(['journal_entry_id' => $journalEntry->id]);

        AuditLog::record($actor, 'job_order.piece_cost_logged', $job, null, $entry->fresh()->toArray());

        return $entry->fresh();
    }

    // 2026-09 GL Posting fix — same as ProductionOrderService::resolveSystemAccount().
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
            throw new \DomainException("No fiscal year covers {$date} — create one before opening a Job Order.");
        }

        return $fy;
    }

    private function yearMonth(string $date): array
    {
        $carbon = \Carbon\Carbon::parse($date);
        return [(int) $carbon->format('Y'), (int) $carbon->format('n')];
    }

    private function generateJobCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\JobOrder::class, 'job_code', 'JOB', $date);
    }
}
