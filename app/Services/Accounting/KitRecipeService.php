<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitRecipeService
//
//  Assembly/Kit feature (VeroCFO_Manufacturing_Flexibility_Design_
//  v1_0.md, Section 3). CRUD for the recipe header + component
//  lines. Deliberately simple compared to BillOfMaterialService:
//  no version number, no is_active-per-version juggling, no
//  effective date — a recipe is just edited in place, always. This
//  is safe (not a shortcut) because KitAssemblyService never
//  snapshots a standard cost from the recipe — every assembly
//  resolves component cost fresh, at actual WAC, at the moment it
//  happens. Editing a recipe today has zero effect on any assembly
//  already posted — those already recorded their own actual
//  quantities/costs directly on stock_movements.
//
//  @layer    Service
//  @depends  KitRecipe, KitRecipeLine, Product, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\KitRecipe;
use App\Models\KitRecipeLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KitRecipeService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return KitRecipe::with('product:id,code,name_en,name_ar')
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->whereHas('product', fn ($q) => $q
                    ->where('code', 'like', "%{$s}%")
                    ->orWhere('name_en', 'like', "%{$s}%")
                )
            )
            ->withCount('lines')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): KitRecipe
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $kitProduct = Product::where('company_id', $companyId)->findOrFail($data['product_id']);

            if (! $kitProduct->has_stock) {
                throw new \DomainException("Product \"{$kitProduct->name_en}\" does not carry stock — only a stock-carrying product can be a Kit.");
            }

            if (KitRecipe::where('company_id', $companyId)->where('product_id', $kitProduct->id)->exists()) {
                throw new \DomainException("\"{$kitProduct->name_en}\" already has a Kit recipe. Edit the existing one instead of creating a second.");
            }

            $lines = $data['lines'] ?? [];
            $this->assertLinesValid($kitProduct->id, $lines);

            $recipe = KitRecipe::create([
                'company_id' => $companyId,
                'product_id' => $kitProduct->id,
                'is_active'  => true,
                'notes'      => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->syncLines($recipe, $companyId, $lines);

            AuditLog::record($actor, 'kit_recipe.created', $recipe, null, $recipe->toArray());

            return $recipe->fresh(['product', 'lines.componentProduct']);
        });
    }

    public function update(int $companyId, KitRecipe $recipe, array $data, User $actor): KitRecipe
    {
        return DB::transaction(function () use ($companyId, $recipe, $data, $actor) {
            $before = $recipe->toArray();

            $lines = $data['lines'] ?? [];
            $this->assertLinesValid($recipe->product_id, $lines);

            $recipe->update([
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($recipe, $companyId, $lines);

            AuditLog::record($actor, 'kit_recipe.updated', $recipe, $before, $recipe->fresh()->toArray());

            return $recipe->fresh(['product', 'lines.componentProduct']);
        });
    }

    public function toggleActive(int $companyId, KitRecipe $recipe, User $actor): KitRecipe
    {
        $recipe->update(['is_active' => ! $recipe->is_active]);

        AuditLog::record($actor, 'kit_recipe.toggled_active', $recipe, null, $recipe->fresh()->toArray());

        return $recipe->fresh();
    }

    // ── Internal ──────────────────────────────────────────────────

    private function assertLinesValid(int $kitProductId, array $lines): void
    {
        if (empty($lines)) {
            throw new \DomainException('A Kit recipe must have at least one component.');
        }

        foreach ($lines as $line) {
            if ((int) $line['component_product_id'] === $kitProductId) {
                throw new \DomainException('A Kit product cannot be a component of its own recipe.');
            }
        }
    }

    private function syncLines(KitRecipe $recipe, int $companyId, array $lines): void
    {
        $recipe->lines()->delete();

        foreach ($lines as $i => $line) {
            KitRecipeLine::create([
                'company_id'            => $companyId,
                'kit_recipe_id'         => $recipe->id,
                'component_product_id'  => $line['component_product_id'],
                'quantity_per_kit'      => $line['quantity_per_kit'],
                'sort_order'            => $i,
            ]);
        }
    }
}
