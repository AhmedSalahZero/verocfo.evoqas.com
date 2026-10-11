<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  IncomeTaxController
//
//  Accounting › General Ledger › Income Tax (Audit H19). Thin HTTP
//  layer over IncomeTaxService (Rule 3): the year's figures, record a
//  provision, set Debit WHT (142) off against the tax owed (226003),
//  and cancel an entry.
//
//  @layer    Controller
//  @depends  IncomeTaxService, IncomeTaxEntry
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\IncomeTaxEntry;
use App\Services\Accounting\IncomeTaxService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncomeTaxController extends Controller
{
    public function __construct(private IncomeTaxService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/IncomeTax/Index', [
            'summary' => $this->service->summary($this->companyId($request), $request->integer('fiscal_year_id') ?: null),
        ]);
    }

    public function storeProvision(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'], 'current_tax' => ['nullable', 'numeric', 'min:0'],
            'deferred_tax' => ['nullable', 'numeric'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->service->recordProvision($this->companyId($request), $data, $request->user()), 'Income tax provision posted.');
    }

    public function storeOffset(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->service->offsetWithholdingTax($this->companyId($request), $data, $request->user()), 'Debit WHT set off against income tax.');
    }

    public function cancel(Request $request, IncomeTaxEntry $incomeTaxEntry)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->companyId($request), $incomeTaxEntry, $data['cancel_reason'], $request->user()), 'Income tax entry cancelled — its journal entry was reversed.');
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
