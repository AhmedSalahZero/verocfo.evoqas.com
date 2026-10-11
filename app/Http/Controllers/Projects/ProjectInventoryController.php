<?php

namespace App\Http\Controllers\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectInventoryController
//
//  Projects > Project Inventory Statement (Project Module Structure
//  v1.1, Section 6.5) — confirmed missing entirely before this build
//  (sidebar link existed, route did not). Pick a Project OR a
//  standalone Sales Order, see every item tagged to it: what was
//  received, at what actual cost, what's since moved to site, and
//  what's still remaining — see ProjectInventoryService header for
//  the full shape and the two data-model fixes this depends on.
//
//  Zero business logic here — reads delegate to
//  ProjectInventoryService; this is a read-only report, nothing here
//  ever writes.
//
//  Routes (under auth + active.company):
//    GET  /projects/inventory         → index()   picker form
//    GET  /projects/inventory/result  → result()  the statement itself
//    GET  /projects/inventory/export  → exportExcel()
//
//  @layer    Controller
//  @depends  ProjectInventoryService, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Exports\ProjectInventoryExport;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Services\Inventory\ProjectInventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ProjectInventoryController extends Controller
{
    public function __construct(private ProjectInventoryService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Projects/Inventory/Index', [
            'projects'    => $this->projectOptions($companyId),
            'salesOrders' => $this->standaloneSalesOrderOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $subject = $this->resolveSubject($companyId, $validated);

        $statement = $this->service->statement(
            $companyId,
            $validated['project_id'] ?? null,
            $validated['sales_order_id'] ?? null
        );

        return Inertia::render('Projects/Inventory/Result', [
            'subject'   => $subject,
            'statement' => $statement,
            'filters'   => $validated,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $subject = $this->resolveSubject($companyId, $validated);

        $statement = $this->service->statement(
            $companyId,
            $validated['project_id'] ?? null,
            $validated['sales_order_id'] ?? null
        );

        return Excel::download(
            new ProjectInventoryExport($statement, $subject['label']),
            'project-inventory-'.\Illuminate\Support\Str::slug($subject['label']).'.xlsx'
        );
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        $validated = $request->validate([
            'project_id'     => ['nullable', 'integer', 'required_without:sales_order_id'],
            'sales_order_id' => ['nullable', 'integer', 'required_without:project_id'],
        ]);

        // Project always wins if somehow both are sent — a subject is
        // exactly one or the other, never both.
        if (! empty($validated['project_id'])) {
            unset($validated['sales_order_id']);
        }

        return $validated;
    }

    /**
     * Resolves whichever subject was picked into a common shape for
     * the Vue page header and the export's sheet title.
     */
    private function resolveSubject(int $companyId, array $validated): array
    {
        if (! empty($validated['project_id'])) {
            $project = Project::where('company_id', $companyId)->findOrFail($validated['project_id']);

            return [
                'type'  => 'project',
                'id'    => $project->id,
                'label' => $project->contract_code.' — '.$project->name_en,
            ];
        }

        $so = SalesOrder::where('company_id', $companyId)->standalone()->findOrFail($validated['sales_order_id']);

        return [
            'type'  => 'sales_order',
            'id'    => $so->id,
            'label' => $so->so_code.($so->subject ? ' — '.$so->subject : ''),
        ];
    }

    private function projectOptions(int $companyId): array
    {
        return Project::where('company_id', $companyId)
            ->orderBy('name_en')
            ->get(['id', 'contract_code', 'name_en', 'name_ar', 'status'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->contract_code, 'name_en' => $p->name_en, 'name_ar' => $p->name_ar, 'status' => $p->status])
            ->values()
            ->all();
    }

    private function standaloneSalesOrderOptions(int $companyId): array
    {
        return SalesOrder::where('company_id', $companyId)
            ->standalone()
            ->orderBy('so_code')
            ->get(['id', 'so_code', 'subject', 'status'])
            ->map(fn ($so) => ['id' => $so->id, 'code' => $so->so_code, 'subject' => $so->subject, 'status' => $so->status])
            ->values()
            ->all();
    }
}
