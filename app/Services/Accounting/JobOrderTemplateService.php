<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderTemplateService
//
//  Simple CRUD for the optional, unversioned starting-point template
//  — deliberately lighter than BillOfMaterialService (no versioning,
//  no "one active per product" rule) since a template here is a
//  convenience, not a governed document. Editing a template never
//  retroactively affects a job already created from it — JobOrder
//  gets its own independent JobOrderLine rows at creation time (see
//  JobOrderService::create()).
//
//  @layer    Service
//  @depends  JobOrderTemplate, JobOrderTemplateLine, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\JobOrderTemplate;
use App\Models\JobOrderTemplateLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JobOrderTemplateService
{
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return JobOrderTemplate::with('product:id,code,name_en')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, array $data, User $actor): JobOrderTemplate
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;

            $template = JobOrderTemplate::create($data);
            $this->syncLines($template, $companyId, $lines);

            AuditLog::record($actor, 'job_order_template.created', $template, null, $template->toArray());

            return $template->fresh(['product', 'lines.componentProduct', 'lines.uom']);
        });
    }

    public function update(JobOrderTemplate $template, array $data, User $actor): JobOrderTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor) {
            $before = $template->toArray();

            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $template->update($data);
            $this->syncLines($template, $template->company_id, $lines);

            AuditLog::record($actor, 'job_order_template.updated', $template, $before, $template->fresh()->toArray());

            return $template->fresh(['product', 'lines.componentProduct', 'lines.uom']);
        });
    }

    // ── Internal ──────────────────────────────────────────────────

    private function syncLines(JobOrderTemplate $template, int $companyId, array $lines): void
    {
        $template->lines()->delete();

        foreach ($lines as $i => $line) {
            JobOrderTemplateLine::create([
                'company_id'             => $companyId,
                'job_order_template_id'  => $template->id,
                'component_product_id'   => $line['component_product_id'],
                'quantity_per_unit'      => $line['quantity_per_unit'],
                'uom_id'                 => $line['uom_id'] ?? null,
                'sort_order'             => $i,
            ]);
        }
    }
}
