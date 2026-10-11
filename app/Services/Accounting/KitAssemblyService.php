<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitAssemblyService
//
//  Assembly/Kit feature (VeroCFO_Manufacturing_Flexibility_Design_
//  v1_0.md, Section 3). The engine behind "assemble N units of this
//  kit": issues every component from stock at its current actual WAC,
//  receives the kit product into Finished Goods at the resulting
//  cost, and posts ONE balanced journal entry moving value from the
//  components' inventory accounts into the kit's own inventory
//  account. No WIP account is used — unlike Production Order, an
//  assembly is instant, not "in progress," so there is nothing to
//  hold mid-transaction.
//
//  ALWAYS ACTUAL COST — confirmed with Mahmoud, no standard-cost
//  concept exists here at all: every component is issued at
//  whatever its WAC happens to be right now, so there is no
//  "variance" to ever calculate or close. This is the whole reason
//  this feature needs no Draft/Active/effective-date machinery the
//  way BOM and Standard Rate do (see KitRecipeService header).
//
//  ROUNDING (confirmed acceptable, same convention already used in
//  ProductionOrderService::complete()/StandardRate cost derivation
//  elsewhere in this app): unit cost is derived via bcdiv to 4
//  decimal places, which can differ from the exact component total
//  by a sub-cent rounding residue once re-multiplied. Rather than
//  let that residue silently unbalance the journal entry (which
//  JournalEntryService::post() would reject outright), the residue
//  is absorbed into the largest credit line — the same "last line
//  absorbs the rounding residue" principle already established for
//  lease amortization schedules (Building Bible Rule 29.2).
//
//  @layer    Service
//  @depends  KitAssembly, KitRecipe, Product, StockMovement,
//            JournalEntry, User, AuditLog, StockLedgerService,
//            JournalEntryService, FiscalYearService
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\BackdatedPastNewerFiscalYearException;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\KitAssembly;
use App\Models\KitRecipe;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KitAssemblyService
{
    public function __construct(
        private StockLedgerService $stockLedger,
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return KitAssembly::with(['product:id,code,name_en', 'kitRecipe:id', 'inventoryLocation:id,code,name_en'])
            ->where('company_id', $companyId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Assemble ──────────────────────────────────────────────────

    public function assemble(int $companyId, KitRecipe $recipe, string $quantity, int $inventoryLocationId, User $actor, ?string $assemblyDate = null, ?string $notes = null): KitAssembly
    {
        if (bccomp($quantity, '0', 4) <= 0) {
            throw new \DomainException('Quantity to assemble must be greater than zero.');
        }

        if (! $recipe->is_active) {
            throw new \DomainException('This Kit recipe is inactive — reactivate it before assembling.');
        }

        $recipe->loadMissing('lines.componentProduct');

        if ($recipe->lines->isEmpty()) {
            throw new \DomainException('This Kit recipe has no components — nothing to assemble.');
        }

        $kitProduct = Product::where('company_id', $companyId)->findOrFail($recipe->product_id);

        if (! $kitProduct->inventory_account_id) {
            throw new \DomainException("Product \"{$kitProduct->name_en}\" has no inventory account assigned — cannot post.");
        }

        $assemblyDate = $assemblyDate ?? now()->toDateString();

        if ($this->fiscalYearService->isPeriodClosed($companyId, $assemblyDate)) {
            throw new \DomainException('The accounting period for this date is closed.');
        }

        // Rule 26.5 extension (confirmed with Mahmoud, September
        // 2026): assembling a Kit receives a new item into stock at a
        // computed cost — the same WAC-integrity concern the Goods
        // Receipt Note hard block exists to prevent.
        if ($this->fiscalYearService->newerFiscalYearExists($companyId, $assemblyDate)) {
            throw new BackdatedPastNewerFiscalYearException(
                "Cannot assemble Kit for \"{$kitProduct->name_en}\" — the assembly date ({$assemblyDate}) falls in a fiscal year that has since been superseded by a newer one. Kit Assembly cannot be back-dated once a newer fiscal year exists (Rule 26.5)."
            );
        }

        return DB::transaction(function () use ($companyId, $recipe, $kitProduct, $quantity, $inventoryLocationId, $actor, $assemblyDate, $notes) {

            $assembly = KitAssembly::create([
                'company_id'            => $companyId,
                'assembly_code'         => $this->generateAssemblyCode($companyId, $assemblyDate),
                'kit_recipe_id'         => $recipe->id,
                'product_id'            => $kitProduct->id,
                'inventory_location_id' => $inventoryLocationId,
                'quantity'              => $quantity,
                'status'                => KitAssembly::STATUS_POSTED,
                'assembly_date'         => $assemblyDate,
                'notes'                 => $notes,
                'created_by'            => $actor->id,
            ]);

            // 1. Issue every component at its current actual WAC.
            $componentMovements = [];
            $totalComponentValue = '0.00';

            foreach ($recipe->lines as $line) {
                $component = $line->componentProduct;

                if (! $component->inventory_account_id) {
                    throw new \DomainException("Component \"{$component->name_en}\" has no inventory account assigned — cannot post.");
                }

                $qtyNeeded = bcmul($line->quantity_per_kit, $quantity, 4);

                $movement = $this->stockLedger->issue($companyId, $component, $inventoryLocationId, $qtyNeeded, $actor, [
                    'movement_type'  => StockMovement::TYPE_KIT_CONSUMPTION,
                    'reference_type' => KitAssembly::class,
                    'reference_id'   => $assembly->id,
                    'movement_date'  => $assemblyDate,
                    'notes'          => $notes,
                ]);

                $componentMovements[] = ['movement' => $movement, 'account_id' => $component->inventory_account_id];
                $totalComponentValue  = bcadd($totalComponentValue, (string) $movement->wac_total_value, 2);
            }

            // 2. Receive the assembled kit at the resulting unit cost.
            $unitCost = bccomp($quantity, '0', 4) > 0 ? bcdiv($totalComponentValue, $quantity, 4) : '0.0000';

            $kitMovement = $this->stockLedger->receive($companyId, $kitProduct, $inventoryLocationId, $quantity, $unitCost, $actor, [
                'movement_type'  => StockMovement::TYPE_KIT_RECEIPT,
                'reference_type' => KitAssembly::class,
                'reference_id'   => $assembly->id,
                'movement_date'  => $assemblyDate,
                'notes'          => $notes,
            ]);

            // 3. One balanced journal entry: Dr kit inventory / Cr each
            // component's own inventory account (grouped, in case two
            // components share the same account).
            $debitTotal = (string) $kitMovement->wac_total_value;

            $creditsByAccount = [];
            foreach ($componentMovements as $entry) {
                $accId = $entry['account_id'];
                $creditsByAccount[$accId] = bcadd($creditsByAccount[$accId] ?? '0.00', (string) $entry['movement']->wac_total_value, 2);
            }

            $creditSum = '0.00';
            foreach ($creditsByAccount as $amount) {
                $creditSum = bcadd($creditSum, $amount, 2);
            }

            $residue = bcsub($debitTotal, $creditSum, 2);
            if (bccomp($residue, '0', 2) !== 0) {
                // Sub-cent rounding only (see class header) — absorbed into
                // the largest credit line so debit === credit exactly.
                $largestAccId = array_keys($creditsByAccount, max($creditsByAccount))[0];
                $creditsByAccount[$largestAccId] = bcadd($creditsByAccount[$largestAccId], $residue, 2);
            }

            $lines = [[
                'chart_of_account_id' => $kitProduct->inventory_account_id,
                'debit'               => $debitTotal,
                'credit'              => '0',
                'description'         => 'Kit assembled — '.$assembly->assembly_code,
            ]];

            foreach ($creditsByAccount as $accId => $amount) {
                $lines[] = [
                    'chart_of_account_id' => $accId,
                    'debit'               => '0',
                    'credit'              => (string) $amount,
                    'description'         => 'Kit assembled — '.$assembly->assembly_code,
                ];
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $assemblyDate,
                lines: $lines,
                sourceType: 'kit_assembly',
                sourceId: $assembly->id,
                narration: "Kit Assembly {$assembly->assembly_code} — {$quantity} units of {$kitProduct->name_en} assembled",
                actor: $actor,
            );

            $assembly->update([
                'total_cost'       => $debitTotal,
                'journal_entry_id' => $journalEntry->id,
            ]);

            AuditLog::record($actor, 'kit_assembly.created', $assembly, null, $assembly->fresh()->toArray());

            return $assembly->fresh(['kitRecipe', 'product', 'inventoryLocation']);
        });
    }

    // ── Reverse (Un-assemble) ────────────────────────────────────
    //
    // Puts every component back into stock (at the exact value it was
    // issued at), removes the kit from stock, and reverses the
    // journal entry — same "automatic-cascade reversal" philosophy as
    // ProductionOrderService::setToDraft() and every other reversible
    // document in this app. Blocked with a clear message if the kit
    // has already been sold/moved on since assembly — this is
    // StockLedgerService::reverseReceipt()'s own built-in protection,
    // inherited for free.

    public function reverse(KitAssembly $assembly, User $actor): KitAssembly
    {
        if ($assembly->status !== KitAssembly::STATUS_POSTED) {
            throw new \DomainException('Only a posted Kit Assembly can be reversed.');
        }

        if ($this->fiscalYearService->isPeriodClosed($assembly->company_id, $assembly->assembly_date->toDateString())) {
            throw new \DomainException('This Kit Assembly\'s accounting period is closed — it cannot be reversed.');
        }

        return DB::transaction(function () use ($assembly, $actor) {
            $before = $assembly->toArray();

            $movements = $assembly->stockMovements()->with('product')->get();
            $kitReceipt   = $movements->firstWhere('movement_type', StockMovement::TYPE_KIT_RECEIPT);
            $consumptions = $movements->where('movement_type', StockMovement::TYPE_KIT_CONSUMPTION);

            if ($kitReceipt) {
                $this->stockLedger->reverseReceipt(
                    $assembly->company_id,
                    $kitReceipt->product,
                    $kitReceipt->inventory_location_id,
                    (string) $kitReceipt->quantity,
                    (string) $kitReceipt->wac_total_value,
                    $actor,
                    [
                        'reference_type'       => KitAssembly::class,
                        'reference_id'         => $assembly->id,
                        'movement_date'        => $kitReceipt->movement_date->toDateString(),
                        'notes'                => 'Kit Assembly reversed — '.$assembly->assembly_code,
                        'original_movement_id' => $kitReceipt->id,
                    ]
                );
            }

            foreach ($consumptions as $movement) {
                $this->stockLedger->receive(
                    $assembly->company_id,
                    $movement->product,
                    $movement->inventory_location_id,
                    (string) $movement->quantity,
                    (string) $movement->wac_unit_cost,
                    $actor,
                    [
                        'movement_type'  => StockMovement::TYPE_ISSUE_REVERSAL,
                        'reference_type' => KitAssembly::class,
                        'reference_id'   => $assembly->id,
                        'movement_date'  => $movement->movement_date->toDateString(),
                        'notes'          => 'Kit Assembly reversed — '.$assembly->assembly_code,
                    ]
                );
            }

            if ($assembly->journal_entry_id) {
                $entry = JournalEntry::findOrFail($assembly->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Kit Assembly reversed — '.$assembly->assembly_code);
            }

            $assembly->update([
                'status'      => KitAssembly::STATUS_REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ]);

            AuditLog::record($actor, 'kit_assembly.reversed', $assembly, $before, $assembly->fresh()->toArray());

            return $assembly->fresh(['kitRecipe', 'product', 'inventoryLocation']);
        });
    }

    // ── Internal ──────────────────────────────────────────────────

    private function generateAssemblyCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\KitAssembly::class, 'assembly_code', 'KIT', $date);
    }
}
