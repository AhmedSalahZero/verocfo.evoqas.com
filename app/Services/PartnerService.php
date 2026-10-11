<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  PartnerService
//
//  All business logic for Partner management.
//  Called exclusively by PartnerController — zero logic in controller.
//
//  Responsibilities:
//    - Paginate partners with search, type, and status filters
//    - Create a new partner with auto-generated code P-00001
//    - Update a partner with employee history tracking
//    - Toggle active/inactive status
//    - Sync partner contacts (create/update/delete in one operation)
//
//  Employee history tracking:
//    On every update, PartnerService compares old vs new values
//    for department, job_title, and cost_center_id. If any changed
//    AND the partner has the employee type, a row is written to
//    partner_employee_history with effective_date = today.
//
//  All writes use DB::transaction(). Audit log on every financial
//  write per Structure Bible Rule 14.1.
//
//  @layer    Service
//  @depends  App\Models\Partner
//            App\Models\PartnerContact
//            App\Models\PartnerEmployeeHistory
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PartnerContact;
use App\Models\PartnerEmployeeHistory;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PartnerService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Partner::query()
            ->with(['businessSector', 'collectionTerm', 'paymentTerm'])
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                $q->search($v)
            )
            ->when($filters['type'] ?? null, fn ($q, $v) =>
                $q->ofType($v)
            )
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '',
                fn ($q) => $q->where('is_active', (bool) $filters['is_active'])
            )
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Returns all active partners for a company — used in dropdowns.
     * Optionally filtered by type.
     */
    public function listForDropdown(int $companyId, ?string $type = null)
    {
        return Partner::query()
            ->active()
            ->when($type, fn ($q) => $q->ofType($type))
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar', 'types']);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): Partner
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $partner = Partner::create(array_merge(
                $this->prepareData($data),
                [
                    'company_id' => $companyId,
                    'code'       => Partner::nextCode($companyId),
                    'is_active'  => true,
                ]
            ));

            // Sync contacts if provided
            if (! empty($data['contacts'])) {
                $this->syncContacts($partner, $data['contacts'], $companyId);
            }

            AuditLog::record($actor, 'create', $partner, null, $partner->toArray());

            return $partner;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(Partner $partner, array $data, User $actor): Partner
    {
        return DB::transaction(function () use ($partner, $data, $actor) {

            $before = $partner->toArray();

            // Track employee field changes before saving
            if ($partner->isEmployee() || in_array('employee', $data['types'] ?? [], true)) {
                $this->trackEmployeeChanges($partner, $data, $actor);
            }

            $partner->update($this->prepareData($data));

            // Sync contacts if provided
            if (array_key_exists('contacts', $data)) {
                $this->syncContacts($partner, $data['contacts'] ?? [], $partner->company_id);
            }

            AuditLog::record($actor, 'update', $partner, $before, $partner->fresh()->toArray());

            return $partner->fresh(['contacts', 'businessSector', 'collectionTerm', 'paymentTerm']);
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Partner $partner, User $actor): Partner
    {
        return DB::transaction(function () use ($partner, $actor) {

            $before = ['is_active' => $partner->is_active];
            $partner->update(['is_active' => ! $partner->is_active]);

            AuditLog::record($actor, 'toggle_active', $partner, $before, ['is_active' => $partner->is_active]);

            return $partner;
        });
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Strips non-fillable keys and sanitises types array.
     */
    private function prepareData(array $data): array
    {
        // Ensure types is always a clean array of valid type strings
        $data['types'] = array_values(
            array_intersect($data['types'] ?? [], Partner::ALL_TYPES)
        );

        // Remove contacts key — handled separately
        unset($data['contacts']);

        return $data;
    }

    /**
     * Syncs the contacts repeater.
     * Existing contacts not in the new list are deleted.
     * Contacts with an id are updated. New contacts are created.
     */
    private function syncContacts(Partner $partner, array $contacts, int $companyId): void
    {
        $incomingIds = collect($contacts)->pluck('id')->filter()->all();

        // Delete removed contacts
        $partner->contacts()
            ->whereNotIn('id', $incomingIds)
            ->delete();

        // Ensure only one primary contact
        $hasPrimary = false;

        foreach ($contacts as $index => $contactData) {
            // First contact is primary if none explicitly marked
            if (! $hasPrimary && ($contactData['is_primary'] ?? false)) {
                $hasPrimary = true;
            } elseif ($index === 0 && ! $hasPrimary) {
                $contactData['is_primary'] = true;
                $hasPrimary = true;
            } else {
                $contactData['is_primary'] = false;
            }

            $payload = [
                'company_id' => $companyId,
                'partner_id' => $partner->id,
                'name_en'    => $contactData['name_en'],
                'name_ar'    => $contactData['name_ar'] ?? null,
                'job_title'  => $contactData['job_title'] ?? null,
                'email'      => $contactData['email'] ?? null,
                'phone'      => $contactData['phone'] ?? null,
                'mobile'     => $contactData['mobile'] ?? null,
                'is_primary' => $contactData['is_primary'] ?? false,
                'is_active'  => true,
            ];

            if (! empty($contactData['id'])) {
                PartnerContact::where('id', $contactData['id'])
                    ->where('partner_id', $partner->id)
                    ->update($payload);
            } else {
                PartnerContact::create($payload);
            }
        }
    }

    /**
     * Compares old vs new values for tracked employee fields.
     * Writes one history row per changed field.
     */
    private function trackEmployeeChanges(Partner $partner, array $data, User $actor): void
    {
        foreach (PartnerEmployeeHistory::TRACKED_FIELDS as $field) {
            $oldValue = (string) ($partner->{$field} ?? '');
            $newValue = (string) ($data[$field] ?? '');

            if ($oldValue === $newValue) {
                continue;
            }

            PartnerEmployeeHistory::create([
                'company_id'     => $partner->company_id,
                'partner_id'     => $partner->id,
                'field'          => $field,
                'old_value'      => $oldValue ?: null,
                'new_value'      => $newValue ?: null,
                'effective_date' => now()->toDateString(),
                'changed_by'     => $actor->id,
            ]);
        }
    }
}
