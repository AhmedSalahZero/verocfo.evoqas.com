<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PurchaseReturnController
//
//  Accounting › Purchases › Purchase Returns (Audit H16). Thin HTTP layer over
//  PurchaseReturnService (Rule 3): the list with the "new return" panel,
//  post a return, cancel one.
//
//  @layer    Controller
//  @depends  PurchaseReturnService, PurchaseReturn
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\PurchaseReturn;
use App\Services\Accounting\PurchaseReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/PurchaseReturns/Index', [
            'returns'    => $this->service->paginate($companyId, $request->only('search')),
            'receipts'   => $this->service->returnableReceipts($companyId),
            'locations'  => InventoryLocation::where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name_en']),
            'filters'    => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'goods_receipt_note_id' => ['required', 'integer'], 'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.goods_receipt_note_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.location_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(fn () => $this->service->create($this->companyId($request), $data, $request->user()), 'Purchase return posted.');
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->companyId($request), $purchaseReturn, $data['cancel_reason'], $request->user()), 'Purchase return cancelled.');
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
