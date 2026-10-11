<?php

namespace App\Models\FinancialInstitutions;


// ══════════════════════════════════════════════════════════════════
//  CreditFacility
//
//  Credit facility contracts — terms only (Layer 1C).
//  Transactions (drawdowns, repayments, charges) activated in Layer 6.
//
//  OD-specific fields added in migration 000052:
//    account_number, outstanding_balance, balance_date,
//    borrowing_rate, bank_margin_rate, min_interest_rate,
//    hdb_rate, admin_fee_rate, settled_max_days
//
//  v1.1 — max_lending_limit_per_customer added (migration 000055)
//    for OD Against Commercial Paper (facility_type =
//    'commercial_paper'). A single contract-level cap, not a
//    per-partner table — see CommercialPaperLendingTier for the
//    rate card this facility type also uses.
//
//  v1.2 — contractAssignments() relationship added (migration 000057)
//    for OD Against Contract/SO Assignment (facility_type =
//    'contract_so'). One facility → many FacilityContractAssignment
//    rows. Each assignment carries its own lending_pct and
//    max_lending_limit (per-contract cap, not per-facility).
//    The old single collateral_project_id / collateral_so_id columns
//    remain on this table and are harmless — they are kept for
//    potential use by other facility types (LG, LC) in future.
//
//  v1.3 — lgTypes() relationship added (migration 000064) for
//    Letter of Guarantee facilities (facility_type = 'letter_guarantee').
//    One facility (agreement) → many FacilityLgType rows (rate card
//    by LG type: Bid Bond / Final LG / Advanced Payment LG /
//    Performance LG / custom). Individual LG issuances (beneficiary,
//    amount, expiry — one agreement → many issuances) live on
//    lg_issuances, fully built. coa_account_id also made nullable
//    (migration 000065) — LG agreements don't post to a user-picked
//    COA account.
//
//  v1.7 — migration 000080 dropped lg_beneficiary, lg_beneficiary_ar,
//    lg_margin_pct, lg_cash_cover_account_id, lc_supplier_id,
//    lc_shipment_date, lc_settlement_terms, lc_cash_cover_account_id.
//    These were built for the old single-instrument LG/LC model.
//    LG's replacement (lg_issuances.beneficiary_partner_id,
//    cash_cover_pct, cash_cover_amount, cash_cover_account_id) is
//    live. LC's equivalent will be designed fresh when LC Issuance
//    is built, not by resurrecting these — confirmed with Mahmoud.
//    The lcSupplier() relationship below is removed accordingly.
//
//  v1.5 — lcTypes() relationship added (migration 000071) for
//    Letter of Credit facilities (facility_type = 'import_lc').
//    Same agreement + rate-card pattern as LG, minus a Commission
//    Interval column (confirmed genuinely absent from LC's rate
//    card). New lc_security_type (unsecured / fully_secured) and
//    4 lc_td_* columns (migration 000072) support Fully Secured
//    LC's pledged-Time-Deposit collateral, where facility_limit is
//    COMPUTED (lc_td_amount × lc_td_lending_pct ÷ 100) rather than
//    typed in directly. LC's "Financing Terms & Conditions" reuses
//    the existing OD-era rate columns (borrowing_rate,
//    bank_margin_rate, interest_rate, min_interest_rate, hdb_rate,
//    follows_corridor_rate) unchanged — a bank-funded LC gap is the
//    same revolving, interest-bearing structure as Clean OD.
//    account_number / outstanding_balance / balance_date /
//    settled_max_days are NOT used at the Agreement level for LC —
//    confirmed these belong to a future LC Issuance layer
//    (New Issuance / Opening Issuance, same pattern as LG).
//
//  v1.6 — Medium Term Loan support added (migrations 000077-000079).
//    entry_type / installment_interval added generically to this
//    table (not MTL-exclusive columns, but only MTL uses them today).
//    mtlScheduleLines() relationship added — NO auto-amortization,
//    confirmed: the schedule is entered manually or via Excel
//    upload, same mechanism as Leasing's cheque schedule. Individual
//    settlements (many per schedule line — partial payments are a
//    real, confirmed business case) live in a separate
//    MtlSettlement model, not on this table.
//    principal_amount and first_payment_date (added speculatively
//    in an earlier session) remain UNUSED by MTL as built — flagged
//    to Mahmoud, not silently repurposed. facility_limit covers the
//    loan principal role; there is no separate first-payment-date
//    concept once the schedule is entered directly.
//
//  interest_rate = borrowing_rate + bank_margin_rate (auto-calculated,
//  stored for historical reference — see CleanOverdraftService).
//
//  OD Against Contract/SO (facility_type = contract_so):
//    Collateral stored in facility_contract_assignments table.
//    Validated by ContractAssignmentOdService::addAssignment() —
//    Rule 30: collateral must be active, end_date > review_date.
//
//  v1.8 — Facility Renewal (OD family: clean_od, commercial_paper,
//    contract_so). Ported from CashVero's "terms history / chapters"
//    design, rebuilt to Vero CFO's standard (PHP service methods,
//    bcmath, one shared table, real audit_log rows — never a
//    database trigger). See migration 2026_01_01_000134 for the
//    full design rationale, and CreditFacilityTermsHistory for the
//    chapter row itself.
//
//    Confirmed with Mahmoud, differs from CashVero's original design:
//    Edit is NEVER locked by the existence of a renewal — it always
//    corrects whichever chapter is CURRENTLY live, in place, no new
//    date. Only renew() ever inserts a new dated chapter. Deletion:
//    a facility still on its Original chapter can be deleted outright
//    if no real money has moved through it; once renewed, only the
//    MOST RECENT renewal can be undone (deleteLatestRenewal()) — the
//    facility itself can't be deleted until it's back down to
//    Original.
//
//  @layer    Model
//  @depends  Bank, BankAccount, Project, SalesOrder, ChartOfAccount,
//            Partner, FacilityWithdrawal, FacilityLiabilityCharge,
//            FacilityDailyBalance, FacilityContractAssignment,
//            CommercialPaperLendingTier, CreditFacilityTermsHistory
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\FinancialInstitutions\FacilityContractAssignment;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\FinancialInstitutions\FacilityLcType;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Models\FinancialInstitutions\CreditFacilityTermsHistory;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Traits\SerializesDateOnly;

class CreditFacility extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'credit_facilities';

    protected $fillable = [
        'company_id',
        'bank_id',
        'facility_reference',
        'name',                 // NEW — migration 000065. Agreement label, e.g. "LG Contract Year May 2024"
        'facility_type',
        'coa_account_id',
        'bank_account_id',
        'currency_id',

        // Core facility fields
        'facility_limit',
        'interest_rate',
        'start_date',
        'review_date',
        'expiry_date',
        'purpose',

        // OD-specific fields (migration 000052)
        'account_number',
        'outstanding_balance',
        'balance_date',
        'borrowing_rate',
        'bank_margin_rate',
        'min_interest_rate',
        'hdb_rate',
        'admin_fee_rate',
        'settled_max_days',
        'follows_corridor_rate',
        'entry_type',            // NEW — migration 000077, MTL's New/Existing
        'installment_interval',  // NEW — migration 000077, MTL's payment interval
        'max_lending_limit_per_customer',   // Commercial Paper — migration 000055
        'max_lending_limit_per_contract',   // OD Contract SO — migration 000059

        // OD Against Contract/SO collateral (Rule 30)
        'collateral_type',
        'collateral_project_id',
        'collateral_so_id',
        'collateral_status',

        // MTL specific
        'principal_amount',
        'tenure_months',
        'first_payment_date',

        // LC specific — migration 000072
        'lc_security_type',
        'lc_td_account_id',
        'lc_td_currency_id',
        'lc_td_amount',
        'lc_td_interest_rate',
        'lc_td_lending_pct',

        // Fully Secured OD specific — migration 000158/000160
        // (secured against a pledged Time Deposit — same shape as
        // LC's fully_secured fields above, fsod_ prefix instead of
        // lc_. facility_type = 'fully_secured'.)
        'fsod_td_account_id',
        'fsod_td_currency_id',
        'fsod_td_amount',
        'fsod_td_interest_rate',
        'fsod_td_lending_pct',

        // Cheque discounting specific
        'cheque_recourse',

        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'facility_limit'                 => 'decimal:2',
        'interest_rate'                  => 'decimal:4',
        'borrowing_rate'                 => 'decimal:4',
        'bank_margin_rate'               => 'decimal:4',
        'min_interest_rate'              => 'decimal:4',
        'hdb_rate'                       => 'decimal:4',
        'admin_fee_rate'                 => 'decimal:4',
        'outstanding_balance'            => 'decimal:2',
        'max_lending_limit_per_customer' => 'decimal:2',
        'max_lending_limit_per_contract' => 'decimal:2',
        'principal_amount'               => 'decimal:2',
        'lc_td_amount'                   => 'decimal:2',
        'lc_td_interest_rate'            => 'decimal:4',
        'lc_td_lending_pct'              => 'decimal:2',
        'fsod_td_amount'                 => 'decimal:2',
        'fsod_td_interest_rate'          => 'decimal:4',
        'fsod_td_lending_pct'            => 'decimal:2',
        'follows_corridor_rate'          => 'boolean',
        'start_date'                     => 'date',
        'review_date'                    => 'date',
        'expiry_date'                    => 'date',
        'balance_date'                   => 'date',
        'first_payment_date'             => 'date',
        'settled_max_days'               => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function collateralProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'collateral_project_id');
    }

    public function collateralSo(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'collateral_so_id');
    }

    // ── OD relationships ──────────────────────────────────────────

    public function withdrawals(): HasMany
    {
        return $this->hasMany(FacilityWithdrawal::class, 'credit_facility_id');
    }

    public function liabilityCharges(): HasMany
    {
        return $this->hasMany(FacilityLiabilityCharge::class, 'credit_facility_id');
    }

    public function dailyBalances(): HasMany
    {
        return $this->hasMany(FacilityDailyBalance::class, 'credit_facility_id');
    }

    /**
     * Rate card for OD Against Commercial Paper facilities only.
     * Returns EVERY chapter's tiers — ordered by due_within_days
     * ascending. Since Facility Renewal (migration 000135), tiers are
     * tagged to a specific chapter; use currentLendingTiers() when
     * you want only the ones actually in force today.
     */
    public function lendingTiers(): HasMany
    {
        return $this->hasMany(CommercialPaperLendingTier::class, 'credit_facility_id')
                    ->orderBy('due_within_days');
    }

    /**
     * Only the tiers belonging to the CURRENTLY LIVE chapter (the
     * Original if never renewed, or the latest renewal otherwise).
     * This is what Create/Edit reads and replaces — Renew is the
     * only action that ever adds a tier set tagged to a brand new
     * chapter, leaving older ones frozen.
     */
    public function currentLendingTiers(): HasMany
    {
        $latestId = $this->getLatestTerms()?->id;

        return $this->lendingTiers()->where('credit_facility_terms_history_id', $latestId);
    }

    /**
     * Assigned contracts/SOs used as collateral for this
     * OD Against Contract Assignment facility (facility_type = contract_so).
     * One facility → many assignments. Each carries its own
     * lending_pct and max_lending_limit (per-contract bank cap).
     */
    public function contractAssignments(): HasMany
    {
        return $this->hasMany(FacilityContractAssignment::class, 'credit_facility_id');
    }

    /**
     * Rate card for Letter of Guarantee facilities only
     * (facility_type = 'letter_guarantee'). One facility → many
     * LG types, each with its own cash cover %, commission terms,
     * and fees. Ordered by sort_order so the default 4 types
     * (Bid Bond, Final LG, Advanced Payment LG, Performance LG)
     * display first, with any custom types the user added after.
     */
    public function lgTypes(): HasMany
    {
        return $this->hasMany(FacilityLgType::class, 'credit_facility_id')
                    ->orderBy('sort_order');
    }

    /**
     * Rate card for Letter of Credit facilities only
     * (facility_type = 'import_lc'). Same pattern as lgTypes() —
     * one facility → many LC types (Sight LC / Deferred / Cash
     * Against Document / custom), no commission_interval (confirmed
     * absent from LC's rate card, unlike LG's periodic billing).
     */
    public function lcTypes(): HasMany
    {
        return $this->hasMany(FacilityLcType::class, 'credit_facility_id')
                    ->orderBy('sort_order');
    }

    /**
     * The pledged Time Deposit backing a Fully Secured LC facility
     * 100% — only set when lc_security_type = 'fully_secured'.
     */
    public function lcTdAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'lc_td_account_id');
    }

    public function lcTdCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'lc_td_currency_id');
    }

    /**
     * The pledged Time Deposit backing a Fully Secured Overdraft
     * (facility_type = 'fully_secured') — same shape as lcTdAccount()
     * above. Always required for this facility type, unlike LC's
     * version which only applies when lc_security_type =
     * 'fully_secured' (Fully Secured OD has no "unsecured" variant —
     * being secured by the TD is the entire point of this facility
     * type; an unsecured revolving line is what Clean OD already is).
     */
    public function fsodTdAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'fsod_td_account_id');
    }

    public function fsodTdCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'fsod_td_currency_id');
    }

    /**
     * Manually-entered/uploaded Loan Schedule for Medium Term Loan
     * facilities only (facility_type = 'mtl'). No auto-calculation —
     * see MtlScheduleLine / migration 000078 header. Individual
     * settlements (many per schedule line) live on MtlSettlement,
     * reached via each line, not directly from this facility.
     */
    public function mtlScheduleLines(): HasMany
    {
        return $this->hasMany(MtlScheduleLine::class, 'credit_facility_id')
                    ->orderBy('sort_order');
    }

    /**
     * Facility Renewal — the dated timeline of terms ("chapters") for
     * OD-family facilities. Ordered oldest-first so the Original
     * chapter is always index 0 and the latest is always last().
     */
    public function termsHistories(): HasMany
    {
        return $this->hasMany(CreditFacilityTermsHistory::class, 'credit_facility_id')
                    ->orderBy('effective_date')
                    ->orderBy('id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCleanOd(Builder $query): Builder
    {
        return $query->where('facility_type', 'clean_od');
    }

    public function scopeCollateralExpired(Builder $query): Builder
    {
        return $query->where('facility_type', 'contract_so')
                     ->where('collateral_status', 'expired');
    }

    /**
     * Every facility type the Facility Settlement Engine (Rule 34)
     * applies to — same list as isOdType(), as a query scope so
     * callers (Internal Money Transfer's facility picker, the charge
     * posting screen, settlement history) don't repeat the array.
     */
    public function scopeOdFamily(Builder $query): Builder
    {
        return $query->whereIn('facility_type', ['clean_od', 'commercial_paper', 'contract_so', 'fully_secured']);
    }

    /**
     * Facilities a payment can be made FROM.
     *
     * TRS-002 (2026-09) — this is odFamily PLUS a Medium-Term Loan
     * that has not been consumed yet. The bank does not hand an MTL
     * over as cash; it pays the company's suppliers directly out of
     * it, so the loan is a payment source exactly like an overdraft.
     *
     * Deliberately NOT folded into odFamily(). That scope also drives
     * daily interest accrual and the bank-statement screens, and an
     * MTL must never accrue daily interest: its instalment already
     * bundles the interest, so charging it again would count it twice.
     * The two lists overlap; they are not the same question.
     *
     * entry_type 'existing' is excluded: that loan was drawn and spent
     * before this system and has nothing left to draw.
     */
    public function scopePayableSource(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereIn('facility_type', ['clean_od', 'commercial_paper', 'contract_so', 'fully_secured'])
              ->orWhere(fn ($m) => $m->where('facility_type', 'mtl')->where('entry_type', 'new'));
        });
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isOdContractSo(): bool     { return $this->facility_type === 'contract_so'; }
    public function isLetterOfGuarantee(): bool { return $this->facility_type === 'letter_guarantee'; }
    public function isLetterOfCredit(): bool    { return $this->facility_type === 'import_lc'; }
    public function isMtl(): bool               { return $this->facility_type === 'mtl'; }
    public function isCleanOd(): bool           { return $this->facility_type === 'clean_od'; }
    public function isFullySecuredOd(): bool    { return $this->facility_type === 'fully_secured'; }

    /**
     * Fully Secured LC — the LC facility's own equivalent of Fully
     * Secured OD, distinguished by lc_security_type rather than a
     * separate facility_type (LC's "unsecured" variant IS Import LC
     * as it always existed; "fully_secured" is the pledged-TD variant
     * added in migration 000072).
     */
    public function isFullySecuredLc(): bool
    {
        return $this->isLetterOfCredit() && $this->lc_security_type === 'fully_secured';
    }

    /** New/Existing — currently only meaningful for MTL and Leasing, see migration 000077. */
    public function isNewEntry(): bool { return $this->entry_type === 'new'; }
    public function isExistingEntry(): bool { return $this->entry_type === 'existing'; }

    public function isOdType(): bool
    {
        return in_array($this->facility_type, ['clean_od', 'commercial_paper', 'contract_so', 'fully_secured']);
    }

    /** Available room = limit - outstanding balance */
    public function availableRoom(): string
    {
        return bcsub(
            (string) $this->facility_limit,
            (string) $this->outstanding_balance,
            2
        );
    }

    /** Utilization percentage */
    public function utilizationPct(): string
    {
        if (bccomp((string) $this->facility_limit, '0', 2) === 0) {
            return '0.00';
        }
        return bcmul(
            bcdiv((string) $this->outstanding_balance, (string) $this->facility_limit, 6),
            '100',
            2
        );
    }

    public function facilityTypeLabel(): string
    {
        return match($this->facility_type) {
            'clean_od'         => 'Clean Overdraft',
            'commercial_paper' => 'OD Against Commercial Paper',
            'contract_so'      => 'OD Against Contract / SO',
            'cheque_discount'  => 'Cheque Discounting',
            'import_lc'        => 'Import Letter of Credit',
            'letter_guarantee' => 'Letter of Guarantee',
            'mtl'              => 'Medium Term Loan',
            'stl'              => 'Short Term Loan',
            'fully_secured'    => 'Fully Secured Overdraft',
            default            => $this->facility_type,
        };
    }

    // ── Facility Renewal (OD family) ────────────────────────────────

    /**
     * True once this facility has been renewed at least once (more
     * than just its Original chapter). Does NOT lock Edit (confirmed
     * with Mahmoud — differs from CashVero's first design) — it's
     * used to decide whether the whole facility can be deleted
     * outright, and whether the "Delete Renewal" action has anything
     * to undo.
     */
    public function hasRenewals(): bool
    {
        return $this->termsHistories()->count() > 1;
    }

    /**
     * The chapter currently in force — the Original if never
     * renewed, or the most recent renewal otherwise. This is what
     * Edit corrects in place.
     */
    public function getLatestTerms(): ?CreditFacilityTermsHistory
    {
        return $this->termsHistories()->reorder('effective_date', 'desc')->orderByDesc('id')->first();
    }

    /**
     * The single source of truth for "what were this facility's terms
     * on a given date" — will be used by the Settlement Engine once
     * it exists, so a transaction dated before a renewal correctly
     * resolves against the OLD terms. A plain PHP method, never a
     * database trigger (CashVero's original mechanism, rejected per
     * the gap-analysis conclusion).
     */
    public function getTermsAsOfDate(string $date): ?CreditFacilityTermsHistory
    {
        return $this->termsHistories()
            ->where('effective_date', '<=', $date)
            ->reorder('effective_date', 'desc')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Real money already tied to this facility — the gate for both
     * "can the whole facility be deleted" and "can the latest renewal
     * be undone". Deliberately does NOT check facility_daily_balances:
     * every OD facility gets a daily-balance snapshot row the moment
     * balance_date is set (even a freshly-opened, zero-balance
     * facility), so its mere existence isn't evidence of real money
     * having moved — checking it here would make deletion impossible
     * immediately after creation. A nonzero outstanding_balance, a
     * real withdrawal row, or a posted liability charge are the
     * actual signals.
     */
    /**
     * AUDIT M-23 — once withdrawals or settlements exist, the
     * outstanding balance is their result. A facility edit may repeat
     * the current figure, but it may not type a different one.
     */
    public function assertOutstandingFollowsMovements(array $data): void
    {
        if (! array_key_exists('outstanding_balance', $data)) {
            return;
        }

        $incoming = bcadd((string) $data['outstanding_balance'], '0', 2);
        $current  = bcadd((string) $this->outstanding_balance, '0', 2);
        if (bccomp($incoming, $current, 2) === 0) {
            return;
        }

        $hasMovements = $this->withdrawals()->exists()
            || $this->liabilityCharges()->exists()
            || FacilitySettlement::where('credit_facility_id', $this->id)->exists();

        if ($hasMovements) {
            throw new \DomainException(
                'The outstanding balance follows withdrawals and settlements. Change it with one of those, not by editing the facility.'
            );
        }
    }

    public function hasAnyTransactions(): bool
    {
        if (bccomp((string) $this->outstanding_balance, '0', 2) > 0) {
            return true;
        }

        if ($this->withdrawals()->exists()) {
            return true;
        }

        return $this->liabilityCharges()->where('status', 'posted')->exists();
    }

    /** Whole-facility delete is only ever allowed on the Original chapter, and only if nothing real has moved. */
    public function canBeDeleted(): bool
    {
        return ! $this->hasRenewals() && ! $this->hasAnyTransactions();
    }

    /**
     * Defensive safety net — mirrors CashVero's own fix for the same
     * gap. A facility should always get its "chapter one" row the
     * moment it's created (see the OD services' create() methods),
     * but if one somehow reaches renew()/syncLatestTermsChapter()
     * with zero chapters (an older facility from before this feature
     * existed and missed the migration backfill for some reason, or
     * a row created through an import path), this backfills it right
     * here rather than letting hasRenewals()/getLatestTerms() break.
     */
    public function createOriginalTermsHistory(): CreditFacilityTermsHistory
    {
        return $this->termsHistories()->create([
            'company_id'                      => $this->company_id,
            'effective_date'                   => $this->start_date,
            'facility_limit'                   => $this->facility_limit,
            'borrowing_rate'                   => $this->borrowing_rate,
            'bank_margin_rate'                 => $this->bank_margin_rate,
            'min_interest_rate'                 => $this->min_interest_rate,
            'hdb_rate'                          => $this->hdb_rate,
            'admin_fee_rate'                    => $this->admin_fee_rate,
            'settled_max_days'                  => $this->settled_max_days,
            'max_lending_limit_per_customer'    => $this->max_lending_limit_per_customer,
            'max_lending_limit_per_contract'    => $this->max_lending_limit_per_contract,
            'fsod_td_amount'                     => $this->fsod_td_amount,
            'fsod_td_lending_pct'                => $this->fsod_td_lending_pct,
            'lc_td_amount'                       => $this->lc_td_amount,
            'lc_td_lending_pct'                  => $this->lc_td_lending_pct,
            'review_date'                       => $this->review_date,
            'is_original'                       => true,
            'notes'                              => 'Original facility terms.',
        ]);
    }

    /**
     * Corrects the CURRENTLY LIVE chapter's numbers in place — called
     * by the OD services' update() after every Edit save. Never
     * touches effective_date (for the Original chapter that's the
     * facility's true start date, already correct; for a renewal
     * chapter it's the renewal's own date and has nothing to do with
     * a plain Edit — only renew()/deleteLatestRenewal() ever move it).
     */
    public function syncLatestTermsChapter(): void
    {
        $latest = $this->getLatestTerms() ?? $this->createOriginalTermsHistory();

        $latest->update([
            'facility_limit'                 => $this->facility_limit,
            'borrowing_rate'                 => $this->borrowing_rate,
            'bank_margin_rate'               => $this->bank_margin_rate,
            'min_interest_rate'               => $this->min_interest_rate,
            'hdb_rate'                        => $this->hdb_rate,
            'admin_fee_rate'                  => $this->admin_fee_rate,
            'settled_max_days'                => $this->settled_max_days,
            'max_lending_limit_per_customer'  => $this->max_lending_limit_per_customer,
            'max_lending_limit_per_contract'  => $this->max_lending_limit_per_contract,
            'fsod_td_amount'                   => $this->fsod_td_amount,
            'fsod_td_lending_pct'              => $this->fsod_td_lending_pct,
            'lc_td_amount'                     => $this->lc_td_amount,
            'lc_td_lending_pct'                => $this->lc_td_lending_pct,
            'review_date'                      => $this->review_date,
        ]);
    }

    /**
     * Records a renewal: a brand-new dated chapter. Anything left
     * null in $newTerms simply carries forward the previous chapter's
     * value — the user only enters what actually changed. review_date
     * is the one field that can never carry forward (a renewal must
     * always state its own new review date) — enforced by the Request,
     * not here.
     *
     * borrowing_rate is deliberately excluded from $newTerms by the
     * caller whenever follows_corridor_rate is true — see
     * RenewCleanOverdraftRequest — so it always falls through to
     * "carry forward from previous chapter", which in practice means
     * "whatever the CBE Corridor Rate cascade last set it to".
     */
    public function renew(array $newTerms, User $actor): CreditFacilityTermsHistory
    {
        if ($this->termsHistories()->count() === 0) {
            $this->createOriginalTermsHistory();
        }

        $previous = $this->getLatestTerms();

        if ($previous && $newTerms['effective_date'] <= $previous->effective_date) {
            throw new \DomainException(
                "A renewal date must be after the facility's most recent chapter date ({$previous->effective_date->format('d-m-Y')})."
            );
        }

        $currentReviewDate = $previous?->review_date ?: $this->review_date;
        if ($currentReviewDate && $newTerms['effective_date'] <= $currentReviewDate) {
            throw new \DomainException(
                "A renewal date must be after the facility's current review date ({$currentReviewDate->format('d-m-Y')})."
            );
        }

        if (empty($newTerms['review_date'])) {
            throw new \DomainException('A renewal must include a new review date.');
        }

        $before = $this->toArray();

        $chapter = $this->termsHistories()->create([
            'company_id'                      => $this->company_id,
            'effective_date'                   => $newTerms['effective_date'],
            'facility_limit'                   => $newTerms['facility_limit']                 ?? $previous?->facility_limit                 ?? $this->facility_limit,
            'borrowing_rate'                   => $newTerms['borrowing_rate']                 ?? $previous?->borrowing_rate                 ?? $this->borrowing_rate,
            'bank_margin_rate'                 => $newTerms['bank_margin_rate']               ?? $previous?->bank_margin_rate               ?? $this->bank_margin_rate,
            'min_interest_rate'                 => $newTerms['min_interest_rate']               ?? $previous?->min_interest_rate               ?? $this->min_interest_rate,
            'hdb_rate'                          => $newTerms['hdb_rate']                        ?? $previous?->hdb_rate                        ?? $this->hdb_rate,
            'admin_fee_rate'                    => $newTerms['admin_fee_rate']                  ?? $previous?->admin_fee_rate                  ?? $this->admin_fee_rate,
            'settled_max_days'                  => $newTerms['settled_max_days']                ?? $previous?->settled_max_days                ?? $this->settled_max_days,
            'max_lending_limit_per_customer'    => $newTerms['max_lending_limit_per_customer']  ?? $previous?->max_lending_limit_per_customer  ?? $this->max_lending_limit_per_customer,
            'max_lending_limit_per_contract'    => $newTerms['max_lending_limit_per_contract']  ?? $previous?->max_lending_limit_per_contract  ?? $this->max_lending_limit_per_contract,
            'fsod_td_amount'                     => $newTerms['fsod_td_amount']                  ?? $previous?->fsod_td_amount                  ?? $this->fsod_td_amount,
            'fsod_td_lending_pct'                => $newTerms['fsod_td_lending_pct']              ?? $previous?->fsod_td_lending_pct              ?? $this->fsod_td_lending_pct,
            'lc_td_amount'                       => $newTerms['lc_td_amount']                    ?? $previous?->lc_td_amount                    ?? $this->lc_td_amount,
            'lc_td_lending_pct'                  => $newTerms['lc_td_lending_pct']                ?? $previous?->lc_td_lending_pct                ?? $this->lc_td_lending_pct,
            'review_date'                       => $newTerms['review_date'],
            'notes'                              => $newTerms['notes'] ?? null,
            'is_original'                        => false,
            'created_by'                          => $actor->id,
        ]);

        // Keep the convenience columns on credit_facilities itself in
        // sync with "the latest chapter" — existing code (dashboards,
        // KPI stats, the facility's own interest_rate) reads these
        // columns directly for present-day figures.
        //
        // Fully Secured OD / Fully Secured LC: facility_limit is NOT
        // carried straight from $chapter->facility_limit here the way
        // every other type is — it's recomputed from THIS chapter's
        // own TD amount and percentage (TD Amount × Lending % ÷ 100),
        // same formula FullySecuredOverdraftService / LetterOfCredit
        // Service use at create()/update() time. This guards against
        // a chapter ever being created with a facility_limit that
        // doesn't actually match its own td_amount / td_lending_pct
        // (e.g. if only one of the two was submitted on a renewal and
        // the other carried forward — the stored facility_limit must
        // always reflect the pairing that's actually live in THIS
        // chapter, not whatever the previous chapter's limit happened
        // to be).
        $limit = match (true) {
            $this->isFullySecuredOd() => bcdiv(bcmul((string) $chapter->fsod_td_amount, (string) $chapter->fsod_td_lending_pct, 4), '100', 2),
            $this->isFullySecuredLc() => bcdiv(bcmul((string) $chapter->lc_td_amount, (string) $chapter->lc_td_lending_pct, 4), '100', 2),
            default => $chapter->facility_limit,
        };

        $this->update([
            'facility_limit'                   => $limit,
            'borrowing_rate'                   => $chapter->borrowing_rate,
            'bank_margin_rate'                 => $chapter->bank_margin_rate,
            'min_interest_rate'                 => $chapter->min_interest_rate,
            'interest_rate'                     => bcadd((string) $chapter->borrowing_rate, (string) $chapter->bank_margin_rate, 4),
            'hdb_rate'                           => $chapter->hdb_rate,
            'admin_fee_rate'                     => $chapter->admin_fee_rate,
            'settled_max_days'                   => $chapter->settled_max_days,
            'max_lending_limit_per_customer'     => $chapter->max_lending_limit_per_customer,
            'max_lending_limit_per_contract'     => $chapter->max_lending_limit_per_contract,
            'fsod_td_amount'                      => $chapter->fsod_td_amount,
            'fsod_td_lending_pct'                 => $chapter->fsod_td_lending_pct,
            'lc_td_amount'                        => $chapter->lc_td_amount,
            'lc_td_lending_pct'                   => $chapter->lc_td_lending_pct,
            'review_date'                         => $chapter->review_date,
        ]);

        AuditLog::record($actor, 'credit_facility.renewed', $this, $before, $this->fresh()->toArray());

        return $chapter;
    }

    /**
     * Undoes the most recent renewal only — never an earlier one out
     * of order, since the chapter right after it would be left with
     * no valid "previous chapter" to make sense against. Blocked if
     * any real transaction is dated on/after that renewal's effective
     * date. On success, the facility's convenience columns revert to
     * whatever the next-most-recent chapter says — which becomes
     * "Original" again if that was the only renewal.
     */
    public function deleteLatestRenewal(User $actor): void
    {
        $latest = $this->getLatestTerms();

        if (! $latest || $latest->is_original) {
            throw new \DomainException('There is no renewal to delete — this facility is still on its original terms.');
        }

        // Deliberately narrower than hasAnyTransactions() (which is the
        // whole-facility-delete gate): removing this renewal should
        // only be blocked by transactions dated on/after ITS OWN
        // effective date — anything before it belongs to an earlier
        // chapter and isn't affected by undoing this one.
        $blockingTransactionsExist = $this->withdrawals()
                ->where('withdrawal_date', '>=', $latest->effective_date)
                ->exists()
            || $this->liabilityCharges()
                ->where('status', 'posted')
                ->where('charge_date', '>=', $latest->effective_date)
                ->exists();

        if ($blockingTransactionsExist) {
            throw new \DomainException(
                "This renewal cannot be deleted because there are transactions dated on or after its effective date ({$latest->effective_date->format('d-m-Y')}). Remove those first."
            );
        }

        $before = $this->toArray();

        $latest->delete();

        $newLatest = $this->getLatestTerms();

        // Recompute the limit for Fully Secured OD / Fully Secured LC
        // the same way renew() does — the chapter's own facility_limit
        // column is a raw carried-forward value for these two types,
        // not the TD-derived figure, so it must be recomputed here too
        // rather than trusted directly (same reasoning as renew()'s
        // own $limit computation above).
        $limit = match (true) {
            $this->isFullySecuredOd() => bcdiv(bcmul((string) $newLatest->fsod_td_amount, (string) $newLatest->fsod_td_lending_pct, 4), '100', 2),
            $this->isFullySecuredLc() => bcdiv(bcmul((string) $newLatest->lc_td_amount, (string) $newLatest->lc_td_lending_pct, 4), '100', 2),
            default => $newLatest->facility_limit,
        };

        $this->update([
            'facility_limit'                   => $limit,
            'borrowing_rate'                   => $newLatest->borrowing_rate,
            'bank_margin_rate'                 => $newLatest->bank_margin_rate,
            'min_interest_rate'                 => $newLatest->min_interest_rate,
            'interest_rate'                     => bcadd((string) $newLatest->borrowing_rate, (string) $newLatest->bank_margin_rate, 4),
            'hdb_rate'                           => $newLatest->hdb_rate,
            'admin_fee_rate'                     => $newLatest->admin_fee_rate,
            'settled_max_days'                   => $newLatest->settled_max_days,
            'max_lending_limit_per_customer'     => $newLatest->max_lending_limit_per_customer,
            'max_lending_limit_per_contract'     => $newLatest->max_lending_limit_per_contract,
            'fsod_td_amount'                      => $newLatest->fsod_td_amount,
            'fsod_td_lending_pct'                 => $newLatest->fsod_td_lending_pct,
            'lc_td_amount'                        => $newLatest->lc_td_amount,
            'lc_td_lending_pct'                   => $newLatest->lc_td_lending_pct,
            'review_date'                         => $newLatest->review_date,
        ]);

        // NOTE: audit_log.action is capped at 30 chars — 'renewal_deleted' pushed
        // this over the limit (31 chars), hence 'renewal_undo' instead.
        AuditLog::record($actor, 'credit_facility.renewal_undo', $this, $before, $this->fresh()->toArray());
    }
}