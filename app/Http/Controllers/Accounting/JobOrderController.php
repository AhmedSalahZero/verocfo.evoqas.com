<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderController
//
//  HTTP layer for the Job Order itself — same shape as
//  ProductionOrderController, but calling JobOrderService, a
//  genuinely separate service, per Mahmoud's "keep Job Order apart
//  from Mass Production" instruction. Gated by
//  Company::isManufacturing().
//
//  No update() — same reasoning as ProductionOrderController: a Job
//  Order's core fields are never edited after creation. show() is
//  the working page — material/labor/overhead postings, WIP, and
//  estimate-vs-actual variance all live there.
//
//  @layer    Controller
//  @depends  JobOrderService, JobOrderRequest, ConsumeJobMaterialRequest,
//            RecordJobLaborCostRequest, RecordJobOverheadCostRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ConsumeJobMaterialRequest;
use App\Http\Requests\Accounting\JobOrderOperationRequest;
use App\Http\Requests\Accounting\JobOrderPieceRequest;
use App\Http\Requests\Accounting\JobOrderRequest;
use App\Http\Requests\Accounting\LogJobOperationCostRequest;
use App\Http\Requests\Accounting\RecordJobLaborCostRequest;
use App\Http\Requests\Accounting\RecordJobOverheadCostRequest;
use App\Models\InventoryLocation;
use App\Models\JobOrder;
use App\Models\JobOrderOperation;
use App\Models\JobOrderPiece;
use App\Models\JobOrderTemplate;
use App\Models\LaborCategory;
use App\Models\Machine;
use App\Models\OverheadPool;
use App\Models\Process;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\Accounting\JobOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JobOrderController extends Controller
{
    public function __construct(private JobOrderService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/JobOrderIndex', [
            'jobs'    => $this->service->paginate($this->companyId($request), $request->only('status', 'product_id')),
            'filters' => $request->only('status', 'product_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Manufacturing/JobOrderForm', [
            'products'  => Product::where('company_id', $companyId)->where('nature', Product::NATURE_MANUFACTURED)->forDropdown()->get(),
            'templates' => JobOrderTemplate::where('company_id', $companyId)->active()->with('lines.componentProduct', 'lines.uom')->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en']),
            'salesOrders' => SalesOrder::where('company_id', $companyId)->orderByDesc('created_at')->get(['id', 'so_code', 'subject']),
        ]);
    }

    public function show(Request $request, JobOrder $job)
    {
        $companyId = $this->companyId($request);

        $job->load(
            'product', 'template', 'salesOrder', 'rawMaterialLocation', 'outputLocation',
            'lines.componentProduct', 'stockMovements.product', 'costEntries.overheadPool', 'costEntries.costCenter',
            // Section 6 — Piece/Operation Routing breakdown, optional
            // per job (empty for a simple, flat job).
            'pieces.operations.process', 'pieces.operations.machine', 'pieces.operations.laborCategory',
        );

        // Machines offered for a NEW Operation are Active-only (Section
        // 4.3) — plus, same "never hide history" pattern as BomForm,
        // every machine already used by one of this job's own
        // operations, even if it has since gone Under Maintenance/Retired.
        $alreadyUsedMachineIds = $job->pieces->flatMap(fn ($p) => $p->operations)->pluck('machine_id')->unique();

        return Inertia::render('Accounting/Manufacturing/JobOrderShow', [
            'job'              => $job,
            'wipBalance'       => $this->service->wipBalance($job),
            'variance'         => $job->status !== JobOrder::STATUS_DRAFT ? $this->service->variance($job) : null,
            'overheadPools'    => OverheadPool::where('company_id', $companyId)->active()->get(),
            'costCenters'      => \App\Models\CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            // Section 6 — pickers for adding an Operation to a Piece.
            'processes'        => Process::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
            'machines'         => Machine::where('company_id', $companyId)
                ->where(function ($q) use ($alreadyUsedMachineIds) {
                    $q->active();
                    if ($alreadyUsedMachineIds->isNotEmpty()) {
                        $q->orWhereIn('id', $alreadyUsedMachineIds);
                    }
                })
                ->orderBy('code')->get(['id', 'code', 'name', 'process_id']),
            'laborCategories'  => LaborCategory::where('company_id', $companyId)->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(JobOrderRequest $request)
    {
        $job = $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.job-orders.show', $job->id)->with('success', 'Job Order created.');
    }

    public function start(Request $request, JobOrder $job)
    {
        $this->service->start($job, $request->user());

        return back()->with('success', 'Job Order started.');
    }

    public function complete(Request $request, JobOrder $job)
    {
        $this->service->complete($job, $request->user());

        return back()->with('success', 'Job Order completed — Finished Goods updated.');
    }

    // 2026-09 — same full reversal as ProductionOrderController::setToDraft().
    public function setToDraft(Request $request, JobOrder $job)
    {
        $this->service->setToDraft($job, $request->user());

        return back()->with('success', 'Job Order reopened to draft.');
    }

    public function consumeMaterial(ConsumeJobMaterialRequest $request, JobOrder $job)
    {
        $data = $request->validated();
        $this->service->consumeMaterial($job, $data['component_product_id'], (string) $data['quantity'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null);

        return back()->with('success', 'Material consumption recorded.');
    }

    public function recordLabor(RecordJobLaborCostRequest $request, JobOrder $job)
    {
        $data = $request->validated();
        $this->service->recordLaborCost($job, (string) $data['hours'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null, $data['cost_center_id'] ?? null);

        return back()->with('success', 'Labor cost recorded.');
    }

    public function recordOverhead(RecordJobOverheadCostRequest $request, JobOrder $job)
    {
        $data = $request->validated();
        $pool = OverheadPool::where('company_id', $this->companyId($request))->findOrFail($data['overhead_pool_id']);
        $this->service->recordOverheadCost($job, $pool, (string) $data['base_quantity'], $request->user(), $data['entry_date'] ?? null, $data['notes'] ?? null, $data['cost_center_id'] ?? null);

        return back()->with('success', 'Overhead cost recorded.');
    }

    // ── Job Order Routing (Section 6) — optional Piece breakdown ──

    public function storePiece(JobOrderPieceRequest $request, JobOrder $job)
    {
        $this->service->addPiece($this->companyId($request), $job, $request->validated(), $request->user());

        return back()->with('success', 'Piece added.');
    }

    public function storeOperation(JobOrderOperationRequest $request, JobOrder $job, JobOrderPiece $piece)
    {
        $this->service->addOperation($this->companyId($request), $piece, $request->validated(), $request->user());

        return back()->with('success', 'Operation added.');
    }

    public function logOperationCost(LogJobOperationCostRequest $request, JobOrder $job, JobOrderPiece $piece, JobOrderOperation $operation)
    {
        $data = $request->validated();
        $this->service->logPieceOperationCost(
            $operation,
            (string) ($data['labor_hours'] ?? 0),
            (string) ($data['machine_hours'] ?? 0),
            $request->user(),
            $data['entry_date'] ?? null,
            $data['notes'] ?? null,
            $data['cost_center_id'] ?? null,
        );

        return back()->with('success', 'Cost logged.');
    }

    public function completePiece(Request $request, JobOrder $job, JobOrderPiece $piece)
    {
        $this->service->completePiece($piece, $request->user());

        return back()->with('success', 'Piece marked complete.');
    }
}
