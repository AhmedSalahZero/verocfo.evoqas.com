<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  KitAssembly
//
//  The transaction header for "assemble N units of this kit."
//  Components actually consumed are NOT stored as lines on this
//  model — they're read back from stock_movements (reference_type =
//  self::class, movement_type = kit_consumption), same pattern
//  ProductionOrder already uses for its own material consumption.
//  See migration 000253 header and KitAssemblyService for the full
//  reasoning and the read path.
//
//  @layer    Model
//  @depends  BelongsToCompany, KitRecipe, Product, InventoryLocation,
//            JournalEntry, User, StockMovement, SerializesDateOnly
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class KitAssembly extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED   = 'posted';
    const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'assembly_code', 'kit_recipe_id', 'product_id',
        'inventory_location_id', 'quantity', 'total_cost', 'status',
        'assembly_date', 'journal_entry_id', 'notes', 'created_by',
        'reversed_by', 'reversed_at',
    ];

    protected $casts = [
        'quantity'     => 'decimal:4',
        'total_cost'   => 'decimal:2',
        'assembly_date' => 'date',
        'reversed_at'  => 'datetime',
    ];

    public function kitRecipe(): BelongsTo
    {
        return $this->belongsTo(KitRecipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    // The physical/cost trail for this assembly, read from the shared
    // stock ledger rather than a duplicated lines table — inverse of
    // StockMovement::reference(), same pattern as ProductionOrder.
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
