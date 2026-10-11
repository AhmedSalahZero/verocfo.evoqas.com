<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityContractAssignment
//
//  One assigned contract/SO on an OD Against Contract Assignment
//  facility (facility_type = 'contract_so', COA 223003).
//
//  One facility → many assignments (one-to-many).
//  Each row represents the bank taking legal assignment of one
//  Project or Sales Order as collateral, with its own negotiated
//  lending percentage and hard cap.
//
//  Effective lending per assignment:
//    MIN(lending_amount, max_lending_limit)
//  where lending_amount = contract_value × lending_pct / 100.
//  Both are stored on save — lending_amount is recalculated by
//  ContractAssignmentOdService whenever lending_pct changes.
//
//  Collateral status mirrors the parent CreditFacility's
//  collateral_status — the scheduler checks all active assignments
//  and flags the facility when any assigned collateral expires.
//
//  @layer    Model
//  @depends  CreditFacility, Partner, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\Partner;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class FacilityContractAssignment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_contract_assignments';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'partner_id',
        'collateral_type',
        'collateral_project_id',
        'collateral_so_id',
        'assignment_date',
        'lending_pct',
        'lending_amount',
        // max_lending_limit removed — cap now lives on credit_facilities
        // as max_lending_limit_per_contract (migration 000059)
        'created_by',
    ];

    protected $casts = [
        'assignment_date' => 'date',
        'lending_pct'     => 'decimal:2',
        'lending_amount'  => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function facility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'credit_facility_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function collateralProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'collateral_project_id');
    }

    public function collateralSo(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'collateral_so_id');
    }

    // ── Computed helpers ──────────────────────────────────────────

    /**
     * The real lending capacity for this assignment.
     * Pass the facility's max_lending_limit_per_contract.
     * NULL cap = no restriction, full lending_amount applies.
     */
    public function effectiveLendingAmount(?string $facilityCap = null): string
    {
        if (!$facilityCap || bccomp($facilityCap, '0', 2) <= 0) {
            return (string) $this->lending_amount;
        }
        return bccomp($this->lending_amount, $facilityCap, 2) <= 0
            ? (string) $this->lending_amount
            : $facilityCap;
    }

    /**
     * Human-readable collateral label for display.
     */
    public function collateralLabel(): string
    {
        if ($this->collateral_type === 'project' && $this->collateralProject) {
            $p = $this->collateralProject;
            return ($p->contract_code ?? 'PRJ-' . $p->id) . ' — ' . $p->name_en;
        }

        if ($this->collateral_type === 'sales_order' && $this->collateralSo) {
            $so = $this->collateralSo;
            return ($so->so_code ?? 'SO-' . $so->id) . ' — ' . ($so->subject ?? '');
        }

        return '—';
    }
}