<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ManualJournalApprovalService
//
//  Maker-checker for manual journal entries (Audit H3, Sept 2026 —
//  confirmed with Mahmoud: EVERY manual journal needs a second
//  person, whatever the amount).
//
//    submit()  — the maker. Runs every posting check up front
//                (JournalEntryService::validateManual: balanced,
//                postable accounts, open period, no control account)
//                and saves a PENDING request. Nothing reaches the
//                ledger yet.
//    approve() — the checker: a DIFFERENT user with the Approve right.
//                Re-checks everything (the period may have closed in
//                between) and posts the real entry through
//                JournalEntryService::createManual(), recorded as
//                created by the maker. The approval itself goes to the
//                audit log.
//    reject()  — the checker declines, with a reason. Nothing posts.
//
//  The platform Super Admin may approve his own request (support and
//  test operations only); nobody else can.
//
//  @layer    Service
//  @depends  ManualJournalRequest, JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ManualJournalRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManualJournalApprovalService
{
    public function __construct(private JournalEntryService $journalEntries)
    {
    }

    public function submit(int $companyId, array $data, User $actor): ManualJournalRequest
    {
        $this->journalEntries->validateManual($companyId, $data['entry_date'], $data['debit_lines'], $data['credit_lines']);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $total = array_reduce($data['debit_lines'], fn ($c, $l) => bcadd($c, (string) $l['amount'], 2), '0.00');

            $request = ManualJournalRequest::create([
                'company_id'   => $companyId,
                'entry_date'   => $data['entry_date'],
                'narration'    => $data['narration'],
                'debit_lines'  => array_values($data['debit_lines']),
                'credit_lines' => array_values($data['credit_lines']),
                'total_amount' => $total,
                'status'       => ManualJournalRequest::STATUS_PENDING,
                'requested_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'manual_je.submitted', $request, null, $request->toArray());

            return $request;
        });
    }

    public function approve(ManualJournalRequest $request, User $actor): ManualJournalRequest
    {
        $this->assertDecidable($request, $actor);

        return DB::transaction(function () use ($request, $actor) {
            $locked = ManualJournalRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                throw new \DomainException('This request has already been decided.');
            }

            $maker = User::withoutGlobalScopes()->findOrFail($locked->requested_by);

            $entry = $this->journalEntries->createManual(
                companyId:   $locked->company_id,
                entryDate:   $locked->entry_date->toDateString(),
                debitLines:  $locked->debit_lines,
                creditLines: $locked->credit_lines,
                narration:   $locked->narration,
                actor:       $maker,
            );

            $before = $locked->toArray();
            $locked->update([
                'status'           => ManualJournalRequest::STATUS_APPROVED,
                'decided_by'       => $actor->id,
                'decided_at'       => now(),
                'journal_entry_id' => $entry->id,
            ]);

            AuditLog::record($actor, 'manual_je.approved', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh('journalEntry');
        });
    }

    public function reject(ManualJournalRequest $request, User $actor, string $reason): ManualJournalRequest
    {
        $this->assertDecidable($request, $actor);

        return DB::transaction(function () use ($request, $actor, $reason) {
            $before = $request->toArray();
            $request->update([
                'status'           => ManualJournalRequest::STATUS_REJECTED,
                'decided_by'       => $actor->id,
                'decided_at'       => now(),
                'rejection_reason' => $reason,
            ]);

            AuditLog::record($actor, 'manual_je.rejected', $request, $before, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    /** Pending requests for the Approvals screen, oldest first. */
    public function pending(int $companyId): Collection
    {
        return ManualJournalRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', ManualJournalRequest::STATUS_PENDING)
            ->with('requestedBy:id,name')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Pending requests shaped for the Approvals screen — every line with
     * its account code and name, so the approver sees exactly what will
     * post.
     */
    public function pendingForScreen(int $companyId): array
    {
        $requests = $this->pending($companyId);
        $accountIds = $requests->flatMap(fn ($r) => array_merge(
            array_column($r->debit_lines, 'chart_of_account_id'),
            array_column($r->credit_lines, 'chart_of_account_id')
        ))->unique()->all();

        $accounts = \App\Models\ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->whereIn('id', $accountIds)->get(['id', 'code', 'name_en'])->keyBy('id');

        $label = fn ($id) => ($a = $accounts[$id] ?? null) ? "{$a->code} — {$a->name_en}" : "#{$id}";

        return $requests->map(fn (ManualJournalRequest $r) => [
            'id'           => $r->id,
            'entry_date'   => $r->entry_date->toDateString(),
            'narration'    => $r->narration,
            'total_amount' => (string) $r->total_amount,
            'requested_by' => $r->requestedBy?->name,
            'requested_at' => $r->created_at?->toDateTimeString(),
            'is_mine'      => (int) $r->requested_by === (int) auth()->id(),
            'lines'        => array_merge(
                array_map(fn ($l) => ['account' => $label($l['chart_of_account_id']), 'debit' => (string) $l['amount'], 'credit' => '', 'description' => $l['description'] ?? ''], $r->debit_lines),
                array_map(fn ($l) => ['account' => $label($l['chart_of_account_id']), 'debit' => '', 'credit' => (string) $l['amount'], 'description' => $l['description'] ?? ''], $r->credit_lines),
            ),
        ])->all();
    }

    private function assertDecidable(ManualJournalRequest $request, User $actor): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('This request has already been decided.');
        }
        if (! $actor->isSuperAdmin() && (int) $request->requested_by === (int) $actor->id) {
            throw new \DomainException('You submitted this journal entry — it must be approved by someone else.');
        }
    }
}
