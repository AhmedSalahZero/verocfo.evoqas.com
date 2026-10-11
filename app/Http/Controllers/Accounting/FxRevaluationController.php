<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FxRevaluationController
//
//  Accounting › Month-End FX Revaluation (Audit H12, IAS 21). Thin
//  HTTP layer — every rule and every figure lives in
//  FxRevaluationService (Rule 3).
//
//    index   — past runs + a month-end picker
//    create  — preview for a month-end (nothing posted yet)
//    store   — posts the revaluation and its day-1 reversal
//    show    — one posted / cancelled run, item by item
//    cancel  — reverses both journal entries, keeps the run
//
//  create and show render the SAME page (Rule 2): with a `run` prop
//  it is the read-only record, without it the preview.
//
//  @layer    Controller
//  @depends  FxRevaluationService, FxRevaluationRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FxRevaluationRequest;
use App\Models\FxRevaluationRun;
use App\Services\Accounting\FxRevaluationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FxRevaluationController extends Controller
{
    public function __construct(private FxRevaluationService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/FxRevaluations/Index', [
            'runs'        => $this->service->paginate($this->companyId($request)),
            'defaultDate' => $this->service->defaultDate(),
        ]);
    }

    public function create(Request $request)
    {
        $date = (string) $request->query('date', $this->service->defaultDate());
        try {
            $preview = $this->service->preview($this->companyId($request), $date);
        } catch (\DomainException $e) {
            return redirect()->route('accounting.fx-revaluations.index')->with('error', $e->getMessage());
        }

        return Inertia::render('Accounting/FxRevaluations/Form', ['preview' => $preview, 'run' => null]);
    }

    public function store(FxRevaluationRequest $request)
    {
        try {
            $run = $this->service->post($this->companyId($request), $request->validated('revaluation_date'), $request->validated('notes'), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.fx-revaluations.show', $run)->with('success', "FX revaluation [{$run->run_code}] posted, with its reversal on {$run->reversal_date->toDateString()}.");
    }

    public function show(Request $request, FxRevaluationRun $fxRevaluation)
    {
        return Inertia::render('Accounting/FxRevaluations/Form', [
            'preview' => null,
            'run'     => $this->service->show($this->companyId($request), $fxRevaluation),
        ]);
    }

    public function cancel(Request $request, FxRevaluationRun $fxRevaluation)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->service->cancel($this->companyId($request), $fxRevaluation, $data['cancel_reason'], $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "FX revaluation [{$fxRevaluation->run_code}] cancelled — both journal entries reversed.");
    }
}
