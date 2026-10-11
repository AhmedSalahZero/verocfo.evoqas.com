<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringService
//
//  Factoring Company master + Factoring Agreements (terms only in Layer 1C).
//  Factoring transactions (invoice assignment, repayment) wired in Layer 8.
//
//  v1.1 — Agreement Renewal added, mirroring CleanOverdraftService's
//  renewFacility()/deleteRenewal() pattern. createAgreement() now
//  seeds the agreement's "chapter one" row; updateAgreement() keeps
//  that chapter in sync with plain Edit saves; renewAgreement() adds
//  a brand-new dated chapter.
//
//  @layer    Service
//  @depends  FactoringCompany, FactoringAgreement,
//            FactoringAgreementTermsHistory, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\FactoringCompany;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FactoringService
{
    // ── Factoring Companies ───────────────────────────────────────

    public function paginateCompanies(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return FactoringCompany::withCount('agreements')
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($w) => $w->where('name_en', 'like', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")) // Audit L-18
            )
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();
    }

    public function createCompany(int $companyId, array $data, User $actor): FactoringCompany
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $company = FactoringCompany::create($data);
            AuditLog::record($actor, 'factoring_company.created', $company, null, $company->toArray());
            return $company;
        });
    }

    public function updateCompany(FactoringCompany $company, array $data, User $actor): FactoringCompany
    {
        return DB::transaction(function () use ($company, $data, $actor) {
            $before = $company->toArray();
            $company->update($data);
            AuditLog::record($actor, 'factoring_company.updated', $company, $before, $company->fresh()->toArray());
            return $company->fresh();
        });
    }

    public function toggleCompany(FactoringCompany $company, User $actor): FactoringCompany
    {
        return DB::transaction(function () use ($company, $actor) {
            $before = $company->toArray();
            $company->update(['is_active' => ! $company->is_active]);
            AuditLog::record($actor, 'factoring_company.toggled', $company, $before, $company->fresh()->toArray());
            return $company->fresh();
        });
    }

    // ── Factoring Agreements ──────────────────────────────────────

    public function paginateAgreements(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return FactoringAgreement::with(['factoringCompany:id,name_en', 'termsHistories'])
            ->when($filters['factoring_company_id'] ?? null, fn ($q, $id) => $q->where('factoring_company_id', $id))
            ->when($filters['agreement_type'] ?? null, fn ($q, $t) => $q->where('agreement_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function createAgreement(int $companyId, array $data, User $actor): FactoringAgreement
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $this->validateAgreementData($data);
            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $agreement = FactoringAgreement::create($data);

            // Agreement Renewal — every agreement gets its "chapter
            // one" row the moment it's created, so hasRenewals()/
            // renew() always have a baseline to work from.
            $agreement->createOriginalTermsHistory();

            AuditLog::record($actor, 'factoring_agreement.created', $agreement, null, $agreement->toArray());
            return $agreement->fresh(['factoringCompany', 'coaAccount', 'termsHistories']);
        });
    }

    public function updateAgreement(FactoringAgreement $agreement, array $data, User $actor): FactoringAgreement
    {
        return DB::transaction(function () use ($agreement, $data, $actor) {
            $this->validateAgreementData($data);
            $before = $agreement->toArray();
            $agreement->update($data);

            // Agreement Renewal — Edit always corrects whichever
            // chapter is CURRENTLY live, in place. This never
            // creates a new chapter or touches effective_date; only
            // renewAgreement() does that.
            $agreement->syncLatestTermsChapter();

            AuditLog::record($actor, 'factoring_agreement.updated', $agreement, $before, $agreement->fresh()->toArray());
            return $agreement->fresh(['factoringCompany', 'coaAccount', 'termsHistories']);
        });
    }

    public function toggleAgreement(FactoringAgreement $agreement, User $actor): FactoringAgreement
    {
        return DB::transaction(function () use ($agreement, $actor) {
            $before = $agreement->toArray();
            $newStatus = $agreement->status === 'active' ? 'inactive' : 'active';
            $agreement->update(['status' => $newStatus]);
            AuditLog::record($actor, 'factoring_agreement.toggled', $agreement, $before, $agreement->fresh()->toArray());
            return $agreement->fresh();
        });
    }

    // ── Agreement Renewal ────────────────────────────────────────

    /**
     * Records a renewal — a brand-new dated chapter. See
     * FactoringAgreement::renew() for the full rule set.
     *
     * borrowing_rate is discarded from $data whenever the agreement
     * follows the CBE Corridor Rate, so it always falls through to
     * "carry forward from the previous chapter" — same guard as
     * CleanOverdraftService::renewFacility().
     */
    public function renewAgreement(int $companyId, FactoringAgreement $agreement, array $data, User $actor): FactoringAgreement
    {
        if ($agreement->follows_corridor_rate) {
            unset($data['borrowing_rate']);
        }

        $agreement->renew($data, $actor);

        return $agreement->fresh(['factoringCompany', 'coaAccount', 'termsHistories']);
    }

    /**
     * Undoes the agreement's most recent renewal. See
     * FactoringAgreement::deleteLatestRenewal() for the full rule set.
     */
    public function deleteRenewal(int $companyId, FactoringAgreement $agreement, User $actor): FactoringAgreement
    {
        $agreement->deleteLatestRenewal($actor);

        return $agreement->fresh(['factoringCompany', 'coaAccount', 'termsHistories']);
    }

    private function validateAgreementData(array $data): void
    {
        if (($data['agreement_type'] ?? '') === 'direct' && empty($data['recourse_type'])) {
            throw new \DomainException('Direct Factoring agreements must specify recourse type (with or without recourse).');
        }
        if (($data['agreement_type'] ?? '') === 'reverse') {
            $data['recourse_type'] = null; // reverse never has recourse
        }
    }
}
