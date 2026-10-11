<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/ContractAssignmentOdForm.vue

  Create + Edit form for OD Against Contract/SO Assignment
  (facility_type = 'contract_so', COA 223003).

  Design is identical to CleanOverdraftForm — same ZAVERO premium
  form standard, same section cards, same field grids, same style
  block. Only the content differences are changed:

  What changed from CleanOverdraftForm:
    - facility_type: 'contract_so' (was 'clean_od')
    - COA account hint: 223003 (was 223001)
    - Routes: contract-od.* (was clean-od.*)
    - Page title / breadcrumb / subtitle label
    - Edit mode header: shows "N contracts assigned — Manage →"
      chip in addition to the utilization panel (assignments are
      managed from the Index page modal, not this form)

  What is identical to CleanOverdraftForm:
    - All five section cards and their layouts
    - All field grids, field groups, field hints
    - Interest rate auto-calc (borrowing + margin)
    - CBE Corridor Rate toggle card
    - Rate summary panel
    - Outstanding Breakdown table + tranche logic
    - submit() blank-row strip fix (same bug, same fix)
    - Entire <style> block — zero CSS changes

  @layout    AppLayout
  @route     fi.facilities.contract-od.create / .edit
  @props     facility, bank, currencies, coaAccounts,
             companyBaseCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <!-- ══════════════════════════════════════════════════════
           PAGE HEADER
      ══════════════════════════════════════════════════════ -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <Link :href="route('fi.facilities.contract-od.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.oDAgainstContractAssignment') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.facilities.contractAssignmentOdForm.new') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('financialInstitutions.banks.facilities.contractAssignmentOdForm.editODAgainstContractAssignment') : t('financialInstitutions.banks.facilities.contractAssignmentOdForm.newODAgainstContractAssignmentFacility') }}
          </h1>
          <p class="zv-page-subtitle">
            <span class="od-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank?.name_en }}
              <span v-if="bank?.name_ar" class="od-bank-ar">{{ bank?.name_ar }}</span>
            </span>
          </p>
        </div>

        <!-- Live utilization bar + assignment chip (edit mode only) -->
        <div v-if="isEdit" class="od-edit-header-right">
          <div v-if="parseFloat(form.facility_limit) > 0" class="od-util-panel">
            <div class="od-util-header">
              <span class="od-util-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.facilityUtilization') }}</span>
              <span class="od-util-pct" :class="utilizationClass">{{ utilizationPct }}%</span>
            </div>
            <div class="od-util-track">
              <div class="od-util-fill" :class="utilizationClass" :style="{ width: Math.min(100, utilizationPct) + '%' }"></div>
            </div>
            <div class="od-util-footer">
              <div class="od-util-stat">
                <span class="od-util-stat-val">{{ fmt(form.outstanding_balance) }}</span>
                <span class="od-util-stat-lbl">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.drawn') }}</span>
              </div>
              <div class="od-util-stat od-util-stat-right">
                <span class="od-util-stat-val">{{ fmt(availableRoom) }}</span>
                <span class="od-util-stat-lbl">{{ t('common.available') }}</span>
              </div>
            </div>
          </div>

          <!-- Assigned contracts chip — managed from Index page modal -->
          <div class="od-assign-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="od-assign-count">{{ facility?.contract_assignments?.length ?? 0 }}</span>
            <span class="od-assign-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.contractsAssigned') }}</span>
            <Link :href="route('fi.facilities.contract-od.index')" class="od-assign-link">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.manage') }}</Link>
          </div>
        </div>
      </div>

      <!-- Error banner -->
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
        <span>{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.pleaseFixTheErrorsBelowBefore') }}</span>
      </div>

      <form @submit.prevent="submit" novalidate>

        <!-- ══════════════════════════════════════════════════════
             SECTION 1 — CONTRACT MAIN INFORMATION
        ══════════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.contractMainInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.datesAccountNumberCurrencyAndCOA') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-5">

              <!-- Financial Institution — read-only -->
              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.financialInstitution') }}</label>
                <div class="zv-readonly-field">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-4 h-4 opacity-40 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                  <span>{{ bank?.name_en }}</span>
                  <span v-if="bank?.name_ar" class="zv-readonly-sub">— {{ bank?.name_ar }}</span>
                </div>
              </div>

              <!-- Contract Start Date -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.contractStartDate') }}</label>
                <input v-model="form.start_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.start_date }"/>
                <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
              </div>

              <!-- Contract End Date -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.contractEndDate') }}</label>
                <input v-model="form.review_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.review_date }"/>
                <p v-if="form.errors.review_date" class="zv-field-error">{{ form.errors.review_date }}</p>
              </div>

              <!-- Currency -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.currency') }}</label>
                <select v-model="form.currency_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.currency_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.facilities.contractAssignmentOdForm.functional') + ')' : '' }}
                  </option>
                </select>
                <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
              </div>

              <!-- Account Number -->
              <div class="field-group field-group-span-2">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.accountNumber') }}</label>
                <input v-model="form.account_number" type="text" class="zv-input font-mono"
                       :class="{ 'zv-input-error': form.errors.account_number }"
                       :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdForm.bankAccountNumberAssignedToThis')"/>
                <p v-if="form.errors.account_number" class="zv-field-error">{{ form.errors.account_number }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.eachODFacilityHasItsOwn') }}</p>
              </div>

              <!-- COA Account — 223003 and sub-accounts -->
              <div class="field-group field-group-span-3">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.cOAAccount') }}</label>
                <select v-model="form.coa_account_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.coa_account_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.selectCOAAccount') }}</option>
                  <option v-for="acc in coaAccounts" :key="acc.id" :value="acc.id">
                    {{ acc.code }} — {{ acc.name_en }}
                  </option>
                </select>
                <p v-if="form.errors.coa_account_id" class="zv-field-error">{{ form.errors.coa_account_id }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.selectAccount223003OrOneOf') }}</p>
              </div>

            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 2 — TERMS & CONDITIONS
        ══════════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#b8860b">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.termsConditions') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.limitsRatesAndSettlementConditions') }}</div>
            </div>
          </div>

          <div class="form-section-body">

            <!-- Row 1: Limit / Outstanding Balance / Balance Date -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.limit') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.facility_limit" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.facility_limit }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.facility_limit" class="zv-field-error">{{ form.errors.facility_limit }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.outstandingBalance') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.outstanding_balance" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.outstanding_balance }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.outstanding_balance" class="zv-field-error">{{ form.errors.outstanding_balance }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.currentDrawnAmountAtContractSetup') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.balanceDate') }}</label>
                <input v-model="form.balance_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.balance_date }"/>
                <p v-if="form.errors.balance_date" class="zv-field-error">{{ form.errors.balance_date }}</p>
              </div>
            </div>

            <!-- Divider: Rates -->
            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.interestRates') }}</div>

            <!-- Row 2: Borrowing Rate / Bank Margin / Interest Rate (auto) -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.borrowingRate') }}
                  <span v-if="form.follows_corridor_rate" class="od-corridor-tag">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.cBECorridor') }}</span>
                </label>
                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000" @input="calcInterestRate"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.cBECorridorRateUpdatesWhenCBE') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.bankMarginRate') }}</label>
                <input v-model="form.bank_margin_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000" @input="calcInterestRate"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.fixedBankSpreadAddedOnTop') }}</p>
              </div>

              <!-- Interest Rate — auto-calculated, read-only -->
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.interestRate') }}</label>
                <div class="od-calc-field">
                  <div class="od-calc-value">{{ interestRate }}%</div>
                  <div class="od-calc-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 11h.01M12 11h.01M15 11h.01M12 7h.01M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5z"/>
                    </svg>
                    = {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.borrowing') }} + {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.margin') }}
                  </div>
                </div>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.autoCalculatedUses360DayEgyptian') }}</p>
              </div>
            </div>

            <!-- Row 3: Min Rate / HDB Rate / Admin Fee Rate -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.minInterestRate') }}</label>
                <input v-model="form.min_interest_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.bankChargesMinimumThisEvenIf') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.highestDebitBalanceRate') }}</label>
                <input v-model="form.hdb_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.quarterlyFeeOnHighestBalanceReached') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.adminFeesRate') }}</label>
                <input v-model="form.admin_fee_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
              </div>
            </div>

            <!-- Row 4: Settled Max Days / Max Lending Limit Per Contract / Notes -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.settledMaxWithinDays') }}</label>
                <input v-model="form.settled_max_days" type="number" min="0" class="zv-input text-end"
                       placeholder="30"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.maxDaysAnySingleWithdrawalCan') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.maxLendingLimitPerContract') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.max_lending_limit_per_contract" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                </div>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.capPerAssignedContractBankNever') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.notes') }}</label>
                <input v-model="form.purpose" type="text" class="zv-input"
                       :placeholder="t('financialInstitutions.banks.facilities.contractAssignmentOdForm.additionalTermsOrConditions')"/>
              </div>
            </div>

            <!-- Divider: CBE Corridor Rate -->
            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.cBECorridorRateSettings') }}</div>

            <!-- Corridor Rate Toggle -->
            <div class="od-toggle-card">
              <div class="od-toggle-info">
                <div class="od-toggle-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.linkToCBECorridorRateUpdates') }}</div>
                <div class="od-toggle-desc">
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.whenEnabledTheBorrowingRateOn') }}
                </div>
              </div>
              <button type="button"
                      class="od-toggle-switch"
                      :class="{ 'od-toggle-on': form.follows_corridor_rate }"
                      @click="form.follows_corridor_rate = !form.follows_corridor_rate">
                <span class="od-toggle-knob"></span>
              </button>
            </div>

            <!-- Rate summary panel -->
            <div v-if="showRateSummary" class="od-rate-summary mt-4">
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.allInRate') }}</div>
                <div class="od-rate-value od-rate-value--main">{{ interestRate }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.minRateFloor') }}</div>
                <div class="od-rate-value">{{ form.min_interest_rate || '0' }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.hDBRate') }}</div>
                <div class="od-rate-value">{{ form.hdb_rate || '0' }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.effectiveRateIfFloor') }}</div>
                <div class="od-rate-value od-rate-value--effective">{{ effectiveRate }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.convention') }}</div>
                <div class="od-rate-value od-rate-value--note">360 {{ t('common.days') }}</div>
              </div>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 3 — OUTSTANDING BREAKDOWN
             Only shown when outstanding_balance > 0
        ══════════════════════════════════════════════════════ -->
        <Transition name="section-slide">
          <div v-if="hasOutstandingBalance" class="form-section mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:#1490A8">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
              </div>
              <div class="flex-1">
                <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.outstandingBreakdown') }}</div>
                <div class="form-section-sub">
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.breakDownTheExistingDrawnBalance') }}
                </div>
              </div>
              <!-- Validation badge -->
              <div v-if="breakdownTotal > 0" class="od-breakdown-badge"
                   :class="breakdownBalanced ? 'od-bd-badge-ok' : 'od-bd-badge-warn'">
                <svg v-if="breakdownBalanced" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3.5 h-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01"/>
                </svg>
                <span>{{ breakdownBalanced ? t('financialInstitutions.banks.facilities.contractAssignmentOdForm.balanced') : t('financialInstitutions.banks.facilities.contractAssignmentOdForm.mismatch') }}</span>
              </div>
            </div>

            <div class="form-section-body">

              <!-- Mismatch alert -->
              <div v-if="breakdownTotal > 0 && !breakdownBalanced" class="zv-alert zv-alert-warning mb-4">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span>
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.breakdownTotal') }} <strong>{{ fmt(breakdownTotal) }}</strong>
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.differsFromOutstandingBalance') }} <strong>{{ fmt(form.outstanding_balance) }}</strong>
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.by') }} <strong>{{ fmt(Math.abs(breakdownTotal - parseFloat(form.outstanding_balance || 0))) }}</strong>
                </span>
              </div>

              <!-- Table header -->
              <div class="od-bd-table">
                <div class="od-bd-header">
                  <div class="od-bd-col-amount">{{ t('common.amount') }}</div>
                  <div class="od-bd-col-date">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.settlementDueDate') }}</div>
                  <div class="od-bd-col-days" v-if="form.settled_max_days">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.daysUntilDue') }}</div>
                  <div class="od-bd-col-action"></div>
                </div>

                <!-- Breakdown rows -->
                <div v-for="(row, idx) in form.breakdown" :key="idx"
                     class="od-bd-row" :class="{ 'od-bd-row-overdue': isRowOverdue(row) }">

                  <div class="od-bd-col-amount">
                    <div class="zv-input-group">
                      <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                      <input v-model="row.amount" type="number" step="0.01" min="0.01"
                             class="zv-input zv-input-has-prefix text-end"
                             placeholder="0.00" @input="recalcBreakdown"/>
                    </div>
                  </div>

                  <div class="od-bd-col-date">
                    <input v-model="row.due_date" type="date" class="zv-input"/>
                  </div>

                  <div v-if="form.settled_max_days" class="od-bd-col-days">
                    <span v-if="row.due_date" class="od-days-chip"
                          :class="daysChipClass(row.due_date)">
                      {{ daysLabel(row.due_date) }}
                    </span>
                  </div>

                  <div class="od-bd-col-action">
                    <button v-if="idx > 0" type="button" class="od-remove-btn"
                            @click="removeBreakdownRow(idx)" :title="t('financialInstitutions.banks.facilities.contractAssignmentOdForm.remove')">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </div>
                </div>

                <!-- Footer: Add + Total -->
                <div class="od-bd-footer">
                  <button type="button" class="od-add-row-btn" @click="addBreakdownRow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.addTranche') }}
                  </button>
                  <div v-if="breakdownTotal > 0" class="od-bd-total">
                    <span class="od-bd-total-label">{{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.total') }}</span>
                    <span class="od-bd-total-val" :class="breakdownBalanced ? 'od-total-ok' : 'od-total-warn'">
                      {{ fmt(breakdownTotal) }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- Info callout -->
              <div class="od-callout mt-4">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="text-sm">
                  {{ t('financialInstitutions.banks.facilities.contractAssignmentOdForm.theseAreExistingWithdrawalsBeforeVero') }}
                </span>
              </div>

            </div>
          </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════
             FORM ACTIONS
        ══════════════════════════════════════════════════════ -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.facilities.contract-od.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.facilities.contractAssignmentOdForm.createODAgainstContractAssignment')) }}
            </button>
          </div>
        </div>

      </form>
    </div>
</template>

<script setup>
import { sum as sumMoney } from '@/Utils/money'
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  facility:              Object,
  bank:                  Object,
  currencies:            Array,   // company active currencies [{id, code, name_en}]
  coaAccounts:           Array,   // 223003 + sub-accounts [{id, code, name_en}]
  companyBaseCurrencyId: Number,  // the company's functional currency id
})

const isEdit = computed(() => !!props.facility?.id)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  facility_type:         'contract_so',
  bank_id:               props.bank?.id ?? '',

  // Section 1 — dates cast by Laravel as ISO strings; slice to YYYY-MM-DD for input[type=date]
  start_date:            (props.facility?.start_date  ?? '').toString().slice(0, 10),
  review_date:           (props.facility?.review_date ?? '').toString().slice(0, 10),
  account_number:        props.facility?.account_number  ?? '',
  currency_id:           props.facility?.currency_id     ?? props.companyBaseCurrencyId ?? '',
  coa_account_id:        props.facility?.coa_account_id  ?? '',

  // Section 2
  facility_limit:        props.facility?.facility_limit      ?? '',
  outstanding_balance:   props.facility?.outstanding_balance ?? '',
  balance_date:          (props.facility?.balance_date ?? '').toString().slice(0, 10),
  borrowing_rate:        props.facility?.borrowing_rate      ?? '',
  bank_margin_rate:      props.facility?.bank_margin_rate    ?? '',
  interest_rate:         props.facility?.interest_rate       ?? '',
  min_interest_rate:     props.facility?.min_interest_rate   ?? '',
  hdb_rate:              props.facility?.hdb_rate            ?? '',
  admin_fee_rate:        props.facility?.admin_fee_rate      ?? '',
  settled_max_days:      props.facility?.settled_max_days    ?? '',
  max_lending_limit_per_contract: props.facility?.max_lending_limit_per_contract ?? '',
  purpose:               props.facility?.purpose             ?? '',
  follows_corridor_rate: props.facility?.follows_corridor_rate ?? false,

  // Section 3
  breakdown: props.facility?.withdrawals?.map(w => ({
    amount:   w.amount   ?? '',
    due_date: (w.due_date ?? '').toString().slice(0, 10),
  })) ?? [{ amount: '', due_date: '' }],
})

// ── Computed ──────────────────────────────────────────────────────

const interestRate = computed(() => {
  const b = parseFloat(form.borrowing_rate  || 0)
  const m = parseFloat(form.bank_margin_rate || 0)
  return (b + m).toFixed(4)
})

const effectiveRate = computed(() => {
  const allIn = parseFloat(interestRate.value)
  const floor = parseFloat(form.min_interest_rate || 0)
  return Math.max(allIn, floor).toFixed(4)
})

const showRateSummary = computed(() =>
  parseFloat(form.borrowing_rate || 0) > 0 || parseFloat(form.bank_margin_rate || 0) > 0
)

const hasOutstandingBalance = computed(() =>
  parseFloat(form.outstanding_balance || 0) > 0
)

const availableRoom = computed(() => {
  const limit = parseFloat(form.facility_limit      || 0)
  const drawn = parseFloat(form.outstanding_balance || 0)
  return Math.max(0, limit - drawn).toFixed(2)
})

const utilizationPct = computed(() => {
  const limit = parseFloat(form.facility_limit      || 0)
  const drawn = parseFloat(form.outstanding_balance || 0)
  if (!limit) return 0
  return Math.round((drawn / limit) * 100)
})

const utilizationClass = computed(() => {
  const p = utilizationPct.value
  if (p >= 90) return 'od-danger'
  if (p >= 75) return 'od-warn'
  return 'od-ok'
})

const selectedCurrencyCode = computed(() => {
  const c = (props.currencies ?? []).find(c => c.id == form.currency_id)
  return c?.code ?? 'CCY'
})

const companyBaseCurrencyId = computed(() => props.companyBaseCurrencyId)

// Breakdown
const breakdownTotal = computed(() =>
  Number(sumMoney(form.breakdown.map(row => row.amount))) // Audit L6: exact decimal sum
)

const breakdownBalanced = computed(() => {
  const ob    = parseFloat(form.outstanding_balance || 0)
  const total = breakdownTotal.value
  if (!ob && !total) return true
  return Math.abs(total - ob) < 0.01
})

// ── Methods ───────────────────────────────────────────────────────

function calcInterestRate() {
  form.interest_rate = interestRate.value
}

function addBreakdownRow() {
  form.breakdown.push({ amount: '', due_date: '' })
}

function removeBreakdownRow(idx) {
  form.breakdown.splice(idx, 1)
}

function recalcBreakdown() { /* reactive */ }

function isRowOverdue(row) {
  if (!row.due_date) return false
  return new Date(row.due_date) < new Date()
}

function daysLabel(dateStr) {
  const diff = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
  if (diff < 0)  return t('common.overdue')
  if (diff === 0) return t('financialInstitutions.banks.facilities.contractAssignmentOdForm.dueToday')
  return diff + ' ' + t('common.days')
}

function daysChipClass(dateStr) {
  const diff = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24))
  if (diff < 0)  return 'od-chip-overdue'
  if (diff <= 7) return 'od-chip-urgent'
  if (diff <= 30) return 'od-chip-warn'
  return 'od-chip-ok'
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

form.transform(data => ({
  ...data,
  breakdown: (data.breakdown ?? []).filter(row => row.amount !== '' || row.due_date !== ''),
}))

function submit() {
  form.interest_rate = interestRate.value

  // BUG FIX: when outstanding_balance is 0, Section 3 (Outstanding
  // Breakdown) never renders — but form.breakdown still holds its
  // default placeholder row ({ amount: '', due_date: '' }) because
  // it's never been cleared. The backend's required_with:breakdown
  // rule then fails on that blank row, with no visible field to
  // show the error on, since the section is hidden. Stripping any
  // fully-blank rows here (regardless of cause) prevents that —
  // and also guards against a stray blank row left behind if the
  // user adds a tranche and removes its values.
  // (empty rows are left out through form.transform — screen rows stay untouched)

  if (isEdit.value) {
    form.put(route('fi.facilities.contract-od.update', props.facility.id))
  } else {
    form.post(route('fi.facilities.contract-od.store'))
  }
}
</script>

<style>
/* ── Page header ─────────────────────────────────────────────────── */
.od-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
}
[data-theme="dark"] .od-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.od-bank-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Utilization panel ───────────────────────────────────────────── */
.od-util-panel {
  min-width: 260px; background: var(--zv-bg-card);
  border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg);
  padding: 14px 18px; box-shadow: var(--zv-shadow-sm);
}
.od-util-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.od-util-label  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); }
.od-util-pct    { font-size: 15px; font-weight: 800; }
.od-util-track  { height: 7px; background: var(--zv-border); border-radius: 4px; overflow: hidden; margin-bottom: 8px; }
.od-util-fill   { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
.od-util-footer { display: flex; justify-content: space-between; }
.od-util-stat { display: flex; flex-direction: column; }
.od-util-stat-right { text-align: end; }
.od-util-stat-val { font-size: 14px; font-weight: 700; color: var(--zv-text-primary); }
.od-util-stat-lbl { font-size: 11px; color: var(--zv-text-muted); }

.od-ok     .od-util-fill, .od-ok.od-util-pct     { background: #10b981; color: #10b981; }
.od-warn   .od-util-fill, .od-warn.od-util-pct   { background: #f59e0b; color: #f59e0b; }
.od-danger .od-util-fill, .od-danger.od-util-pct { background: #ef4444; color: #ef4444; }

/* ── Alert ───────────────────────────────────────────────────────── */
.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger  { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

/* ── Form section (mirrors Partners form) ────────────────────────── */
.form-section {
  background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); overflow: hidden;
}
.form-section-header {
  display: flex; align-items: center; gap: 1rem;
  padding: 1.125rem 1.375rem;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
}
.form-section-icon {
  width: 36px; height: 36px; border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.form-section-title { font-size: 0.9375rem; font-weight: 600; color: var(--zv-text-primary); display: flex; align-items: center; gap: 0.375rem; }
.form-section-sub   { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.form-section-body  { padding: 1.375rem; }

/* ── Field grids ─────────────────────────────────────────────────── */
.field-grid   { display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-grid-5 { grid-template-columns: repeat(5, 1fr); }
.field-group  { display: flex; flex-direction: column; gap: 5px; }
.field-group-span-2 { grid-column: span 2; }
.field-group-span-3 { grid-column: span 3; }
.field-hint { font-size: 0.6875rem; color: var(--zv-text-muted); font-style: italic; }
.field-divider {
  display: flex; align-items: center; gap: 0.75rem;
  font-size: 0.75rem; font-weight: 700; color: var(--zv-text-muted);
  text-transform: uppercase; letter-spacing: 0.08em;
}
.field-divider::before, .field-divider::after { content: ''; flex: 1; height: 1px; background: var(--zv-border); }

/* ── Read-only field ─────────────────────────────────────────────── */
.zv-readonly-field {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
  background: var(--zv-bg-surface-alt); color: var(--zv-text-primary);
  font-size: 13px; font-weight: 500; min-height: 38px;
}
.zv-readonly-sub { color: var(--zv-text-muted); font-size: 12px; font-weight: 400; }

/* ── Auto-calculated interest rate field ─────────────────────────── */
.od-calc-field {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; border: 1px solid rgba(184,134,11,0.3); border-radius: var(--zv-radius);
  background: rgba(255,191,0,0.04); min-height: 38px;
}
.od-calc-value { font-size: 18px; font-weight: 800; color: #b8860b; font-variant-numeric: tabular-nums; }
.od-calc-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 12px;
  background: rgba(184,134,11,0.1); color: #b8860b;
  font-size: 11px; font-weight: 600;
}

/* ── Corridor Rate Tag ───────────────────────────────────────────── */
.od-corridor-tag {
  display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 10px;
  background: rgba(20,144,168,0.1); color: #1490A8;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
  margin-inline-start: 6px;
}

/* ── Corridor Rate Toggle Card ───────────────────────────────────── */
.od-toggle-card {
  display: flex; align-items: center; justify-content: space-between; gap: 20px;
  padding: 16px 18px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg);
  background: var(--zv-bg-surface);
}
.od-toggle-info { flex: 1; }
.od-toggle-title { font-size: 13.5px; font-weight: 600; color: var(--zv-text-primary); margin-bottom: 4px; }
.od-toggle-desc  { font-size: 12px; color: var(--zv-text-muted); line-height: 1.5; }
.od-toggle-switch {
  width: 48px; height: 26px; border-radius: 13px; background: var(--zv-border);
  border: none; cursor: pointer; position: relative; transition: background 0.2s; flex-shrink: 0; padding: 0;
}
.od-toggle-on { background: #1490A8; }
.od-toggle-knob {
  position: absolute; top: 3px; left: 3px; width: 20px; height: 20px;
  border-radius: 50%; background: #fff; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.od-toggle-on .od-toggle-knob { left: 25px; }

/* ── Rate summary ────────────────────────────────────────────────── */
.od-rate-summary {
  display: flex; align-items: stretch;
  border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden;
}
.od-rate-item { flex: 1; padding: 14px 16px; }
.od-rate-divider { width: 1px; background: var(--zv-border); }
.od-rate-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 5px; }
.od-rate-value { font-size: 17px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--zv-text-primary); }
.od-rate-value--main      { color: #0F2554; }
.od-rate-value--effective { color: #10b981; }
.od-rate-value--note      { font-size: 14px; color: #1490A8; }
[data-theme="dark"] .od-rate-value--main { color: #90a4c4; }

/* ── Breakdown table ─────────────────────────────────────────────── */
.od-breakdown-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700;
}
.od-bd-badge-ok   { background: rgba(16,185,129,0.1); color: #10b981; }
.od-bd-badge-warn { background: rgba(245,158,11,0.1); color: #f59e0b; }

.od-bd-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.od-bd-header {
  display: grid; grid-template-columns: 1fr 1fr 120px 40px;
  padding: 9px 14px; gap: 10px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-muted);
}
.od-bd-header:not(:has(.od-bd-col-days)) { grid-template-columns: 1fr 1fr 40px; }

.od-bd-row {
  display: grid; grid-template-columns: 1fr 1fr 120px 40px;
  padding: 8px 14px; gap: 10px; align-items: center;
  border-bottom: 1px solid var(--zv-border); transition: background 0.1s;
}
.od-bd-row:last-of-type { border-bottom: none; }
.od-bd-row:hover { background: var(--zv-bg-surface-alt); }
.od-bd-row-overdue { background: rgba(239,68,68,0.03); }

.od-bd-col-amount, .od-bd-col-date, .od-bd-col-days, .od-bd-col-action { display: flex; align-items: center; }
.od-bd-col-action { justify-content: center; }

.od-days-chip { padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.od-chip-ok      { background: rgba(16,185,129,0.1); color: #10b981; }
.od-chip-warn    { background: rgba(245,158,11,0.1); color: #f59e0b; }
.od-chip-urgent  { background: rgba(239,68,68,0.1); color: #ef4444; }
.od-chip-overdue { background: rgba(239,68,68,0.15); color: #dc2626; font-weight: 800; }

.od-remove-btn {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
  color: var(--zv-text-muted); transition: all 0.12s;
}
.od-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.od-bd-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-top: 1px dashed var(--zv-border);
  background: var(--zv-bg-surface);
}
.od-add-row-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border: 1px dashed var(--zv-border);
  border-radius: var(--zv-radius); background: transparent;
  color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.od-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

.od-bd-total { display: flex; align-items: center; gap: 12px; }
.od-bd-total-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); }
.od-bd-total-val   { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
.od-total-ok   { color: #10b981; }
.od-total-warn { color: #f59e0b; }

/* ── Info callout ────────────────────────────────────────────────── */
.od-callout {
  display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px;
  background: rgba(20,144,168,0.05); border: 1px solid rgba(20,144,168,0.15);
  border-radius: var(--zv-radius-md); color: #1490A8;
}

/* ── Card footer ─────────────────────────────────────────────────── */
.zv-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); }
.zv-card-footer { padding: 1rem 1.375rem; border-top: 1px solid var(--zv-border); }

/* ── Section slide transition ────────────────────────────────────── */
.section-slide-enter-active { transition: all 0.25s ease; overflow: hidden; }
.section-slide-leave-active { transition: all 0.20s ease; overflow: hidden; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-8px); max-height: 0; }
.section-slide-enter-to, .section-slide-leave-from { opacity: 1; transform: translateY(0); max-height: 2000px; }

/* ── Edit mode header right column ──────────────────────────────── */
/* Wraps utilization panel + assignment chip in edit mode */
.od-edit-header-right {
  display: flex; flex-direction: column; align-items: flex-end; gap: 10px;
}

/* ── Assignment count chip ──────────────────────────────────────── */
/* Shows number of assigned contracts, links back to Index modal */
.od-assign-chip {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 14px; border-radius: 20px;
  background: rgba(20,144,168,0.07); border: 1px solid rgba(20,144,168,0.2);
  font-size: 13px; color: var(--zv-text-secondary);
}
.od-assign-count { font-size: 16px; font-weight: 800; color: #1490A8; font-variant-numeric: tabular-nums; }
.od-assign-label { color: var(--zv-text-secondary); }
.od-assign-link  { color: #1490A8; font-weight: 600; text-decoration: none; margin-inline-start: 4px; }
.od-assign-link:hover { text-decoration: underline; }

</style>