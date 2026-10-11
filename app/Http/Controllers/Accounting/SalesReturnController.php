<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SalesReturnController
//
//  Accounting › Sales › Sales Returns (Audit H16). Thin HTTP layer over
//  SalesReturnService (Rule 3): the list with the "new return" panel,
//  post a return, cancel one.
//
//  @layer    Controller
//  @depends  SalesReturnService, SalesReturn
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\SalesReturn;
use App\Services\Accounting\SalesReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesReturnController extends Controller
{
    public function __construct(private SalesReturnService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/SalesReturns/Index', [
            'returns'    => $this->service->paginate($companyId, $request->only('search')),
            'deliveries' => $this->service->returnableDeliveries($companyId),
            'locations'  => InventoryLocation::where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name_en']),
            'filters'    => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'delivery_note_id' => ['required', 'integer'], 'return_date' => ['required', 'date'],
            'invoiced_treatment' => ['required', 'in:credit,replace'], 'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.delivery_note_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.location_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(fn () => $this->service->create($this->companyId($request), $data, $request->user()), 'Sales return posted.');
    }

    public function cancel(Request $request, SalesReturn $salesReturn)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->companyId($request), $salesReturn, $data['cancel_reason'], $request->user()), 'Sales return cancelled.');
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
