<?php

namespace App\Services\Inventory;

// ══════════════════════════════════════════════════════════════════
//  StockLedgerService
//
//  The ONLY place in the codebase allowed to write to
//  stock_movements, product_stock_balances, and product_location_stock.
//  Every future caller — Purchase GRN, Sales Delivery Note, Mass
//  Production, Job Order, manual stock adjustment — goes through
//  this service. Nothing writes to those three tables directly.
//
//  WAC RULE (confirmed): one WAC per product PER COMPANY. Only
//  RECEIPTS (and adjustment_in / production_receipt / job_receipt,
//  which are treated as receipts) ever move the WAC. Issues relieve
//  stock at the WAC that already exists at that moment — they never
//  change it. Formula (Application Scope §5.3):
//      New WAC = (current stock value + new receipt value)
//                ÷ (current stock qty + new receipt qty)
//
//  DUAL-VALUATION RULE (confirmed — non-negotiable, same weight as
//  Building Bible Rule 31): every movement's wac_unit_cost /
//  wac_total_value is ALWAYS populated and is the only figure that
//  ever posts to the GL. actual_unit_cost / actual_total_value are
//  populated whenever a project_id OR a sales_order_id is supplied
//  (2026-09 — broadened from "project only"; migration 000220 closed
//  the gap where a standalone Sales Order, no parent Project, got
//  zero actual-cost tracking) — sourced by the CALLER from that
//  item's specific purchase line — and feed Project P&L / Project
//  Inventory Statement only. This service enforces that a project-
//  or sales-order-assigned movement can never be saved without its
//  actual cost (assertActualCostConsistency) — it does not, and
//  cannot, derive that figure itself.
//
//  A transfer between locations can now also carry a project_id /
//  sales_order_id tag forward onto both rows it writes (2026-09) —
//  a deliberate, user-declared statement of "this moving stock
//  belongs to Project/SO X," never inferred, since WAC pooling means
//  there is no batch-level way to know which physical units came
//  from which receipt. See transfer()'s own header for why.
//
//  JOB ORDER SEGMENT EXCEPTION (2026-09, Manufacturing Variance
//  Closing Design v1.0, Section 6): unlike project_id/sales_order_id
//  above — which are side reporting tags while WAC still governs the
//  GL — a job_order_id on receive()/reverseReceipt() means something
//  stronger: the company-wide pooled balance (ProductStockBalance) —
//  QUANTITY as well as cost — is skipped entirely for that call.
//  JobOrderStockBalanceService, not this class, owns that job's own
//  segment balance (both quantity and cost).
//
//  This is intentional, confirmed with Mahmoud, and matches how a
//  real order works: a Job Order builds a customer-specific product
//  (e.g. a racking system built to Customer A's own spec) — it is
//  never averaged into the Product's general cost or count, by
//  design (confirmed precedent: SAP's "Individual Valuation" / Oracle
//  Project Manufacturing), because its output only ever belongs to
//  the one sale it was built for. DeliveryNoteService already draws
//  from a job's own segment automatically when the same Sales Order
//  is being fulfilled (no manual picking) — that is the only route by
//  which this stock is ever meant to leave. Location-level tracking
//  (which warehouse physically holds it) is still updated normally
//  either way — it's only the company-wide POOLED figures, quantity
//  included, that a Job Order's output never joins.
//
//  MFG-001 (corrected 2026-09): this header previously said "only the
//  pooled COST balance is skipped," implying quantity stayed in the
//  pool. It never did — quantity_on_hand sits inside the very same
//  skipped block, and always has. That was actually correct behavior
//  all along; the comment was simply wrong about what the code does.
//
//  LOCATION RULE (confirmed): product_location_stock is QUANTITY
//  ONLY — no cost. A location-level insufficient-stock check is
//  still enforced here (you cannot issue/transfer out of a specific
//  warehouse more than what is physically there), even though the
//  cost side is company-wide.
//
//  Every balance row is locked with lockForUpdate() inside a DB
//  transaction before it is read or written, to keep concurrent
//  postings from corrupting the running WAC.
//
//  CROSS-COMPANY GUARD (added July 2026 audit — Building Bible
//  Rule 2.5): receive(), issue(), transfer(), and adjust() all take
//  a raw inventory_location_id (and, when project-assigned, a raw
//  project_id) rather than a resolved, already-tenant-checked model.
//  The calling controllers scope their dropdown queries to the
//  current company, but nothing previously stopped a tampered
//  request from passing a location or project ID belonging to a
//  different company straight through to a real ledger write. Every
//  public method now calls assertLocationBelongsToCompany() (and
//  assertProjectBelongsToCompany() when a project_id is supplied)
//  before touching the ledger — independent of, and in addition to,
//  the same check now also enforced in StockTransferRequest /
//  StockAdjustmentRequest. A future caller (Goods Receipt Note, Job
//  Order, etc.) that calls this service directly is protected even
//  if its own Form Request forgets to scope the ID.
//
//  @layer    Service
//  @depends  StockMovement, ProductStockBalance, ProductLocationStock,
//            Product, InventoryLocation, Project, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLocationStock;
use App\Models\ProductStockBalance;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    // ── Read: Inventory Statement (per product, date range) ────────
    //
    // New report (July 2026 — Point 7 from the cross-audit
    // discussion). Nothing new is tracked to build this — every
    // number below is read straight from stock_movements, which has
    // recorded quantity AND value on every receipt/issue/transfer/
    // adjustment/production movement since the Inventory Engine was
    // built. This is purely a summarization of data already there.
    //
    // Quantity side:  Beginning Balance → In → Out → Ending Balance
    // Value side:     Beginning Balance → Debit → Credit → Ending
    //                 Balance → WAC (Ending Value ÷ Ending Qty)
    //
    // All arithmetic in bcmath per Rule 6 — never float on money,
    // and quantity here directly drives money (WAC), so it's held to
    // the same standard.

    /**
     * The Inventory Statement ledger — ONE row per transaction (not
     * per date, not a separate summary + movements split), running
     * balance carried from each row's Ending Balance into the next
     * row's Beginning Balance. Confirmed with Mahmoud, July 2026:
     * "if 5 receipts happen on the same day, 5 separate rows" — this
     * replaced an earlier version that aggregated to one summary row
     * per period plus a separate movements list.
     *
     * $locationId: null = combined across every location (transfers
     * between two of the company's own locations net to zero at this
     * level, which is correct — moving stock between your own
     * warehouses doesn't change how much the company owns). A real
     * id = that one location's own movements only, including its
     * transfer_in/transfer_out rows — this is how a user traces
     * "how much of this moved between Warehouse A and Warehouse B."
     * Project Site locations are deliberately excluded from this
     * report's location list — project-tagged inventory has its own
     * Project Inventory report (Projects & Contracts module, tracked
     * at actual purchase cost, not WAC) — a different question this
     * report was never meant to answer.
     *
     * THE RUNNING BALANCE IS COMPUTED BY THE DATABASE, NOT PHP: a SQL
     * window function (`SUM(...) OVER (ORDER BY ...)`) computes each
     * row's cumulative total across every row that matches the
     * filters — not just the rows on the current page — so paginating
     * to page 40 of a busy product is exactly as fast as page 1; nothing
     * is loaded into the app just to add it up.
     */
    public function ledger(
        int    $companyId,
        int    $productId,
        string $dateFrom,
        string $dateTo,
        ?int   $locationId,
        int    $page = 1
    ): array {
        $perPage = 20;

        $opening = $this->openingBalance($companyId, $productId, $dateFrom, $locationId);

        $inboundList  = "'" . implode("','", StockMovement::INBOUND_TYPES)  . "'";
        $outboundList = "'" . implode("','", StockMovement::OUTBOUND_TYPES) . "'";

        $base = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('inventory_location_id', $locationId));

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->selectRaw("
                id, movement_date, movement_type, quantity, wac_total_value,
                reference_type, reference_id,
                SUM(CASE WHEN movement_type IN ({$inboundList}) THEN quantity ELSE -quantity END)
                    OVER (ORDER BY movement_date, id) as running_qty,
                SUM(CASE WHEN movement_type IN ({$inboundList}) THEN wac_total_value ELSE -wac_total_value END)
                    OVER (ORDER BY movement_date, id) as running_value
            ")
            ->orderBy('movement_date')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        $refs = $this->resolveReferences($companyId, $rows);

        $data = $rows->map(function ($row) use ($opening, $inboundList, $refs) {
            $isInbound   = in_array($row->movement_type, StockMovement::INBOUND_TYPES, true);
            $quantity    = (string) $row->quantity;
            $value       = (string) $row->wac_total_value;
            $signedQty   = $isInbound ? $quantity : bcmul($quantity, '-1', 4);
            $signedValue = $isInbound ? $value    : bcmul($value, '-1', 2);

            $endingQty   = bcadd($opening['qty'],   (string) $row->running_qty,   4);
            $endingValue = bcadd($opening['value'], (string) $row->running_value, 2);
            $beginningQty   = bcsub($endingQty,   $signedQty,   4);
            $beginningValue = bcsub($endingValue, $signedValue, 2);

            $key = $row->reference_type . ':' . $row->reference_id;

            return [
                'id'                  => $row->id,
                'movement_date'       => $row->movement_date,
                'movement_type'       => $row->movement_type,
                'transaction_reference' => $refs['transactions'][$key] ?? null,
                'journal_reference'     => $refs['journals'][$key] ?? null,
                'quantity' => [
                    'beginning_balance' => $beginningQty,
                    'in'                => $isInbound ? $quantity : '0.0000',
                    'out'               => $isInbound ? '0.0000' : $quantity,
                    'ending_balance'    => $endingQty,
                ],
                'value' => [
                    'beginning_balance' => $beginningValue,
                    'debit'             => $isInbound ? $value : '0.00',
                    'credit'            => $isInbound ? '0.00' : $value,
                    'ending_balance'    => $endingValue,
                    // WAC as it stood immediately after THIS
                    // transaction — not a period average. Needs the
                    // running quantity balance too, even though
                    // quantity isn't its own column on the Value
                    // statement — that's why $endingQty is computed
                    // unconditionally above, for both statement types.
                    'wac'               => bccomp($endingQty, '0', 4) > 0
                        ? bcdiv($endingValue, $endingQty, 4)
                        : '0.0000',
                ],
            ];
        });

        return [
            'data'         => $data,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage) ?: 1,
            'total'        => $total,
        ];
    }

    /** Net quantity + value of every movement strictly before $date, optionally scoped to one location. */
    private function openingBalance(int $companyId, int $productId, string $date, ?int $locationId): array
    {
        $base = StockMovement::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('movement_date', '<', $date)
            ->when($locationId, fn ($q) => $q->where('inventory_location_id', $locationId));

        $inQty  = (string) (clone $base)->whereIn('movement_type', StockMovement::INBOUND_TYPES)->sum('quantity');
        $outQty = (string) (clone $base)->whereIn('movement_type', StockMovement::OUTBOUND_TYPES)->sum('quantity');

        $inValue  = (string) (clone $base)->whereIn('movement_type', StockMovement::INBOUND_TYPES)->sum('wac_total_value');
        $outValue = (string) (clone $base)->whereIn('movement_type', StockMovement::OUTBOUND_TYPES)->sum('wac_total_value');

        return [
            'qty'   => bcsub($inQty, $outQty, 4),
            'value' => bcsub($inValue, $outValue, 2),
        ];
    }

    /**
     * Resolves a small page of rows' reference_type/reference_id into
     * human-readable codes — Transaction Reference (the source
     * document's own code) and Journal Reference (the journal entry
     * it posted, if any). Only ever looks up the ~20 rows on the
     * current page, never the whole table.
     *
     * Scope today: fully resolves Goods Receipt Note rows (currently
     * the only stock-affecting document posting through the Journal
     * Entry Engine). Stock Transfers and Adjustments correctly get no
     * Journal Reference — they don't post to the GL, by design (moving
     * or adjusting stock doesn't change money). Other reference types
     * (e.g. Production/Job Order) degrade gracefully: shown without a
     * resolved code rather than erroring, and will resolve cleanly on
     * their own once those flows post through the same Journal Entry
     * Engine with matching source_type/source_id.
     */
    private function resolveReferences(int $companyId, \Illuminate\Support\Collection $rows): array
    {
        $transactions = [];
        $journals     = [];

        $grnIds = $rows->where('reference_type', 'goods_receipt_note')->pluck('reference_id')->unique();

        if ($grnIds->isNotEmpty()) {
            $grns = \App\Models\GoodsReceiptNote::withoutGlobalScopes()
                ->whereIn('id', $grnIds)
                ->pluck('grn_code', 'id');

            foreach ($grns as $id => $code) {
                $transactions["goods_receipt_note:{$id}"] = $code;
            }

            $entries = \App\Models\JournalEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('source_type', 'goods_receipt_note')
                ->whereIn('source_id', $grnIds)
                ->pluck('entry_number', 'source_id');

            foreach ($entries as $sourceId => $entryNumber) {
                $journals["goods_receipt_note:{$sourceId}"] = $entryNumber;
            }
        }

        // Fix (found while investigating Mahmoud's report, July 2026):
        // this method was written only for GRN — Delivery Note posts
        // reference_type = 'delivery_note_line' (per LINE, not per
        // document, since one delivery can span several stock
        // movements), which was never added here. Every delivery-
        // driven movement has silently resolved to no reference at
        // all since Delivery Note shipped — not a timing issue, a
        // genuine gap in this resolver.
        $deliveryLineIds = $rows->where('reference_type', 'delivery_note_line')->pluck('reference_id')->unique();

        if ($deliveryLineIds->isNotEmpty()) {
            $deliveryLines = \App\Models\DeliveryNoteLine::withoutGlobalScopes()
                ->whereIn('id', $deliveryLineIds)
                ->with('deliveryNote:id,delivery_code,inventory_journal_entry_id')
                ->get(['id', 'delivery_note_id']);

            foreach ($deliveryLines as $line) {
                if (! $line->deliveryNote) {
                    continue;
                }
                $transactions["delivery_note_line:{$line->id}"] = $line->deliveryNote->delivery_code;
            }

            $journalEntryIds = $deliveryLines->pluck('deliveryNote.inventory_journal_entry_id')->filter()->unique();

            if ($journalEntryIds->isNotEmpty()) {
                $entryNumbers = \App\Models\JournalEntry::withoutGlobalScopes()
                    ->whereIn('id', $journalEntryIds)
                    ->pluck('entry_number', 'id');

                foreach ($deliveryLines as $line) {
                    $entryId = $line->deliveryNote?->inventory_journal_entry_id;
                    if ($entryId && $entryNumbers->has($entryId)) {
                        $journals["delivery_note_line:{$line->id}"] = $entryNumbers[$entryId];
                    }
                }
            }
        }

        return ['transactions' => $transactions, 'journals' => $journals];
    }

    // ── Read: Category Value Statement (new report) ─────────────────
    //
    // Value-only, by design — never quantity. A category can contain
    // products counted in different units (Units, KG, Cartons...); a
    // quantity total across mixed units would be meaningless. Money
    // always sums cleanly regardless of each product's UOM, so this
    // report is Value only, structured as parent (Main Category) rows
    // with child (Sub-Category) rows nested underneath for analysis —
    // confirmed with Mahmoud, July 2026. This is purely a reporting
    // view: Sub-Categories have no Chart of Accounts entry of their
    // own (see ProductService::resolveCategoryInventoryAccount()) —
    // every product's real GL posting already rolled up to its Main
    // Category's single account. This report just re-derives the
    // Sub-Category breakdown from the same stock_movements ledger,
    // for analysis, without needing a second GL account to exist.
    public function categoryValueStatement(int $companyId, string $dateFrom, string $dateTo): array
    {
        $categories = ProductCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id', 'name_en', 'name_ar']);

        [$beginIn, $beginOut] = $this->categoryMovementSums($companyId, null, $dateFrom);
        [$rangeIn, $rangeOut] = $this->categoryMovementSums($companyId, [$dateFrom, $dateTo], null);

        // Each category's OWN totals — only the products directly
        // assigned to that exact category record (main or sub).
        $own = [];
        foreach ($categories as $cat) {
            $beginning = bcsub((string) ($beginIn[$cat->id] ?? 0), (string) ($beginOut[$cat->id] ?? 0), 2);
            $debit     = (string) ($rangeIn[$cat->id]  ?? 0);
            $credit    = (string) ($rangeOut[$cat->id] ?? 0);
            $ending    = bcsub(bcadd($beginning, $debit, 2), $credit, 2);

            $own[$cat->id] = compact('beginning', 'debit', 'credit', 'ending');
        }

        // Assemble the tree: each Main Category's displayed row rolls
        // up its own totals PLUS every Sub-Category's totals; each
        // Sub-Category is listed underneath showing only its own.
        $result = [];
        foreach ($categories->whereNull('parent_id') as $main) {
            $rollup   = $own[$main->id];
            $children = [];

            foreach ($categories->where('parent_id', $main->id) as $sub) {
                $co = $own[$sub->id];
                $children[] = ['id' => $sub->id, 'name' => $sub->name_en] + $co;

                $rollup['beginning'] = bcadd($rollup['beginning'], $co['beginning'], 2);
                $rollup['debit']     = bcadd($rollup['debit'],     $co['debit'],     2);
                $rollup['credit']    = bcadd($rollup['credit'],    $co['credit'],    2);
                $rollup['ending']    = bcadd($rollup['ending'],    $co['ending'],    2);
            }

            $result[] = ['id' => $main->id, 'name' => $main->name_en] + $rollup + ['children' => $children];
        }

        // Audit M-16 — value that belongs to no listed category (a
        // product with no category, or a sub-category whose main category
        // is missing) used to fall out of the statement altogether, so
        // the total no longer matched the stock balance. It now gets its
        // own row.
        $listed = [];
        foreach ($categories->whereNull('parent_id') as $main) {
            $listed[] = $main->id;
            foreach ($categories->where('parent_id', $main->id) as $sub) {
                $listed[] = $sub->id;
            }
        }
        $other = ['beginning' => '0.00', 'debit' => '0.00', 'credit' => '0.00', 'ending' => '0.00'];
        $catIds = array_unique(array_merge(
            $beginIn->keys()->all(), $beginOut->keys()->all(), $rangeIn->keys()->all(), $rangeOut->keys()->all()
        ));
        foreach ($catIds as $catId) {
            if ($catId !== '' && $catId !== null && in_array((int) $catId, $listed, true)) {
                continue;
            }
            $b = bcsub((string) ($beginIn[$catId] ?? 0), (string) ($beginOut[$catId] ?? 0), 2);
            $d = (string) ($rangeIn[$catId] ?? 0);
            $c = (string) ($rangeOut[$catId] ?? 0);
            $other['beginning'] = bcadd($other['beginning'], $b, 2);
            $other['debit']     = bcadd($other['debit'], $d, 2);
            $other['credit']    = bcadd($other['credit'], $c, 2);
            $other['ending']    = bcadd($other['ending'], bcsub(bcadd($b, $d, 2), $c, 2), 2);
        }
        if ($catIds && (bccomp($other['beginning'], '0', 2) != 0 || bccomp($other['debit'], '0', 2) != 0 || bccomp($other['credit'], '0', 2) != 0)) {
            $result[] = ['id' => 0, 'name' => 'Uncategorised'] + $other + ['children' => []];
        }

        return $result;
    }

    /**
     * Net value moved per product-category, grouped in one query (not
     * one query per category — matters once a company has many
     * categories). Returns [inbound-by-category, outbound-by-category]
     * as two id-keyed maps; either $range or $before is given, never
     * both — $before computes the balance struck immediately before a
     * date, $range sums movements strictly inside a date range.
     */
    private function categoryMovementSums(int $companyId, ?array $range, ?string $before): array
    {
        $sumsFor = function (array $types) use ($companyId, $range, $before) {
            return DB::table('stock_movements')
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->where('stock_movements.company_id', $companyId)
                ->whereIn('stock_movements.movement_type', $types)
                ->when($range, fn ($q) => $q->whereBetween('stock_movements.movement_date', $range))
                ->when($before, fn ($q) => $q->where('stock_movements.movement_date', '<', $before))
                ->groupBy('products.category_id')
                ->selectRaw('products.category_id as cat_id, SUM(stock_movements.wac_total_value) as total')
                ->pluck('total', 'cat_id');
        };

        return [$sumsFor(StockMovement::INBOUND_TYPES), $sumsFor(StockMovement::OUTBOUND_TYPES)];
    }

    // ── Read: current balances (Stock Balance page) ──────────────────

    public function paginateBalances(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return ProductStockBalance::with('product:id,code,name_en,name_ar,has_stock')
            ->where('company_id', $companyId)
            ->whereHas('product', fn ($q) => $q
                ->when($filters['search'] ?? null, fn ($q, $s) => $q
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$s}%")
                        ->orWhere('name_en', 'like', "%{$s}%")
                        ->orWhere('name_ar', 'like', "%{$s}%")
                    )
                )
            )
            ->orderBy('product_id')
            ->paginate(20)
            ->withQueryString();
    }

    // Per-location quantity breakdown for one product — used to expand
    // a row on the Stock Balance page ("which warehouse has how much").

    public function locationBreakdown(int $companyId, int $productId)
    {
        return ProductLocationStock::with('inventoryLocation:id,code,name_en,name_ar')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('quantity_on_hand', '>', 0)
            ->get();
    }

    // ── Read: movement history (Transfers / Adjustments index pages) ──
    //
    // $movementTypes narrows the ledger to just the types a given page
    // cares about — e.g. StockTransferController only wants
    // [transfer_out, transfer_in], StockAdjustmentController only wants
    // [adjustment_in, adjustment_out]. Each row is shown as its own
    // ledger entry (see StockTransferController header note on why the
    // two legs of one transfer aren't paired together yet).

    public function paginateMovements(int $companyId, array $movementTypes, array $filters = []): LengthAwarePaginator
    {
        return StockMovement::with(['product:id,code,name_en,name_ar', 'inventoryLocation:id,code,name_en,name_ar', 'createdBy:id,name'])
            ->where('company_id', $companyId)
            ->whereIn('movement_type', $movementTypes)
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))
            ->when($filters['inventory_location_id'] ?? null, fn ($q, $l) => $q->where('inventory_location_id', $l))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Receive (and anything that behaves like a receipt) ──────────
    //
    // $options may include: movement_type, actual_unit_cost, project_id,
    // reference_type, reference_id, movement_date, notes, total_value.
    //
    // total_value (Audit C7, Sept 2026) — optional exact receipt value.
    // Used by Opening Balances, where the user states a TOTAL value for
    // a quantity: quantity × a rounded unit cost does not always give
    // that total back to the cent, and the stock ledger must equal the
    // opening journal entry exactly. When omitted, behaviour is
    // unchanged (value = quantity × unit cost, rounded).

    public function receive(
        int $companyId,
        Product $product,
        int $inventoryLocationId,
        string $quantity,
        string $unitCost,
        User $actor,
        array $options = []
    ): StockMovement {
        $this->assertPositiveQuantity($quantity);
        $this->assertActualCostConsistency(
            $options['project_id'] ?? null,
            $options['sales_order_id'] ?? null,
            $options['actual_unit_cost'] ?? null
        );
        $this->assertLocationBelongsToCompany($companyId, $inventoryLocationId);
        $this->assertProjectBelongsToCompany($companyId, $options['project_id'] ?? null);
        $this->assertSalesOrderBelongsToCompany($companyId, $options['sales_order_id'] ?? null);
        $this->assertJobOrderBelongsToCompany($companyId, $options['job_order_id'] ?? null);

        return DB::transaction(function () use ($companyId, $product, $inventoryLocationId, $quantity, $unitCost, $actor, $options) {

            $wacDelta = null;

            // Manufacturing Variance Closing Design v1.0, Section 6 —
            // when this receipt belongs to a Job Order's own segment,
            // the company-wide pooled balance (ProductStockBalance) —
            // quantity AND cost together, the whole block below — is
            // deliberately left untouched, since that job's units and
            // cost only ever belong to the one sale they were built
            // for (MFG-001, corrected 2026-09 — this comment used to
            // claim only cost was skipped; quantity always was too,
            // correctly). Location tracking (adjustLocationQuantity
            // below, outside this block) still happens exactly as
            // normal either way — the physical units genuinely are in
            // the warehouse, just not counted in the general pool.
            // JobOrderStockBalanceService (called by JobOrderService,
            // not from here) updates that job's own segment instead.
            if (empty($options['job_order_id'])) {
                $balance = $this->lockCompanyBalance($companyId, $product->id);

                $receiptValue = isset($options['total_value'])
                    ? $this->bcround((string) $options['total_value'], 2)
                    : $this->bcround(bcmul($quantity, $unitCost, 6), 2);

                $receiptDate = substr((string) ($options['movement_date'] ?? now()->toDateString()), 0, 10);
                $revalue = empty($options['skip_date_sequence']) && in_array($options['movement_type'] ?? StockMovement::TYPE_RECEIPT, [
                    StockMovement::TYPE_RECEIPT,
                    StockMovement::TYPE_PRODUCTION_RECEIPT,
                    StockMovement::TYPE_KIT_RECEIPT,
                    StockMovement::TYPE_ADJUSTMENT_IN,
                ], true);

                if ($revalue && $this->hasLaterWacIssue($companyId, $product->id, $receiptDate)) {
                    $wacDelta = $this->revaluePoolForBackdatedReceipt(
                        $balance, $companyId, $product, $quantity, $receiptValue, $receiptDate
                    );
                } else {
                    $wacDelta = null;
                    $newQty   = bcadd($balance->quantity_on_hand, $quantity, 4);
                    $newValue = bcadd($balance->wac_total_value, $receiptValue, 2);
                    $newWac   = bccomp($newQty, '0', 4) > 0 ? bcdiv($newValue, $newQty, 4) : '0.0000';

                    $balance->update([
                        'quantity_on_hand' => $newQty,
                        'wac_unit_cost'    => $newWac,
                        'wac_total_value'  => $newValue,
                    ]);
                }
            }

            $this->adjustLocationQuantity($companyId, $product->id, $inventoryLocationId, $quantity);

            $actualUnitCost = $options['actual_unit_cost'] ?? null;
            $receiptValue   = isset($options['total_value'])
                ? $this->bcround((string) $options['total_value'], 2)
                : $this->bcround(bcmul($quantity, $unitCost, 6), 2);

            $movement = StockMovement::create([
                'company_id'            => $companyId,
                'product_id'            => $product->id,
                'inventory_location_id' => $inventoryLocationId,
                'movement_type'         => $options['movement_type'] ?? StockMovement::TYPE_RECEIPT,
                'quantity'              => $quantity,
                'wac_unit_cost'         => $unitCost,
                'wac_total_value'       => $receiptValue,
                'actual_unit_cost'      => $actualUnitCost,
                'actual_total_value'    => $actualUnitCost !== null ? bcmul($quantity, $actualUnitCost, 2) : null,
                'project_id'            => $options['project_id'] ?? null,
                'sales_order_id'        => $options['sales_order_id'] ?? null,
                'job_order_id'          => $options['job_order_id'] ?? null,
                'reference_type'        => $options['reference_type'] ?? null,
                'reference_id'          => $options['reference_id'] ?? null,
                'movement_date'         => $options['movement_date'] ?? now()->toDateString(),
                'created_by'            => $actor->id,
                'notes'                 => $options['notes'] ?? null,
            ]);

            AuditLog::record($actor, 'stock.received', $movement, null, $movement->toArray());

            if ($wacDelta !== null && bccomp($wacDelta, '0', 2) !== 0) {
                $this->postWacRevaluationJournal(
                    $companyId,
                    $product,
                    $wacDelta,
                    substr((string) $movement->movement_date, 0, 10),
                    $movement->id,
                    $actor,
                );
            }

            return $movement;
        });
    }

    // ── Reverse a receipt (undo a posted GRN being set back to draft) ──
    //
    // This is the mirror image of receive(): subtracts the original
    // receipt's quantity and value back out of the running company
    // balance and the specific location balance, and recalculates
    // wac_unit_cost from what remains — exactly like receive() does,
    // just in reverse.
    //
    // INV-006 fix (confirmed with Mahmoud, 2026-09) — this used to be
    // described as "safe even if other receipts/issues happened
    // after," guarded only by checking that quantity doesn't go
    // negative. That turned out not to be true: once anything has
    // been issued from the pooled balance at a blended average, the
    // naive subtraction below can silently leave a wrong VALUE behind
    // — sometimes a leftover positive residue, sometimes a wrong
    // number even while quantity looks perfectly normal (confirmed by
    // direct calculation, not assumption — a case was found where
    // quantity net out correctly but value was off by 250.00 with no
    // guard catching it). There is no way to detect this reliably
    // after the fact by inspecting the resulting numbers.
    //
    // The only reliable rule: reversals must undo things in the exact
    // reverse order they happened (Mahmoud's own confirmed principle
    // for this system). $options['original_movement_id'] — the exact
    // stock_movements row this receipt originally created — lets this
    // method check that directly: has this product genuinely been
    // sold, transferred, or consumed — company-wide — since then? If
    // so, that must be undone first, in order, before this receipt
    // can be. (Deliberately excludes this same method's own output,
    // TYPE_RECEIPT_REVERSAL — undoing two mistaken receipts, in either
    // order, is always safe as long as neither was ever actually sold
    // or consumed; only genuine outward movement counts.) If
    // original_movement_id isn't supplied at all (e.g. a GRN line
    // posted before this fix existed), this cannot be verified — and
    // rather than guess, the reversal is refused. Location-quantity is
    // still guarded separately below (a cheap, always-available check
    // that catches the same problem at that level regardless).

    public function reverseReceipt(
        int $companyId,
        Product $product,
        int $inventoryLocationId,
        string $quantity,
        string $originalReceiptValue,
        User $actor,
        array $options = []
    ): StockMovement {
        $this->assertPositiveQuantity($quantity);
        $this->assertLocationBelongsToCompany($companyId, $inventoryLocationId);

        return DB::transaction(function () use ($companyId, $product, $inventoryLocationId, $quantity, $originalReceiptValue, $actor, $options) {

            $locationStock = $this->lockLocationStock($companyId, $product->id, $inventoryLocationId);
            if (bccomp($locationStock->quantity_on_hand, $quantity, 4) < 0) {
                throw new \DomainException(
                    "Cannot reopen this receipt — some of this stock has already moved out of " .
                    "{$product->name_en}'s location since it was received."
                );
            }

            // Manufacturing Variance Closing Design v1.0, Section 6 —
            // mirrors receive()'s own job_order_id skip exactly: a
            // Job-Order-segmented receipt never touched the pooled
            // balance, so reversing it must not touch it either.
            // JobOrderService::setToDraft() reverses that job's own
            // segment separately, via JobOrderStockBalanceService.
            if (empty($options['job_order_id'])) {

                // INV-006 fix — the reverse-chronological-order check.
                if (empty($options['original_movement_id'])) {
                    throw new \DomainException(
                        "Cannot reopen this receipt — {$product->name_en}'s original transaction record " .
                        "cannot be found, so this cannot be safely verified. Please post a stock " .
                        "adjustment instead."
                    );
                }

                $somethingMovedSince = StockMovement::where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->where('id', '>', $options['original_movement_id'])
                    // TYPE_RECEIPT_REVERSAL is deliberately excluded —
                    // it's this very method's own output, generated
                    // whenever an unrelated receipt is undone. Two
                    // mistaken receipts can safely be undone in either
                    // order as long as neither was ever actually sold
                    // or consumed; only genuine outward movement of the
                    // product counts as "something happened since."
                    // TYPE_VARIANCE_ABSORPTION_OUT is excluded for the
                    // same reason (RPT-003 fix, 8 Sep 2026) — it's a
                    // value-only revaluation with zero quantity change,
                    // never a real physical movement of this product.
                    ->whereIn('movement_type', array_diff(
                        StockMovement::OUTBOUND_TYPES,
                        [StockMovement::TYPE_RECEIPT_REVERSAL, StockMovement::TYPE_VARIANCE_ABSORPTION_OUT]
                    ))
                    ->exists();

                if ($somethingMovedSince) {
                    throw new \DomainException(
                        "Cannot reopen this receipt — some of this stock ({$product->name_en}) has " .
                        "already been used, sold, or transferred elsewhere since it was received. " .
                        "Please reverse those transactions first, starting with the most recent, " .
                        "then reopen this receipt."
                    );
                }

                $balance = $this->lockCompanyBalance($companyId, $product->id);
                if (bccomp($balance->quantity_on_hand, $quantity, 4) < 0) {
                    throw new \DomainException(
                        "Cannot reopen this receipt — some of this stock ({$product->name_en}) has " .
                        "already been used elsewhere since it was received."
                    );
                }

                // Safe now — nothing outbound has touched this product
                // since this exact receipt, so the naive subtraction
                // below is mathematically exact, not just "probably
                // fine." The zero-clamp is a pure defensive fallback
                // for bcmath's own sub-cent rounding (INV-005), never
                // the primary safety mechanism.
                $newQty   = bcsub($balance->quantity_on_hand, $quantity, 4);
                $newValue = bcsub($balance->wac_total_value, $originalReceiptValue, 2);
                if (bccomp($newValue, '0', 2) < 0) {
                    $newValue = '0.00';
                }

                $newWac = bccomp($newQty, '0', 4) > 0 ? bcdiv($newValue, $newQty, 4) : '0.0000';

                $balance->update([
                    'quantity_on_hand' => $newQty,
                    'wac_unit_cost'    => $newWac,
                    'wac_total_value'  => $newValue,
                ]);
            }

            $this->adjustLocationQuantity($companyId, $product->id, $inventoryLocationId, bcmul($quantity, '-1', 4));

            $movement = StockMovement::create([
                'company_id'            => $companyId,
                'product_id'            => $product->id,
                'inventory_location_id' => $inventoryLocationId,
                'movement_type'         => StockMovement::TYPE_RECEIPT_REVERSAL,
                'quantity'              => $quantity,
                'wac_unit_cost'         => bccomp($quantity, '0', 4) > 0 ? bcdiv($originalReceiptValue, $quantity, 4) : '0.0000',
                'wac_total_value'       => $originalReceiptValue,
                'actual_unit_cost'      => null,
                'actual_total_value'    => null,
                'project_id'            => $options['project_id'] ?? null,
                'sales_order_id'        => $options['sales_order_id'] ?? null,
                'job_order_id'          => $options['job_order_id'] ?? null,
                'reference_type'        => $options['reference_type'] ?? null,
                'reference_id'          => $options['reference_id'] ?? null,
                'movement_date'         => $options['movement_date'] ?? now()->toDateString(),
                'created_by'            => $actor->id,
                'notes'                 => $options['notes'] ?? 'Reversal of an earlier receipt — Goods Receipt Note reopened to draft.',
            ]);

            AuditLog::record($actor, 'stock.receipt_reversed', $movement, null, $movement->toArray());

            return $movement;
        });
    }

    // ── Issue (and anything that behaves like an issue) ─────────────

    public function issue(
        int $companyId,
        Product $product,
        int $inventoryLocationId,
        string $quantity,
        User $actor,
        array $options = []
    ): StockMovement {
        $this->assertPositiveQuantity($quantity);
        $this->assertActualCostConsistency(
            $options['project_id'] ?? null,
            $options['sales_order_id'] ?? null,
            $options['actual_unit_cost'] ?? null
        );
        $this->assertLocationBelongsToCompany($companyId, $inventoryLocationId);
        $this->assertProjectBelongsToCompany($companyId, $options['project_id'] ?? null);
        $this->assertSalesOrderBelongsToCompany($companyId, $options['sales_order_id'] ?? null);
        $this->assertJobOrderBelongsToCompany($companyId, $options['job_order_id'] ?? null);

        return DB::transaction(function () use ($companyId, $product, $inventoryLocationId, $quantity, $actor, $options) {

            $locationStock = $this->lockLocationStock($companyId, $product->id, $inventoryLocationId);
            if (bccomp($locationStock->quantity_on_hand, $quantity, 4) < 0) {
                throw new \DomainException(
                    "Insufficient stock for {$product->name_en} at this location: on hand {$locationStock->quantity_on_hand}, requested {$quantity}."
                );
            }

            // Manufacturing Variance Closing Design v1.0, Section 6 —
            // mirrors receive()'s own job_order_id skip. A Job-Order-
            // segmented issue never touches the pooled balance (it was
            // never added there); its cost comes from the caller
            // (that job's own segment unit cost, from
            // JobOrderStockBalanceService — this class deliberately
            // stays unaware of that table's internals, per Mahmoud's
            // request to keep the two concerns separate). The caller
            // is responsible for decrementing that job's own segment
            // separately after this call succeeds.
            if (empty($options['job_order_id'])) {
                $balance = $this->lockCompanyBalance($companyId, $product->id);
                if (bccomp($balance->quantity_on_hand, $quantity, 4) < 0) {
                    throw new \DomainException($this->insufficientPoolMessage($companyId, $product, $balance->quantity_on_hand, $quantity));
                }

                $wacUnitCost = $balance->wac_unit_cost;
                $issueValue  = $this->bcround(bcmul($quantity, $wacUnitCost, 6), 2);

                // Audit H16 (Sept 2026) — a Purchase Return sends goods back
                // at their ORIGINAL receipt cost ('unit_cost_override'), and
                // cancelling a Sales Return takes them out at the cost they
                // came back in at — both exact reversals of an earlier
                // movement. The remaining stock is then re-averaged, the
                // same way a receipt re-averages it. Capped at the book
                // value on hand, and taking the last unit takes all of the
                // remaining value — the caller posts any difference.
                $overrideCost = isset($options['unit_cost_override']) ? (string) $options['unit_cost_override'] : null;
                if ($overrideCost !== null) {
                    $issueValue = $this->bcround(bcmul($quantity, $overrideCost, 6), 2);
                    if (bccomp($balance->quantity_on_hand, $quantity, 4) === 0 || bccomp($issueValue, (string) $balance->wac_total_value, 2) > 0) {
                        $issueValue = bcadd((string) $balance->wac_total_value, '0', 2);
                    }
                    $wacUnitCost = bccomp($quantity, '0', 4) > 0 ? bcdiv($issueValue, $quantity, 4) : '0.0000';
                }

                $newQty   = bcsub($balance->quantity_on_hand, $quantity, 4);
                $newValue = bcsub($balance->wac_total_value, $issueValue, 2);
                if (bccomp($newValue, '0', 2) < 0) {
                    $newValue = '0.00'; // guards against rounding drift, never lets book value go negative
                }

                // wac_unit_cost is deliberately NOT recalculated here — only receipts move WAC
                // (an exact reversal at an override cost is the one exception — see above).
                $balance->update(array_filter([
                    'quantity_on_hand' => $newQty,
                    'wac_total_value'  => $newValue,
                    'wac_unit_cost'    => $overrideCost !== null
                        ? (bccomp($newQty, '0', 4) > 0 ? bcdiv($newValue, $newQty, 4) : '0.0000')
                        : null,
                ], fn ($v) => $v !== null));
            } else {
                if (! isset($options['unit_cost'])) {
                    throw new \DomainException('A Job-Order-segmented issue must supply that segment\'s own unit cost.');
                }
                $wacUnitCost = (string) $options['unit_cost'];
                $issueValue  = $this->bcround(bcmul($quantity, $wacUnitCost, 6), 2);
            }

            $this->adjustLocationQuantity($companyId, $product->id, $inventoryLocationId, bcmul($quantity, '-1', 4));

            $actualUnitCost = $options['actual_unit_cost'] ?? null;

            $movement = StockMovement::create([
                'company_id'            => $companyId,
                'product_id'            => $product->id,
                'inventory_location_id' => $inventoryLocationId,
                'movement_type'         => $options['movement_type'] ?? StockMovement::TYPE_ISSUE,
                'quantity'              => $quantity,
                'wac_unit_cost'         => $wacUnitCost,
                'wac_total_value'       => $issueValue,
                'actual_unit_cost'      => $actualUnitCost,
                'actual_total_value'    => $actualUnitCost !== null ? bcmul($quantity, $actualUnitCost, 2) : null,
                'project_id'            => $options['project_id'] ?? null,
                'sales_order_id'        => $options['sales_order_id'] ?? null,
                'job_order_id'          => $options['job_order_id'] ?? null,
                'reference_type'        => $options['reference_type'] ?? null,
                'reference_id'          => $options['reference_id'] ?? null,
                'movement_date'         => $options['movement_date'] ?? now()->toDateString(),
                'created_by'            => $actor->id,
                'notes'                 => $options['notes'] ?? null,
            ]);

            AuditLog::record($actor, 'stock.issued', $movement, null, $movement->toArray());

            return $movement;
        });
    }

    // ── Transfer between two locations — no cost impact ─────────────
    //
    // Same product, same company-wide WAC before and after. Only the
    // location-quantity split changes. Writes one transfer_out row
    // and one transfer_in row so the ledger shows both sides.
    //
    // 2026-09 addition — project_id / sales_order_id: a transfer can
    // now optionally carry the SAME project/SO tag forward onto BOTH
    // rows it writes. This is a deliberate, user-declared tag, not
    // something derived automatically — Vero CFO pools cost company-
    // wide (one WAC per product, no batch tracking), so there is no
    // way to algorithmically know "these specific units came from
    // Project X's receipt." The person doing the transfer states it,
    // same as a receipt line does. This is what lets Project
    // Inventory Statement compute a real "Remaining" figure — see
    // that service's header. No cost fields are touched by this —
    // a transfer still never carries actual_unit_cost; only the WAC
    // figures, exactly as before.

    public function transfer(
        int $companyId,
        Product $product,
        int $fromLocationId,
        int $toLocationId,
        string $quantity,
        User $actor,
        array $options = []
    ): array {
        $this->assertPositiveQuantity($quantity);
        $this->assertLocationBelongsToCompany($companyId, $fromLocationId);
        $this->assertLocationBelongsToCompany($companyId, $toLocationId);
        $this->assertProjectBelongsToCompany($companyId, $options['project_id'] ?? null);
        $this->assertSalesOrderBelongsToCompany($companyId, $options['sales_order_id'] ?? null);

        if ($fromLocationId === $toLocationId) {
            throw new \DomainException('Source and destination locations must be different.');
        }

        return DB::transaction(function () use ($companyId, $product, $fromLocationId, $toLocationId, $quantity, $actor, $options) {

            $fromStock = $this->lockLocationStock($companyId, $product->id, $fromLocationId);
            if (bccomp($fromStock->quantity_on_hand, $quantity, 4) < 0) {
                throw new \DomainException(
                    "Insufficient stock for {$product->name_en} at the source location: on hand {$fromStock->quantity_on_hand}, requested {$quantity}."
                );
            }

            // Lock the company balance purely to read a consistent wac_unit_cost —
            // quantity/value are untouched, a transfer never changes company-wide WAC.
            $balance     = $this->lockCompanyBalance($companyId, $product->id);
            $wacUnitCost = $balance->wac_unit_cost;
            $value       = $this->bcround(bcmul($quantity, $wacUnitCost, 6), 2);

            $this->adjustLocationQuantity($companyId, $product->id, $fromLocationId, bcmul($quantity, '-1', 4));
            $this->adjustLocationQuantity($companyId, $product->id, $toLocationId, $quantity);

            $movementDate = $options['movement_date'] ?? now()->toDateString();
            $projectId    = $options['project_id'] ?? null;
            $salesOrderId = $options['sales_order_id'] ?? null;

            $out = StockMovement::create([
                'company_id'            => $companyId,
                'product_id'            => $product->id,
                'inventory_location_id' => $fromLocationId,
                'movement_type'         => StockMovement::TYPE_TRANSFER_OUT,
                'quantity'              => $quantity,
                'wac_unit_cost'         => $wacUnitCost,
                'wac_total_value'       => $value,
                'project_id'            => $projectId,
                'sales_order_id'        => $salesOrderId,
                'reference_type'        => $options['reference_type'] ?? null,
                'reference_id'          => $options['reference_id'] ?? null,
                'movement_date'         => $movementDate,
                'created_by'            => $actor->id,
                'notes'                 => $options['notes'] ?? null,
            ]);

            $in = StockMovement::create([
                'company_id'            => $companyId,
                'product_id'            => $product->id,
                'inventory_location_id' => $toLocationId,
                'movement_type'         => StockMovement::TYPE_TRANSFER_IN,
                'quantity'              => $quantity,
                'wac_unit_cost'         => $wacUnitCost,
                'wac_total_value'       => $value,
                'project_id'            => $projectId,
                'sales_order_id'        => $salesOrderId,
                'reference_type'        => $options['reference_type'] ?? null,
                'reference_id'          => $options['reference_id'] ?? null,
                'movement_date'         => $movementDate,
                'created_by'            => $actor->id,
                'notes'                 => $options['notes'] ?? null,
            ]);

            AuditLog::record($actor, 'stock.transferred', $out, null, [
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $toLocationId,
                'quantity'         => $quantity,
            ]);

            return [$out, $in];
        });
    }

    // ── Manual adjustment — the audited correction path ──────────────
    //
    // direction 'in'  → requires $options['unit_cost'], behaves exactly
    //                    like a receipt (moves WAC).
    // direction 'out' → relieves at current WAC, behaves exactly like
    //                    an issue (never moves WAC).

    public function adjust(
        int $companyId,
        Product $product,
        int $inventoryLocationId,
        string $quantity,
        string $direction,
        User $actor,
        array $options = []
    ): StockMovement {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new \DomainException("Adjustment direction must be 'in' or 'out'.");
        }

        if ($direction === 'in') {
            if (empty($options['unit_cost'])) {
                throw new \DomainException('unit_cost is required for a positive (in) stock adjustment.');
            }

            return $this->receive(
                $companyId, $product, $inventoryLocationId, $quantity, $options['unit_cost'], $actor,
                array_merge($options, ['movement_type' => StockMovement::TYPE_ADJUSTMENT_IN])
            );
        }

        return $this->issue(
            $companyId, $product, $inventoryLocationId, $quantity, $actor,
            array_merge($options, ['movement_type' => StockMovement::TYPE_ADJUSTMENT_OUT])
        );
    }

    // ── Guards ────────────────────────────────────────────────────

    // INV-005 fix (confirmed with Mahmoud, 2026-09) — PHP's bcmath
    // functions (bcmul, bcdiv, bcadd, bcsub) only ever TRUNCATE at the
    // given scale; none of them round. That meant every money
    // calculation finalized here was quietly biased downward (e.g.
    // relieving 3 units @ 14.6153 produced 43.8459, truncated to
    // 43.84 instead of properly rounding to 43.85) — small per
    // transaction, but always in the same direction, never
    // correcting itself. This implements standard "round half up"
    // (the same convention SAP, Oracle, and ordinary invoicing use)
    // by adding a half-unit at the target precision before letting
    // bcadd's own truncation take over — which, applied AFTER that
    // nudge, is mathematically identical to rounding.
    /**
     * AUDIT M-13 — a receipt dated before a later issue used to be
     * refused, because the issue (and its journal) stayed at the old
     * average. The receipt is now accepted. Later issues are re-costed
     * on the stock subledger, and the difference is posted as a NEW
     * journal. The original issue journals are left as they were.
     */
    private function hasLaterWacIssue(int $companyId, int $productId, string $date): bool
    {
        return StockMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->whereNull('job_order_id')
            ->whereIn('movement_type', $this->wacRelievedTypes())
            ->where('movement_date', '>', $date)
            ->exists();
    }

    /**
     * Rewinds the pooled balance to the receipt date, applies the new
     * receipt, then replays every later movement. Issues relieved at
     * the running average get the new cost. Their old journals stay.
     *
     * @return string  extra cost now sitting on later issues (positive
     *                 means COGS should increase and inventory decrease)
     */
    private function revaluePoolForBackdatedReceipt(
        ProductStockBalance $balance,
        int $companyId,
        Product $product,
        string $receiptQty,
        string $receiptValue,
        string $date,
    ): string {
        $later = StockMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->whereNull('job_order_id')
            ->where('movement_date', '>', $date)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();

        $qty   = (string) $balance->quantity_on_hand;
        $value = (string) $balance->wac_total_value;

        foreach ($later as $movement) {
            [$qty, $value] = $this->undoPoolMovement($qty, $value, $movement);
        }

        $value = bcadd($value, $receiptValue, 2);
        $qty   = bcadd($qty, $receiptQty, 4);
        $wac   = bccomp($qty, '0', 4) > 0 ? bcdiv($value, $qty, 4) : '0.0000';

        $delta = '0.00';

        foreach ($later->reverse()->values() as $movement) {
            [$qty, $value, $wac, $issueDelta] = $this->replayPoolMovement($qty, $value, $wac, $movement);
            $delta = bcadd($delta, $issueDelta, 2);
        }

        $balance->update([
            'quantity_on_hand' => $qty,
            'wac_unit_cost'    => $wac,
            'wac_total_value'  => $value,
        ]);

        return $delta;
    }

    /** @return array{0: string, 1: string} */
    private function undoPoolMovement(string $qty, string $value, StockMovement $movement): array
    {
        $type = $movement->movement_type;

        if (in_array($type, $this->poolInTypes(), true)) {
            $qty   = bcsub($qty, (string) $movement->quantity, 4);
            $value = bcsub($value, (string) $movement->wac_total_value, 2);
        } elseif (in_array($type, $this->wacRelievedTypes(), true) || in_array($type, $this->storedOutTypes(), true)) {
            $qty   = bcadd($qty, (string) $movement->quantity, 4);
            $value = bcadd($value, (string) $movement->wac_total_value, 2);
        }

        return [$qty, $value];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function replayPoolMovement(string $qty, string $value, string $wac, StockMovement $movement): array
    {
        $type  = $movement->movement_type;
        $delta = '0.00';

        if (in_array($type, $this->poolInTypes(), true)) {
            $qty   = bcadd($qty, (string) $movement->quantity, 4);
            $value = bcadd($value, (string) $movement->wac_total_value, 2);
            $wac   = bccomp($qty, '0', 4) > 0 ? bcdiv($value, $qty, 4) : '0.0000';
        } elseif (in_array($type, $this->wacRelievedTypes(), true)) {
            $issueValue = $this->bcround(bcmul((string) $movement->quantity, $wac, 6), 2);
            $newValue   = bcsub($value, $issueValue, 2);
            if (bccomp($newValue, '0', 2) < 0) {
                $issueValue = $value;
                $newValue   = '0.00';
            }
            $delta = bcsub($issueValue, (string) $movement->wac_total_value, 2);
            $movement->update([
                'wac_unit_cost'   => $wac,
                'wac_total_value' => $issueValue,
            ]);
            $qty   = bcsub($qty, (string) $movement->quantity, 4);
            $value = $newValue;
        } elseif (in_array($type, $this->storedOutTypes(), true)) {
            $qty   = bcsub($qty, (string) $movement->quantity, 4);
            $value = bcsub($value, (string) $movement->wac_total_value, 2);
            if (bccomp($value, '0', 2) < 0) {
                $value = '0.00';
            }
            $wac = bccomp($qty, '0', 4) > 0 ? bcdiv($value, $qty, 4) : '0.0000';
        }

        return [$qty, $value, $wac, $delta];
    }

    private function postWacRevaluationJournal(
        int $companyId,
        Product $product,
        string $delta,
        string $receiptDate,
        int $movementId,
        User $actor,
    ): void {
        if (! $product->inventory_account_id || ! $product->cogs_account_id) {
            throw new \DomainException("{$product->name_en} needs an inventory account and a cost of sales account before a back-dated receipt can revalue later issues.");
        }

        $amount = ltrim($delta, '-');
        $increaseCogs = bccomp($delta, '0', 2) > 0;

        app(JournalEntryService::class)->post(
            $companyId,
            $this->openDateForAdjustment($companyId, $receiptDate),
            [
                [
                    'chart_of_account_id' => $increaseCogs ? $product->cogs_account_id : $product->inventory_account_id,
                    'debit'               => $amount,
                    'credit'              => '0',
                    'description'         => 'WAC revaluation — back-dated receipt',
                ],
                [
                    'chart_of_account_id' => $increaseCogs ? $product->inventory_account_id : $product->cogs_account_id,
                    'debit'               => '0',
                    'credit'              => $amount,
                    'description'         => 'WAC revaluation — back-dated receipt',
                ],
            ],
            'wac_revaluation',
            $movementId,
            "WAC revaluation for {$product->name_en} — receipt dated {$receiptDate} arrived after later issues.",
            $actor,
        );
    }

    /** Receipt date when that period is open; otherwise the first later open day. */
    private function openDateForAdjustment(int $companyId, string $preferred): string
    {
        $period = app(FiscalYearService::class)->periodForDate($companyId, $preferred);
        if ($period && $period->isOpen()) {
            return $preferred;
        }

        $open = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->where('end_date', '>=', $preferred)
            ->orderBy('start_date')
            ->first();

        if (! $open) {
            throw new \DomainException("Cannot revalue stock for a receipt dated {$preferred} — no open accounting period is available.");
        }

        $start = $open->start_date->toDateString();

        return $start > $preferred ? $start : $preferred;
    }

    /** @return list<string> */
    private function wacRelievedTypes(): array
    {
        return [
            StockMovement::TYPE_ISSUE,
            StockMovement::TYPE_ADJUSTMENT_OUT,
            StockMovement::TYPE_PRODUCTION_CONSUMPTION,
            StockMovement::TYPE_KIT_CONSUMPTION,
        ];
    }

    /** @return list<string> */
    private function poolInTypes(): array
    {
        return [
            StockMovement::TYPE_RECEIPT,
            StockMovement::TYPE_ADJUSTMENT_IN,
            StockMovement::TYPE_PRODUCTION_RECEIPT,
            StockMovement::TYPE_OPENING_BALANCE,
            StockMovement::TYPE_KIT_RECEIPT,
            StockMovement::TYPE_ISSUE_REVERSAL,
            StockMovement::TYPE_VARIANCE_ABSORPTION_IN,
        ];
    }

    /** @return list<string> */
    private function storedOutTypes(): array
    {
        return [
            StockMovement::TYPE_RECEIPT_REVERSAL,
            StockMovement::TYPE_VARIANCE_ABSORPTION_OUT,
        ];
    }

    private function bcround(string $number, int $scale = 2): string
    {
        $halfUnit = '0.' . str_repeat('0', $scale) . '5';

        return bccomp($number, '0', $scale + 10) >= 0
            ? bcadd($number, $halfUnit, $scale)
            : bcsub($number, $halfUnit, $scale);
    }

    private function assertPositiveQuantity(string $quantity): void
    {
        if (bccomp($quantity, '0', 4) <= 0) {
            throw new \DomainException('Stock movement quantity must be greater than zero.');
        }
    }

    // Enforces the dual-valuation rule at the point of write: a
    // project-assigned movement can never be saved without its actual
    // cost — this service has no way to derive that figure itself,
    // the caller must resolve it from the specific purchase line.
    // 2026-09 — broadened from "project only" to "project OR sales
    // order" (either one is a valid reason to require actual_unit_cost;
    // see migration 000220 and GoodsReceiptNoteService header for why).
    private function assertActualCostConsistency(?int $projectId, ?int $salesOrderId, ?string $actualUnitCost): void
    {
        if (($projectId !== null || $salesOrderId !== null) && $actualUnitCost === null) {
            throw new \DomainException(
                'A project- or sales-order-assigned stock movement must include actual_unit_cost, sourced from the specific purchase line — see the dual-valuation rule.'
            );
        }
    }

    // Cross-company guard (Building Bible Rule 2.5). Re-checks that the
    // location ID passed in actually belongs to the company making the
    // request, independent of whatever the Form Request already checked —
    // this service must not trust an ID just because it looks well-formed.
    private function assertLocationBelongsToCompany(int $companyId, int $locationId): void
    {
        $exists = InventoryLocation::where('company_id', $companyId)
            ->where('id', $locationId)
            ->exists();

        if (! $exists) {
            throw new \DomainException(
                'The selected inventory location does not belong to this company.'
            );
        }
    }

    // Same guard for project_id, only run when a project is actually
    // supplied — most stock movements are not project-assigned.
    private function assertProjectBelongsToCompany(int $companyId, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $exists = Project::where('company_id', $companyId)
            ->where('id', $projectId)
            ->exists();

        if (! $exists) {
            throw new \DomainException(
                'The selected project does not belong to this company.'
            );
        }
    }

    // Same guard for sales_order_id (2026-09 addition, mirrors
    // assertProjectBelongsToCompany exactly) — only run when a sales
    // order is actually supplied.
    private function assertSalesOrderBelongsToCompany(int $companyId, ?int $salesOrderId): void
    {
        if ($salesOrderId === null) {
            return;
        }

        $exists = SalesOrder::where('company_id', $companyId)
            ->where('id', $salesOrderId)
            ->exists();

        if (! $exists) {
            throw new \DomainException(
                'The selected sales order does not belong to this company.'
            );
        }
    }

    // Same guard for job_order_id (Manufacturing Variance Closing
    // Design v1.0, Section 6) — mirrors assertProjectBelongsToCompany
    // exactly.
    private function assertJobOrderBelongsToCompany(int $companyId, ?int $jobOrderId): void
    {
        if ($jobOrderId === null) {
            return;
        }

        $exists = JobOrder::where('company_id', $companyId)
            ->where('id', $jobOrderId)
            ->exists();

        if (! $exists) {
            throw new \DomainException(
                'The selected Job Order does not belong to this company.'
            );
        }
    }

    // ── Balance locking helpers ──────────────────────────────────────

    private function lockCompanyBalance(int $companyId, int $productId): ProductStockBalance
    {
        ProductStockBalance::firstOrCreate(
            ['company_id' => $companyId, 'product_id' => $productId],
            ['quantity_on_hand' => 0, 'wac_unit_cost' => 0, 'wac_total_value' => 0]
        );

        return ProductStockBalance::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    // MFG-002 fix (confirmed with Mahmoud, 2026-09) — "insufficient
    // stock: on hand 0" is misleading when the product is visibly
    // sitting in the warehouse, just reserved inside one or more Job
    // Orders' own segments (MFG-001). A read-only lookup here, via a
    // plain query rather than JobOrderStockBalanceService, so this
    // class stays unaware of that service's own logic — it only ever
    // reports what it finds, never acts on it.
    private function insufficientPoolMessage(int $companyId, Product $product, string $onHand, string $requested): string
    {
        $reserved = DB::table('job_order_stock_balances')
            ->join('job_orders', 'job_orders.id', '=', 'job_order_stock_balances.job_order_id')
            ->where('job_order_stock_balances.company_id', $companyId)
            ->where('job_order_stock_balances.product_id', $product->id)
            ->where('job_order_stock_balances.quantity_on_hand', '>', 0)
            ->pluck('job_order_stock_balances.quantity_on_hand', 'job_orders.job_code');

        if ($reserved->isEmpty()) {
            return "Insufficient stock for {$product->name_en}: on hand {$onHand}, requested {$requested}.";
        }

        $breakdown = $reserved->map(fn ($qty, $jobCode) => "{$qty} reserved for Job Order {$jobCode}")->implode('; ');

        return "Insufficient available stock for {$product->name_en}: only {$onHand} is unreserved " .
            "(requested {$requested}). The rest is physically in stock but reserved for a specific " .
            "customer's Job Order and cannot be used elsewhere: {$breakdown}. Deliver it against that " .
            "job's own Sales Order, or write it off from that Job Order specifically if it's been " .
            "damaged or lost.";
    }

    private function lockLocationStock(int $companyId, int $productId, int $locationId): ProductLocationStock
    {
        ProductLocationStock::firstOrCreate(
            ['company_id' => $companyId, 'product_id' => $productId, 'inventory_location_id' => $locationId],
            ['quantity_on_hand' => 0]
        );

        return ProductLocationStock::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('inventory_location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }

    private function adjustLocationQuantity(int $companyId, int $productId, int $locationId, string $delta): void
    {
        $row = $this->lockLocationStock($companyId, $productId, $locationId);
        $row->update(['quantity_on_hand' => bcadd($row->quantity_on_hand, $delta, 4)]);
    }
}
