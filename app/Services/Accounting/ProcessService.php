<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ProcessService
//
//  Plain CRUD for user-defined manufacturing processes (Welding,
//  Cutting, Assembly, ...). No versioning, no status lifecycle beyond
//  active/inactive. Mirrors LaborCategoryService / OverheadPoolService
//  exactly.
//
//  @layer    Service
//  @depends  Process, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Process;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ProcessService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Process::when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): Process
    {
        $data['company_id'] = $companyId;
        $process = Process::create($data);

        AuditLog::record($actor, 'process.created', $process, null, $process->toArray());

        return $process;
    }

    public function update(Process $process, array $data, User $actor): Process
    {
        $before = $process->toArray();
        $process->update($data);

        AuditLog::record($actor, 'process.updated', $process, $before, $process->fresh()->toArray());

        return $process->fresh();
    }
}
