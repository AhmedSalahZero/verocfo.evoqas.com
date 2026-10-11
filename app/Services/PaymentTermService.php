<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  PaymentTermService
//
//  All business logic for Payment Terms management.
//  Called exclusively by PaymentTermController.
//
//  Responsibilities:
//    - Paginate terms for the settings index (system + company)
//    - Create a new custom payment term with its lines
//    - Update an existing custom term and its lines
//    - Toggle active/inactive status
//    - Validate that all lines sum to exactly 100%
//    - Guard system terms from modification or deletion
//
//  All writes use DB::transaction() for atomicity.
//  Audit log written on every create and update.
//  System terms (is_system = true) cannot be edited or deleted —
//  the service throws a DomainException if attempted.
//
//  @layer    Service
//  @depends  App\Models\PaymentTerm
//            App\Models\PaymentTermLine
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentTermService
{
    // ── List ──────────────────────────────────────────────────────

    /**
     * Returns system terms + this company's custom terms, paginated.
     */
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return PaymentTerm::forCompany($companyId)
            ->active()
            ->with('lines')
            // AUDIT M1 FIX: the two name conditions are grouped in
            // brackets. Before, "... AND name_en LIKE x OR name_ar LIKE x"
            // let the OR escape the company filter, so a search
            // returned other companies' terms.
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                $q->where(fn ($w) =>
                    $w->where('name_en', 'like', "%{$v}%")
                      ->orWhere('name_ar', 'like', "%{$v}%")
                )
            )
            ->orderByRaw('is_system DESC, name_en ASC')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Returns all active terms for a company — used in dropdowns.
     */
    public function listForDropdown(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentTerm::forCompany($companyId)
            ->active()
            ->with('lines')
            ->orderByRaw('is_system DESC, name_en ASC')
            ->get(['id', 'code', 'name_en', 'name_ar', 'is_system']);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): PaymentTerm
    {
        $this->validateLines($data['lines']);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $term = PaymentTerm::create([
                'company_id' => $companyId,
                'code'       => strtoupper($data['code']),
                'name_en'    => $data['name_en'],
                'name_ar'    => $data['name_ar'] ?? null,
                'is_system'  => false,
                'is_active'  => true,
            ]);

            $this->syncLines($term, $data['lines']);

            AuditLog::create([
                'company_id'      => $companyId,
                'user_id'         => $actor->id,
                'action'          => 'create',
                'auditable_type'  => PaymentTerm::class,
                'auditable_id'    => $term->id,
                'before'          => null,
                'after'           => $term->load('lines')->toArray(),
            ]);

            return $term;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(PaymentTerm $term, array $data, User $actor): PaymentTerm
    {
        if ($term->is_system) {
            throw new \DomainException('System payment terms cannot be modified.');
        }

        $this->validateLines($data['lines']);

        return DB::transaction(function () use ($term, $data, $actor) {
            $before = $term->load('lines')->toArray();

            $term->update([
                'code'    => strtoupper($data['code']),
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'] ?? null,
            ]);

            $this->syncLines($term, $data['lines']);

            AuditLog::create([
                'company_id'      => $term->company_id,
                'user_id'         => $actor->id,
                'action'          => 'update',
                'auditable_type'  => PaymentTerm::class,
                'auditable_id'    => $term->id,
                'before'          => $before,
                'after'           => $term->fresh('lines')->toArray(),
            ]);

            return $term->fresh('lines');
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(PaymentTerm $term, User $actor): PaymentTerm
    {
        if ($term->is_system) {
            throw new \DomainException('System payment terms cannot be deactivated.');
        }

        return DB::transaction(function () use ($term, $actor) {
            $before = ['is_active' => $term->is_active];
            $term->update(['is_active' => ! $term->is_active]);

            AuditLog::create([
                'company_id'      => $term->company_id,
                'user_id'         => $actor->id,
                'action'          => 'toggle_active',
                'auditable_type'  => PaymentTerm::class,
                'auditable_id'    => $term->id,
                'before'          => $before,
                'after'           => ['is_active' => $term->is_active],
            ]);

            return $term;
        });
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Validates that lines array is not empty and sums to 100%.
     * Uses bcmath for exact decimal comparison.
     */
    private function validateLines(array $lines): void
    {
        if (empty($lines)) {
            throw new \DomainException('A payment term must have at least one line.');
        }

        $sum = array_reduce($lines, function ($carry, $line) {
            return bcadd($carry, (string) ($line['percentage'] ?? '0'), 2);
        }, '0.00');

        if (bccomp($sum, '100.00', 2) !== 0) {
            throw new \DomainException(
                "Payment term lines must sum to 100%. Current sum: {$sum}%"
            );
        }
    }

    /**
     * Deletes existing lines and inserts new ones.
     * Called inside DB::transaction() — safe to delete-and-reinsert.
     */
    private function syncLines(PaymentTerm $term, array $lines): void
    {
        $term->lines()->delete();

        foreach ($lines as $index => $line) {
            $term->lines()->create([
                'percentage'      => $line['percentage'],
                'due_days'        => $line['due_days'] ?? 0,
                'reference_point' => $line['reference_point'] ?? 'invoice_date',
                'label'           => $line['label'] ?? null,
                'sort_order'      => $index + 1,
            ]);
        }
    }
}
