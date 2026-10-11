<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftForm.vue

  Create + Edit form for OD Against Commercial Paper facility
  contracts. Design matches the ZAVERO premium form standard —
  same look as CleanOverdraftForm.vue, same product.

  Sections:
    1. Contract Main Information
    2. Terms & Conditions (rates, limits, interest auto-calc,
       max lending limit per customer)
    3. Lending Information — the rate card: "a cheque due within
       N days is lent at X% of its face value." Always visible
       (not gated behind outstanding_balance, unlike Clean
       Overdraft's breakdown) and always required — at least one
       tier, since without one the system has no rate to apply
       once a cheque is eventually deposited.

  Deliberately NOT in this form (confirmed with the business
  owner before building):
    - No "Outstanding Breakdown" section. This facility doesn't
      track individual cheques yet — that's a later build (the
      money-transaction feature). There's nothing to break the
      opening balance into here.
    - No "Settled Max Days" — that existed on Clean Overdraft
      because withdrawals there have no due date of their own.
      Every commercial paper already has a real due date, so
      there's nothing for that setting to do here.

  Key behaviours:
    - Bank name read-only (bank selected before this form)
    - Currency: company active currencies, functional currency pre-selected
    - COA Account: 223002 and its sub-accounts only
    - Interest Rate = Borrowing Rate + Bank Margin Rate (auto-calc, read-only)
    - Corridor Rate toggle: does this facility follow CBE Corridor Rate updates?
    - Lending Information: add/remove tier rows; warns client-side
      if two tiers claim the same "due within" day count (the
      database also rejects this, but with a clearer message here)

  @layout    AppLayout
  @route     fi.facilities.commercial-paper-od.create / fi.facilities.commercial-paper-od.edit
  @props     facility, bank, currencies, coaAccounts, companyBaseCurrencyId
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
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <Link :href="route('fi.facilities.commercial-paper-od.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.oDAgainstCommercialPaper') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.new') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.editODAgainstCommercialPaper') : t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.newODAgainstCommercialPaperFacility') }}
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

        <!-- Live utilization bar (edit mode only) -->
        <div v-if="isEdit && parseFloat(form.facility_limit) > 0" class="od-util-panel">
          <div class="od-util-header">
            <span class="od-util-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.facilityUtilization') }}</span>
            <span class="od-util-pct" :class="utilizationClass">{{ utilizationPct }}%</span>
          </div>
          <div class="od-util-track">
            <div class="od-util-fill" :class="utilizationClass" :style="{ width: Math.min(100, utilizationPct) + '%' }"></div>
          </div>
          <div class="od-util-footer">
            <div class="od-util-stat">
              <span class="od-util-stat-val">{{ fmt(form.outstanding_balance) }}</span>
              <span class="od-util-stat-lbl">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.drawn') }}</span>
            </div>
            <div class="od-util-stat od-util-stat-right">
              <span class="od-util-stat-val">{{ fmt(availableRoom) }}</span>
              <span class="od-util-stat-lbl">{{ t('common.available') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Error banner -->
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
        <span>{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.pleaseFixTheErrorsBelowBefore') }}</span>
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
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.contractMainInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.datesAccountNumberCurrencyAndCOA') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-5">

              <!-- Financial Institution — read-only -->
              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.financialInstitution') }}</label>
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
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.contractStartDate') }}</label>
                <input v-model="form.start_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.start_date }"/>
                <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
              </div>

              <!-- Contract End Date -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.contractEndDate') }}</label>
                <input v-model="form.review_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.review_date }"/>
                <p v-if="form.errors.review_date" class="zv-field-error">{{ form.errors.review_date }}</p>
              </div>

              <!-- Currency -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.currency') }}</label>
                <select v-model="form.currency_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.currency_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.functional') + ')' : '' }}
                  </option>
                </select>
                <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
              </div>

              <!-- Account Number -->
              <div class="field-group field-group-span-2">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.accountNumber') }}</label>
                <input v-model="form.account_number" type="text" class="zv-input font-mono"
                       :class="{ 'zv-input-error': form.errors.account_number }"
                       :placeholder="t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.bankAccountNumberAssignedToThis')"/>
                <p v-if="form.errors.account_number" class="zv-field-error">{{ form.errors.account_number }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.eachODFacilityHasItsOwn') }}</p>
              </div>

              <!-- COA Account — 223002 and sub-accounts -->
              <div class="field-group field-group-span-3">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.cOAAccount') }}</label>
                <select v-model="form.coa_account_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.coa_account_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.selectCOAAccount') }}</option>
                  <option v-for="acc in coaAccounts" :key="acc.id" :value="acc.id">
                    {{ acc.code }} — {{ acc.name_en }}
                  </option>
                </select>
                <p v-if="form.errors.coa_account_id" class="zv-field-error">{{ form.errors.coa_account_id }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.selectAccount223002OrOneOf') }}</p>
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
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.termsConditions') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.limitsRatesAndSettlementConditions') }}</div>
            </div>
          </div>

          <div class="form-section-body">

            <!-- Row 1: Limit / Outstanding Balance / Balance Date -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.limit') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.facility_limit" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.facility_limit }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.facility_limit" class="zv-field-error">{{ form.errors.facility_limit }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.bankApprovedCeilingActualUsableCapacity') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.outstandingBalance') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.outstanding_balance" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.outstanding_balance }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.outstanding_balance" class="zv-field-error">{{ form.errors.outstanding_balance }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.currentDrawnAmountAtContractSetup') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.balanceDate') }}</label>
                <input v-model="form.balance_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.balance_date }"/>
                <p v-if="form.errors.balance_date" class="zv-field-error">{{ form.errors.balance_date }}</p>
              </div>
            </div>

            <!-- Divider: Rates -->
            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.interestRates') }}</div>

            <!-- Row 2: Borrowing Rate / Bank Margin / Interest Rate (auto) -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">
                  {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.borrowingRate') }}
                  <span v-if="form.follows_corridor_rate" class="od-corridor-tag">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.cBECorridor') }}</span>
                </label>
                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000" @input="calcInterestRate"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.cBECorridorRateUpdatesWhenCBE') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.bankMarginRate') }}</label>
                <input v-model="form.bank_margin_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000" @input="calcInterestRate"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.fixedBankSpreadAddedOnTop') }}</p>
              </div>

              <!-- Interest Rate — auto-calculated, read-only -->
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.interestRate') }}</label>
                <div class="od-calc-field">
                  <div class="od-calc-value">{{ interestRate }}%</div>
                  <div class="od-calc-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 11h.01M12 11h.01M15 11h.01M12 7h.01M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5z"/>
                    </svg>
                    = {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.borrowing') }} + {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.margin') }}
                  </div>
                </div>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.autoCalculatedUses360DayEgyptian') }}</p>
              </div>
            </div>

            <!-- Row 3: Min Rate / HDB Rate / Admin Fee Rate -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.minInterestRate') }}</label>
                <input v-model="form.min_interest_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.bankChargesMinimumThisEvenIf') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.highestDebitBalanceRate') }}</label>
                <input v-model="form.hdb_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.quarterlyFeeOnHighestBalanceReached') }}</p>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.adminFeesRate') }}</label>
                <input v-model="form.admin_fee_rate" type="number" step="0.0001" min="0" max="100"
                       class="zv-input" placeholder="0.0000"/>
              </div>
            </div>

            <!-- Row 4: Max Lending Limit Per Customer / Notes -->
            <div class="field-grid field-grid-3 mb-5">
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.maxLendingLimitPerCustomer') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.max_lending_limit_per_customer" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.max_lending_limit_per_customer }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.max_lending_limit_per_customer" class="zv-field-error">{{ form.errors.max_lending_limit_per_customer }}</p>
                <p class="field-hint">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.capOnTotalExposureFromAny') }}</p>
              </div>

              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.notes') }}</label>
                <input v-model="form.purpose" type="text" class="zv-input"
                       :placeholder="t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.additionalTermsOrConditions')"/>
              </div>
            </div>

            <!-- Divider: CBE Corridor Rate -->
            <div class="field-divider mb-4">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.cBECorridorRateSettings') }}</div>

            <!-- Corridor Rate Toggle -->
            <div class="od-toggle-card">
              <div class="od-toggle-info">
                <div class="od-toggle-title">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.linkToCBECorridorRateUpdates') }}</div>
                <div class="od-toggle-desc">
                  {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.whenEnabledTheBorrowingRateOn') }}
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
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.allInRate') }}</div>
                <div class="od-rate-value od-rate-value--main">{{ interestRate }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.minRateFloor') }}</div>
                <div class="od-rate-value">{{ form.min_interest_rate || '0' }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.hDBRate') }}</div>
                <div class="od-rate-value">{{ form.hdb_rate || '0' }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.effectiveRateIfFloor') }}</div>
                <div class="od-rate-value od-rate-value--effective">{{ effectiveRate }}%</div>
              </div>
              <div class="od-rate-divider"></div>
              <div class="od-rate-item">
                <div class="od-rate-label">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.convention') }}</div>
                <div class="od-rate-value od-rate-value--note">360 {{ t('common.days') }}</div>
              </div>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 3 — LENDING INFORMATION (rate card)
             Always visible — at least one tier is required
        ══════════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#1490A8">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div class="flex-1">
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.lendingInformation') }}</div>
              <div class="form-section-sub">
                {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.theRateCardAChequeDue') }}
              </div>
            </div>
          </div>

          <div class="form-section-body">

            <!-- Duplicate day-bracket warning -->
            <div v-if="duplicateTierDays.length" class="zv-alert zv-alert-warning mb-4">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
              </svg>
              <span>
                {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.twoOrMoreTiersSayDue') }} {{ duplicateTierDays.join(', ') }} {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.daysEachDayBracketCanOnly') }}
              </span>
            </div>

            <!-- Table header -->
            <div class="od-bd-table">
              <div class="od-bd-header od-bd-header-tiers">
                <div class="od-bd-col-amount">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.commercialPapersDueWithinDays') }}</div>
                <div class="od-bd-col-date">{{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.lendingRate') }}</div>
                <div class="od-bd-col-action"></div>
              </div>

              <!-- Tier rows -->
              <div v-for="(row, idx) in form.lending_tiers" :key="idx" class="od-bd-row od-bd-row-tiers">

                <div class="od-bd-col-amount">
                  <input v-model="row.due_within_days" type="number" min="1" step="1"
                         class="zv-input text-end"
                         :class="{ 'zv-input-error': form.errors['lending_tiers.' + idx + '.due_within_days'] }"
                         placeholder="30"/>
                  <p v-if="form.errors['lending_tiers.' + idx + '.due_within_days']" class="zv-field-error">
                    {{ form.errors['lending_tiers.' + idx + '.due_within_days'] }}
                  </p>
                </div>

                <div class="od-bd-col-date">
                  <div class="zv-input-group">
                    <input v-model="row.lending_rate_pct" type="number" min="0" max="100" step="0.01"
                           class="zv-input zv-input-has-suffix text-end"
                           :class="{ 'zv-input-error': form.errors['lending_tiers.' + idx + '.lending_rate_pct'] }"
                           placeholder="80"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                  <p v-if="form.errors['lending_tiers.' + idx + '.lending_rate_pct']" class="zv-field-error">
                    {{ form.errors['lending_tiers.' + idx + '.lending_rate_pct'] }}
                  </p>
                </div>

                <div class="od-bd-col-action">
                  <button v-if="form.lending_tiers.length > 1" type="button" class="od-remove-btn"
                          @click="removeTierRow(idx)" :title="t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.remove')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Footer: Add -->
              <div class="od-bd-footer">
                <button type="button" class="od-add-row-btn" @click="addTierRow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                  </svg>
                  {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.addTier') }}
                </button>
              </div>
            </div>

            <p v-if="form.errors.lending_tiers" class="zv-field-error mt-2">{{ form.errors.lending_tiers }}</p>

            <!-- Info callout -->
            <div class="od-callout mt-4">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span class="text-sm">
                {{ t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.exampleA1000000Cheque') }}
              </span>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             FORM ACTIONS
        ══════════════════════════════════════════════════════ -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.facilities.commercial-paper-od.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.facilities.commercialPaperOverdraftForm.createOverdraftFacility')) }}
            </button>
          </div>
        </div>

      </form>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  facility:              Object,
  bank:                  Object,
  currencies:            Array,   // company active currencies [{id, code, name_en, is_base}]
  coaAccounts:           Array,   // 223002 + sub-accounts [{id, code, name_en}]
  companyBaseCurrencyId: Number,  // the company's functional currency id
})

const isEdit = computed(() => !!props.facility?.id)

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  facility_type:         'commercial_paper',
  bank_id:               props.bank?.id ?? '',

  // Section 1
  start_date:            props.facility?.start_date?.toString().slice(0, 10) ?? '',
  review_date:           props.facility?.review_date?.toString().slice(0, 10) ?? '',
  account_number:        props.facility?.account_number  ?? '',
  currency_id:           props.facility?.currency_id     ?? props.companyBaseCurrencyId ?? '',
  coa_account_id:        props.facility?.coa_account_id  ?? '',

  // Section 2
  facility_limit:                  props.facility?.facility_limit                  ?? '',
  outstanding_balance:             props.facility?.outstanding_balance             ?? '',
  balance_date:          props.facility?.balance_date?.toString().slice(0, 10) ?? '',
  borrowing_rate:                  props.facility?.borrowing_rate                  ?? '',
  bank_margin_rate:                props.facility?.bank_margin_rate                ?? '',
  interest_rate:                   props.facility?.interest_rate                   ?? '',
  min_interest_rate:               props.facility?.min_interest_rate               ?? '',
  hdb_rate:                        props.facility?.hdb_rate                        ?? '',
  admin_fee_rate:                  props.facility?.admin_fee_rate                  ?? '',
  max_lending_limit_per_customer:  props.facility?.max_lending_limit_per_customer  ?? '',
  purpose:                         props.facility?.purpose                         ?? '',
  follows_corridor_rate:           props.facility?.follows_corridor_rate           ?? false,

  // Section 3 — rate card
  // NOTE: the lendingTiers() relationship on CreditFacility serializes to
  // JSON as "lending_tiers" (Laravel snake-cases relation keys on array/
  // JSON conversion), NOT "lendingTiers" — reading props.facility.lendingTiers
  // here would silently be undefined and fall through to an empty rate
  // card on Edit. Same bug class already caught once on the LG Types form —
  // see LetterOfGuaranteeForm.vue for the matching comment.
  lending_tiers: props.facility?.lending_tiers?.length
    ? props.facility.lending_tiers.map(t => ({
        due_within_days:   t.due_within_days  ?? '',
        lending_rate_pct:  t.lending_rate_pct ?? '',
      }))
    : [{ due_within_days: '', lending_rate_pct: '' }],
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

// Client-side mirror of the server's duplicate-tier check — catches
// the mistake before submit instead of after a round trip.
const duplicateTierDays = computed(() => {
  const seen  = new Set()
  const dupes = new Set()

  form.lending_tiers.forEach(row => {
    const days = parseInt(row.due_within_days)
    if (!days) return
    if (seen.has(days)) dupes.add(days)
    seen.add(days)
  })

  return [...dupes]
})

// ── Methods ───────────────────────────────────────────────────────

function calcInterestRate() {
  form.interest_rate = interestRate.value
}

function addTierRow() {
  form.lending_tiers.push({ due_within_days: '', lending_rate_pct: '' })
}

function removeTierRow(idx) {
  form.lending_tiers.splice(idx, 1)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  form.interest_rate = interestRate.value

  if (isEdit.value) {
    form.put(route('fi.facilities.commercial-paper-od.update', props.facility.id))
  } else {
    form.post(route('fi.facilities.commercial-paper-od.store'))
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

/* ── Form section (mirrors Clean Overdraft / Partners form) ──────── */
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

/* ── Lending tier table (reuses the breakdown table's visual language) ── */
.od-bd-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.od-bd-header {
  display: grid; grid-template-columns: 1fr 1fr 40px;
  padding: 9px 14px; gap: 10px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--zv-text-muted);
}

.od-bd-row {
  display: grid; grid-template-columns: 1fr 1fr 40px;
  padding: 8px 14px; gap: 10px; align-items: start;
  border-bottom: 1px solid var(--zv-border); transition: background 0.1s;
}
.od-bd-row:last-of-type { border-bottom: none; }
.od-bd-row:hover { background: var(--zv-bg-surface-alt); }

.od-bd-col-amount, .od-bd-col-date, .od-bd-col-action { display: flex; flex-direction: column; }
.od-bd-col-action { align-items: center; justify-content: center; padding-top: 2px; }

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

/* ── Info callout ────────────────────────────────────────────────── */
.od-callout {
  display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px;
  background: rgba(20,144,168,0.05); border: 1px solid rgba(20,144,168,0.15);
  border-radius: var(--zv-radius-md); color: #1490A8;
}

/* ── Card footer ─────────────────────────────────────────────────── */
.zv-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); }
.zv-card-footer { padding: 1rem 1.375rem; border-top: 1px solid var(--zv-border); }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .field-grid-3, .field-grid-5 { grid-template-columns: 1fr 1fr; }
  .field-group-span-2, .field-group-span-3 { grid-column: span 2; }
  .zv-page-header { flex-direction: column; }
  .od-util-panel { width: 100%; }
  .od-rate-summary { flex-wrap: wrap; }
}
@media (max-width: 600px) {
  .field-grid-3, .field-grid-5 { grid-template-columns: 1fr; }
  .field-group-span-2, .field-group-span-3 { grid-column: span 1; }
}
</style>
