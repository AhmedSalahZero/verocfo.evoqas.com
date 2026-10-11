<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LaborCategoryService
//
//  Plain CRUD for user-defined labor categories. No versioning, no
//  status lifecycle — a category is either active or not. Its rate
//  lives in StandardRate (see StandardRateService), not here. Mirrors
//  OverheadPoolService exactly.
//
//  @layer    Service
//  @depends  LaborCategory, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\LaborCategory;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class LaborCategoryService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LaborCategory::when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): LaborCategory
    {
        $data['company_id'] = $companyId;
        $category = LaborCategory::create($data);

        AuditLog::record($actor, 'labor_category.created', $category, null, $category->toArray());

        return $category;
    }

    public function update(LaborCategory $category, array $data, User $actor): LaborCategory
    {
        $before = $category->toArray();
        $category->update($data);

        AuditLog::record($actor, 'labor_category.updated', $category, $before, $category->fresh()->toArray());

        return $category->fresh();
    }
}
