<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  StockMovement
//
//  One row per physical inventory event. The permanent, append-only
//  ledger everything else in the Inventory Engine derives from —
//  see migration 000082 for the full dual-valuation rule.
//
//  wac_unit_cost / wac_total_value are ALWAYS populated and are the
//  only figures that ever post to the GL. actual_unit_cost /
//  actual_total_value are nullable — populated only when
//  project_id OR sales_order_id is set (2026-09 fix — previously
//  project_id only, silently dropping standalone-SO jobs; see
//  migration 000220), and feed Project P&L / Project Inventory
//  Statement only. Never written by this model directly — always
//  through StockLedgerService, which is the only place allowed to
//  compute WAC and write to this table.
//
//  project_id / sales_order_id are independent, both nullable, and
//  both can be set on the same row (an item tagged to a Sales Order
//  that itself belongs to a Project). transfer_out/transfer_in rows
//  now carry these tags forward too (2026-09, StockLedgerService::
//  transfer()) — a project's stock keeps its tag as it physically
//  moves between locations, which is what lets Project Inventory
//  Statement compute a real "Remaining" figure instead of only
//  seeing the original receipt.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, InventoryLocation, Project, SalesOrder, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Traits\SerializesDateOnly;

class StockMovement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // ── Movement Type Constants ─────────────────────────────────────
    const TYPE_RECEIPT               = 'receipt';
    const TYPE_ISSUE                 = 'issue';
    const TYPE_TRANSFER_OUT          = 'transfer_out';
    const TYPE_TRANSFER_IN           = 'transfer_in';
    const TYPE_ADJUSTMENT_IN         = 'adjustment_in';
    const TYPE_ADJUSTMENT_OUT        = 'adjustment_out';
    const TYPE_PRODUCTION_CONSUMPTION = 'production_consumption';
    const TYPE_PRODUCTION_RECEIPT    = 'production_receipt';
    const TYPE_JOB_CONSUMPTION       = 'job_consumption';
    const TYPE_JOB_RECEIPT           = 'job_receipt';
    const TYPE_OPENING_BALANCE       = 'opening_balance';
    const TYPE_RECEIPT_REVERSAL      = 'receipt_reversal';
    const TYPE_ISSUE_REVERSAL        = 'issue_reversal';
    // Assembly/Kit feature (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md,
    // Section 3) — deliberately distinct from production_consumption/
    // production_receipt: a Kit Assembly never goes through WIP or a
    // standard-cost snapshot, always actual WAC. See migration 000250.
    const TYPE_KIT_CONSUMPTION       = 'kit_consumption';
    const TYPE_KIT_RECEIPT           = 'kit_receipt';
    // RPT-003 fix (8 Sep 2026) — value-only revaluation, ZERO
    // quantity change. Written by VarianceClosingService when
    // absorbing manufacturing variance into a product's Finished
    // Goods value at month-end, so that StockLedgerService's
    // category/ledger value reports agree with product_stock_balances
    // instead of silently drifting apart. Same category of event as
    // SAP's MR21/MR22 "Price Change" documents or Oracle's "Inventory
    // value change" transactions — a real, dated entry, just one that
    // never moves physical stock. Included in INBOUND_TYPES/
    // OUTBOUND_TYPES below so value reports get the sign right
    // automatically, but deliberately excluded from the "has this
    // stock physically moved since receipt" guard in
    // reverseReceipt() — see the array_diff() there — since nothing
    // physical happened.
    const TYPE_VARIANCE_ABSORPTION_IN  = 'variance_absorption_in';
    const TYPE_VARIANCE_ABSORPTION_OUT = 'variance_absorption_out';

    // Movement types that ADD quantity to stock
    const INBOUND_TYPES = [
        self::TYPE_RECEIPT,
        self::TYPE_TRANSFER_IN,
        self::TYPE_ADJUSTMENT_IN,
        self::TYPE_PRODUCTION_RECEIPT,
        self::TYPE_JOB_RECEIPT,
        self::TYPE_OPENING_BALANCE,
        self::TYPE_ISSUE_REVERSAL,
        self::TYPE_KIT_RECEIPT,
        self::TYPE_VARIANCE_ABSORPTION_IN,
    ];

    // Movement types that REMOVE quantity from stock
    const OUTBOUND_TYPES = [
        self::TYPE_ISSUE,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_PRODUCTION_CONSUMPTION,
        self::TYPE_JOB_CONSUMPTION,
        self::TYPE_RECEIPT_REVERSAL,
        self::TYPE_KIT_CONSUMPTION,
        self::TYPE_VARIANCE_ABSORPTION_OUT,
    ];

    protected $fillable = [
        'company_id', 'product_id', 'inventory_location_id', 'movement_type',
        'quantity', 'wac_unit_cost', 'wac_total_value',
        'actual_unit_cost', 'actual_total_value', 'project_id', 'sales_order_id',
        'job_order_id', 'reference_type', 'reference_id',
        'movement_date', 'created_by', 'notes', 'reason_code', 'count_reference',
    ];

    protected $casts = [
        'quantity'            => 'decimal:4',
        'wac_unit_cost'       => 'decimal:4',
        'wac_total_value'     => 'decimal:2',
        'actual_unit_cost'    => 'decimal:4',
        'actual_total_value'  => 'decimal:2',
        'movement_date'       => 'date',
    ];

    public function isInbound(): bool
    {
        return in_array($this->movement_type, self::INBOUND_TYPES, true);
    }

    public function isOutbound(): bool
    {
        return in_array($this->movement_type, self::OUTBOUND_TYPES, true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    // Manufacturing Variance Closing Design v1.0, Section 6 — set when
    // this movement belongs to a Job Order's own valuation segment
    // (JobOrderStockBalance), never blended into the pooled company
    // WAC. See StockLedgerService::receive()'s job_order_id handling.
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
