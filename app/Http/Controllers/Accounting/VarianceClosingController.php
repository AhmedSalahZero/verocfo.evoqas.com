<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  VarianceClosingController
//
//  HTTP layer for the monthly Manufacturing Variance Closing —
//  Manufacturing Variance Closing Design v1.0, Section 1/Step 3,
//  piece 3. See VarianceClosingService's own header for the full
//  accounting design.
//
//  Deliberately minimal (2026-09) — index() lists past closings and
//  lets a period be picked; preview() shows what computeProposal()
//  calculates WITHOUT posting; post() actually posts it. The
//  confirmed "review and override before posting" screen (Section
//  1.4) is its own, separate piece of UI work, not built here — this
//  gives a working, if plain, way to run the closing while that's
//  designed.
//
//  @layer    Controller
//  @depends  VarianceClosingService, ManufacturingVarianceClosing
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ManufacturingVarianceClosing;
use App\Services\Accounting\VarianceClosingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VarianceClosingController extends Controller
{
    public function __construct(private VarianceClosingService $service) {}

    public function index(Request $request)
    {
        $closings = ManufacturingVarianceClosing::where('company_id', $this->companyId($request))
            ->with('journalEntry:id,entry_number', 'postedBy:id,name', 'reversedBy:id,name')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->paginate(20);

        return Inertia::render('Accounting/Manufacturing/VarianceClosingIndex', [
            'closings' => $closings,
        ]);
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'period_year'  => ['required', 'integer'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $proposal = $this->service->computeProposal($this->companyId($request), (int) $data['period_year'], (int) $data['period_month']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return Inertia::render('Accounting/Manufacturing/VarianceClosingPreview', [
            'proposal' => $proposal,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_year'                  => ['required', 'integer'],
            'period_month'                 => ['required', 'integer', 'min:1', 'max:12'],
            'overrides.products'           => ['nullable', 'array'],
            'overrides.products.*'         => ['numeric'],
            'overrides.job_orders'         => ['nullable', 'array'],
            'overrides.job_orders.*'       => ['numeric'],
            'override_reason'              => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $closing = $this->service->post(
                $this->companyId($request),
                (int) $data['period_year'],
                (int) $data['period_month'],
                $request->user(),
                $data['overrides'] ?? [],
                $data['override_reason'] ?? null
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.variance-closing.index')
            ->with('success', "Manufacturing Variance for {$closing->periodLabel()} closed.");
    }

    public function reverse(Request $request, int $closing)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $companyId = $this->companyId($request);
        $record = ManufacturingVarianceClosing::where('company_id', $companyId)->findOrFail($closing);

        try {
            $this->service->reverse($companyId, $record, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Manufacturing Variance closing for {$record->periodLabel()} reversed.");
    }
}
