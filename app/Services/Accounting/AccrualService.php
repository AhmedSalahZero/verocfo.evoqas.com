<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccrualService
//
//  All business logic for Accruals — see migration 000105 for the
//  full design rationale (the problem this solves, why it's a real
//  tracking entity and not just an account pick on a Bill line).
//
//  create() is itself a real GL posting — Dr [expense/asset account]
//  / Cr 228 Accrued Expenses — via JournalEntryService, exactly like
//  every other financial write in this app. This is NOT a draft
//  document; there is no "post" step separate from create() because
//  an accrual, by definition, only exists once the expense has
//  actually been incurred and needs to be on the books.
//
//  settle() is called exclusively by SupplierBillService, inside its
//  own DB::transaction(), when a Bill line references this accrual.
//  It does NOT post its own journal entry — the Bill's own entry
//  (Dr 228 / Cr 221) is what actually clears the accrual on the GL;
//  this method only updates the tracking row (settled_amount, status)
//  so the next Bill knows how much is still open. Throws
//  AccrualExceededException if the amount would exceed
//  remainingAmount() — this is the hard stop that keeps an accrual
//  from ever being over-settled across multiple Bills.
//
//  cancel() is only allowed while settled_amount is still zero — an
//  accrual with any settlement history is a real financial record
//  and is never removed, same append-only principle as everywhere
//  else in this app.
//
//  @layer    Service
//  @depends  Accrual, ChartOfAccount, JournalEntryService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\AccrualExceededException;
use App\Models\AuditLog;
use App\Models\Accrual;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccrualService
{
    public function __construct(private JournalEntryService $journalEntryService) {}

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Accrual::with(['partner:id,name_en,name_ar', 'chartOfAccount:id,code,name_en'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('accrual_code', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** Open (or partially settled) accruals for one supplier — feeds the Supplier Bill "settle an accrual" line picker. */
    public function openForPartner(int $companyId, int $partnerId): Collection
    {
        return Accrual::open()
            ->forPartner($partnerId)
            ->orderBy('accrual_date')
            ->get(['id', 'accrual_code', 'partner_id', 'chart_of_account_id', 'description', 'accrual_date', 'amount', 'settled_amount']);
    }

    // ── Create — posts the initial GL entry immediately ────────────

    public function create(int $companyId, array $data, User $actor): Accrual
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $accruedExpenseAccount = ChartOfAccount::findByCode($companyId, '228');

            if (! $accruedExpenseAccount) {
                throw new \DomainException('Accrued Expenses account (228) is not seeded for this company — cannot post.');
            }

            $amount = bcadd((string) $data['amount'], '0', 2);

            $accrual = Accrual::create([
                'company_id'          => $companyId,
                'accrual_code'        => $this->generateAccrualCode($companyId, $data['accrual_date'] ?? null),
                'partner_id'          => $data['partner_id'],
                'chart_of_account_id' => $data['chart_of_account_id'],
                'cost_center_id'      => $data['cost_center_id'] ?? null,
                'project_id'          => $data['project_id'] ?? null,
                'sales_order_id'      => $data['sales_order_id'] ?? null,
                'overhead_pool_id'    => $data['overhead_pool_id'] ?? null,
                'description'         => $data['description'],
                'accrual_date'        => $data['accrual_date'],
                'amount'              => $amount,
                'settled_amount'      => 0,
                'status'              => Accrual::STATUS_OPEN,
                'internal_notes'      => $data['internal_notes'] ?? null,
                'created_by'          => $actor->id,
            ]);

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['accrual_date'],
                lines: [
                    [
                        'chart_of_account_id' => $data['chart_of_account_id'],
                        'debit'               => $amount,
                        'credit'              => '0',
                        'cost_center_id'      => $data['cost_center_id'] ?? null,
                        'description'         => "Accrued — {$accrual->accrual_code}: {$data['description']}",
                    ],
                    [
                        'chart_of_account_id' => $accruedExpenseAccount->id,
                        'debit'               => '0',
                        'credit'              => $amount,
                        'description'         => "Accrued — {$accrual->accrual_code}: {$data['description']}",
                    ],
                ],
                sourceType: 'accrual',
                sourceId: $accrual->id,
                narration: "Accrual {$accrual->accrual_code} — {$data['description']}",
                actor: $actor
            );

            $accrual->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'accrual.created', $accrual, null, $accrual->fresh()->toArray());

            return $accrual->fresh(['partner', 'chartOfAccount', 'journalEntry.lines']);
        });
    }

    // ── Settle — called only by SupplierBillService, inside its own transaction ──

    /**
     * Applies a settlement amount against this accrual. Does NOT post
     * a journal entry itself — the caller's Bill entry (Dr 228 / Cr
     * 221) is what actually clears the accrual on the GL. This only
     * advances the tracking row. Throws AccrualExceededException if
     * the amount would exceed remainingAmount().
     */
    public function applySettlement(Accrual $accrual, string $amount, User $actor): Accrual
    {
        $locked = Accrual::lockForUpdate()->findOrFail($accrual->id);

        if (bccomp($amount, $locked->remainingAmount(), 2) > 0) {
            throw new AccrualExceededException(
                "Settling {$amount} on accrual {$locked->accrual_code} would exceed its remaining amount " .
                "of {$locked->remainingAmount()}."
            );
        }

        $before = $locked->toArray();
        $newSettled = bcadd((string) $locked->settled_amount, $amount, 2);

        $locked->update([
            'settled_amount' => $newSettled,
            'status'         => bccomp($newSettled, (string) $locked->amount, 2) >= 0
                ? Accrual::STATUS_FULLY_SETTLED
                : Accrual::STATUS_PARTIALLY_SETTLED,
        ]);

        AuditLog::record($actor, 'accrual.settled', $locked, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    // ── Cancel (only while nothing has been settled against it) ────

    public function cancel(Accrual $accrual, User $actor): Accrual
    {
        if ($accrual->hasAnySettlement()) {
            throw new \DomainException('An accrual with any settled amount cannot be cancelled — it is a real financial record.');
        }

        return DB::transaction(function () use ($accrual, $actor) {
            $before = $accrual->toArray();

            if ($accrual->journal_entry_id) {
                $this->journalEntryService->reverse($accrual->journalEntry, $actor, "Cancellation of accrual {$accrual->accrual_code}");
            }

            $accrual->update(['status' => Accrual::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            AuditLog::record($actor, 'accrual.cancelled', $accrual, $before, $accrual->fresh()->toArray());

            return $accrual->fresh();
        });
    }

    // ── Private helpers ───────────────────────────────────────────

    private function generateAccrualCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\Accrual::class, 'accrual_code', 'ACR', $date);
    }
}
