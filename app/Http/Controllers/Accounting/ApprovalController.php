<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ApprovalController
//
//  Accounting › Approvals (Audit H3 / H5, Sept 2026). One screen where
//  a user with the Approve right sees everything waiting for a second
//  person, and approves or rejects it:
//    • manual journal entries (every one needs approval)
//    • requests to reopen a posted customer invoice / supplier bill
//  The requester can see their own pending items but can never
//  approve them (enforced in the services). Zero business logic here.
//
//  Routes (prefix accounting, name accounting.):
//    GET   /approvals                                    → index()
//    POST  /approvals/journals/{manualJournalRequest}/approve → approveJournal()
//    POST  /approvals/journals/{manualJournalRequest}/reject  → rejectJournal()
//    POST  /approvals/reopens/{documentReopenRequest}/approve → approveReopen()
//    POST  /approvals/reopens/{documentReopenRequest}/reject  → rejectReopen()
//
//  @layer    Controller
//  @depends  ManualJournalApprovalService, DocumentReopenService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\DocumentReopenRequest;
use App\Models\ManualJournalRequest;
use App\Services\Accounting\DocumentReopenService;
use App\Services\Accounting\ManualJournalApprovalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    public function __construct(
        private ManualJournalApprovalService $journals,
        private DocumentReopenService $reopens,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Approvals/Index', [
            'manualJournals' => $this->journals->pendingForScreen($companyId),
            'reopenRequests' => $this->reopens->pending($companyId)->map(fn ($r) => [
                'id' => $r->id, 'document_type' => $r->document_type, 'document_code' => $r->document_code,
                'reason' => $r->reason, 'requested_by' => $r->requestedBy?->name, 'requested_at' => $r->created_at?->toDateTimeString(),
                'is_mine' => (int) $r->requested_by === (int) $request->user()->id,
            ])->all(),
        ]);
    }

    public function approveJournal(Request $request, ManualJournalRequest $manualJournalRequest)
    {
        return $this->attempt(fn () => $this->journals->approve($manualJournalRequest, $request->user()), 'Manual journal entry approved and posted.');
    }

    public function rejectJournal(Request $request, ManualJournalRequest $manualJournalRequest)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(fn () => $this->journals->reject($manualJournalRequest, $request->user(), $data['reason']), 'Manual journal entry rejected.');
    }

    public function approveReopen(Request $request, DocumentReopenRequest $documentReopenRequest)
    {
        return $this->attempt(fn () => $this->reopens->approve($documentReopenRequest, $request->user()), 'Reopen approved — the document is back in draft.');
    }

    public function rejectReopen(Request $request, DocumentReopenRequest $documentReopenRequest)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(fn () => $this->reopens->reject($documentReopenRequest, $request->user(), $data['reason']), 'Reopen request rejected.');
    }

    private function attempt(callable $action, string $message)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
