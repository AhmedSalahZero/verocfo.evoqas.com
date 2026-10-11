<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JournalEntryController
//
//  Minimal, read-only Journal Entry viewer (July 2026) — this is
//  Point 6 from the July 07 cross-audit discussion: there was
//  previously NO way to see what a Goods Receipt Note (or anything
//  else) actually posted to the GL. `routes/web.php` had a
//  placeholder route that just rendered the Dashboard.
//
//  Was deliberately minimal (July 2026): list + expand the lines of
//  one entry, no manual entry creation — that was flagged as
//  separate, future Phase 4 work.
//
//  Phase 4 (September 2026, per Mahmoud): create()/store() add
//  exactly that — Manual Journal Entry, the one exception tool for
//  adjustments/accruals/corrections a business document doesn't
//  cover. JournalEntryService remains the only WRITER to these
//  tables; create()/store() call its createManual() the same way
//  index()/lines() call its read methods — this controller still has
//  zero business logic of its own.
//
//  show()/setToDraft() (same session) — Manual Journal Entry's own
//  "Set to Draft", deliberately simpler than every other document's:
//  there's no separate draft row to reopen (the journal entry IS the
//  record), so setToDraft() only ever reverses it. show() renders
//  that entry read-only until Set to Draft is clicked, at which
//  point the Vue page unlocks the SAME fields for editing and posts
//  the edit as a brand-new entry via store() above — this controller
//  adds no new "recreate" logic, it's the same create flow reused.
//
//  Routes (under auth + active.company):
//    GET   /accounting/journal-entries                        → index()
//    GET   /accounting/journal-entries/create                  → create()
//    POST  /accounting/journal-entries                         → store()
//    GET   /accounting/journal-entries/{entry}                 → show()      (manual only)
//    PATCH /accounting/journal-entries/{entry}/set-to-draft     → setToDraft() (manual only)
//    GET   /accounting/journal-entries/{entry}/lines           → lines() (JSON, expand-a-row)
//
//  Audit H3 (Sept 2026): store() submits the entry for APPROVAL
//  (ManualJournalApprovalService) — it posts only when a different
//  user approves it on the Approvals screen.
//
//  @layer    Controller
//  @depends  JournalEntryService, JournalEntry, ChartOfAccount, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ManualJournalEntryRequest;
use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JournalEntryController extends Controller
{
    public function __construct(private JournalEntryService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/JournalEntries/Index', [
            'journalEntries' => $this->service->paginate($companyId, $request->only('search', 'source_type', 'journal_type', 'date_from', 'date_to')),
            'filters'        => $request->only('search', 'source_type', 'journal_type', 'date_from', 'date_to'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/JournalEntries/Form', [
            'accounts'    => $this->postableAccounts($companyId),
            'costCenters' => $this->activeCostCenters(),
        ]);
    }

    /**
     * Audit H3 (Sept 2026, confirmed with Mahmoud): a manual journal no
     * longer posts on save — it is submitted for approval by a different
     * user (Accounting › Approvals). Every posting check still runs now.
     */
    public function store(ManualJournalEntryRequest $request)
    {
        try {
            app(\App\Services\Accounting\ManualJournalApprovalService::class)
                ->submit($this->companyId($request), $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('accounting.journal-entries.index')
            ->with('success', 'Manual Journal Entry submitted — it will post once another user approves it (Accounting › Approvals).');
    }

    /** Manual entries only — the "View" page that Set to Draft / edit-and-repost lives on. */
    public function show(Request $request, JournalEntry $journalEntry)
    {
        $companyId = $this->companyId($request);
        abort_unless($journalEntry->company_id === $companyId, 404);
        abort_unless($journalEntry->isManual(), 404);

        $entry = $this->service->withLines($companyId, $journalEntry);

        return Inertia::render('Accounting/JournalEntries/Show', [
            'journalEntry' => [
                'id'           => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date'   => $entry->entry_date->toDateString(),
                'narration'    => $entry->narration,
                'status'       => $entry->status,
                'lines'        => $entry->lines->map(fn ($line) => [
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'account_code'        => $line->chartOfAccount?->code,
                    'account_name'        => $line->chartOfAccount?->name_en,
                    'cost_center_id'      => $line->cost_center_id,
                    'cost_center_label'   => $line->costCenter ? "{$line->costCenter->code} — {$line->costCenter->name_en}" : null,
                    'description'         => $line->description,
                    'debit'               => (string) $line->debit,
                    'credit'              => (string) $line->credit,
                ]),
            ],
            'accounts'    => $this->postableAccounts($companyId),
            'costCenters' => $this->activeCostCenters(),
        ]);
    }

    /** Reverses the entry — the only thing Manual JE's own Set to Draft does (see JournalEntryService::setToDraft() header). */
    public function setToDraft(Request $request, JournalEntry $journalEntry)
    {
        abort_unless($journalEntry->company_id === $this->companyId($request), 404);

        $this->service->setToDraft($journalEntry, $request->user());

        return back()->with('success', "Journal Entry [{$journalEntry->entry_number}] reversed — edit the lines below and post the replacement.");
    }

    /** Live lookup — one entry's lines, for expanding a row on the Index page. */
    public function lines(Request $request, JournalEntry $journalEntry)
    {
        $entry = $this->service->withLines($this->companyId($request), $journalEntry);

        return response()->json([
            'lines' => $entry->lines->map(fn ($line) => [
                'account_code' => $line->chartOfAccount?->code,
                'account_name' => $line->chartOfAccount?->name_en,
                'debit'        => (string) $line->debit,
                'credit'       => (string) $line->credit,
                'description'  => $line->description,
            ]),
        ]);
    }

    private function postableAccounts(int $companyId)
    {
        return ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->postable()->active()->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar']);
    }

    private function activeCostCenters()
    {
        return CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']);
    }
}
