<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/LgIssuanceForm.vue

  Create form for an individual LG issuance. One component, three
  layouts — switches on issuanceType ('facility' | 'cash_cover' |
  'time_deposit') per Rule 2's spirit (one page, mode-driven), same
  as every Create/Edit pair elsewhere in the app.

  Section 1 fields differ by type (see the reference images):
    facility     → LG Facility picker, LG Type from that facility's
                   rate card (autofills terms), Cash Cover From
                   Account + separate Fees & Commission Account
    cash_cover   → no facility. Cash Cover Rate fixed at 100%. One
                   combined "Cash & Commission" account.
    time_deposit → Bank + Time Deposit Account picked directly. No
                   cash cover fields — the TD itself is blocked as
                   collateral (Mahmoud: company keeps the TD's
                   interest, just can't liquidate it while pledged).
                   Amount / TD Free to Use / Against Cash Cover shown
                   read-only IF the bank account record carries that
                   data — flagged where it doesn't (see template).

  Confirmed cross-type rules baked into this form:
    - Start Date + End Date are both user-entered; Duration (Months)
      is READ-ONLY, computed from the two dates — never the other
      way around (a bank's actual end date rarely lands on a clean
      N-month boundary).
    - Beneficiary dropdown is filtered live by LG Type: Bid Bond →
      customer or other; Final/Advanced/Performance → customer only,
      and Contract Reference / Purchase Order becomes required for
      those three (existence of an active Project or SO only — no
      delivery proof).
    - Posting Mode (New Issuance / Opening Issuance) is the one field
      that changes what happens on Save — New Issuance creates
      accounting movement rows, Opening Issuance creates none.

  @layout    AppLayout
  @route     fi.lg-issuance.create / fi.lg-issuance.edit
  @props     issuance, issuanceType, banks, currencies, lgFacilities,
             bankAccounts, defaultLgTypes, initialBeneficiaries,
             periodClosed, canSetToDraft, undoableEvent
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.lg-issuance.index')" class="zv-text-blue">{{ t('financialInstitutions.treasury.lgIssuanceForm.letterOfGuaranteeIssuance') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t(typeLabel) }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('financialInstitutions.treasury.lgIssuanceForm.editLGIssuance') : t(typeLabel) }}</h1>
        </div>

        <div v-if="parseFloat(form.lg_amount) > 0" class="lgi-summary-panel">
          <div class="lgi-summary-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGAmount') }}</div>
          <div class="lgi-summary-value">{{ selectedCurrencyCode }} {{ fmt(form.lg_amount) }}</div>
          <div class="lgi-summary-sub">{{ t('financialInstitutions.treasury.lgIssuanceForm.commission') }}: {{ fmt(computedCommissionAmount) }} · {{ t('financialInstitutions.treasury.lgIssuanceForm.duration') }}: {{ computedDuration }} {{ t('financialInstitutions.treasury.lgIssuanceForm.months') }}</div>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-4">
        {{ t('financialInstitutions.treasury.lgIssuanceForm.pleaseFixTheErrorsBelowBefore') }}
      </div>

      <!-- August 2026: an issued LG is locked while POSTED — its
           terms only change through a real lifecycle action (Renew /
           Decrease / Cancel / Liquidate) or by explicitly clicking
           "Set to Draft" below, which reverses its journal entry and
           reopens it for a genuine edit. A DRAFT issuance (only ever
           reached via Set to Draft) is fully editable, same as create. -->
      <div v-if="isEdit && isLocked" class="zv-alert zv-alert-info mb-4">
        {{ t('financialInstitutions.treasury.lgIssuanceForm.thisLGHasAlreadyBeenIssued') }}
      </div>
      <div v-else-if="isEdit && !isLocked" class="zv-alert zv-alert-warning mb-4">
        {{ t('financialInstitutions.treasury.lgIssuanceForm.thisLGIsADraftIts') }}
      </div>

      <form @submit.prevent="submit" novalidate>

      <fieldset :disabled="isLocked" style="border: 0; padding: 0; margin: 0;">

        <!-- ══════════════════ Posting Mode ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-body">
            <div class="lgi-posting-toggle">
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.posting_mode === 'new_issuance' }" @click="form.posting_mode = 'new_issuance'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.newIssuance') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lgIssuanceForm.happeningNowSystemPostsCashCover') }}</div>
              </button>
              <button type="button" class="lgi-posting-option" :class="{ 'lgi-posting-active': form.posting_mode === 'opening_issuance' }" @click="form.posting_mode = 'opening_issuance'">
                <div class="lgi-posting-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.openingIssuance') }}</div>
                <div class="lgi-posting-sub">{{ t('financialInstitutions.treasury.lgIssuanceForm.alreadyExistedBeforeVeroCFORecord') }}</div>
              </button>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 1 — LG Type ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.letterOfGuaranteeType') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-3">

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.transactionName') }}</label>
                <input v-model="form.transaction_name" type="text" class="zv-input"/>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGCurrency') }}</label>
                <select v-model="form.currency_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
              </div>

              <!-- FACILITY type: Bank first (correct flow), then LG Facility filtered to that bank -->
              <template v-if="issuanceType === 'facility'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.bank') }}</label>
                  <select v-model="form.bank_id" class="zv-select" @change="onBankChangeForFacility">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGFacility') }}</label>
                  <select v-model="form.credit_facility_id" class="zv-select" :disabled="!form.bank_id" @change="onFacilityChange">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="f in facilitiesForBank" :key="f.id" :value="f.id">{{ f.name }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGLimit') }}</label>
                  <div class="zv-readonly-field">{{ selectedFacility ? fmt(selectedFacility.facility_limit) : '—' }}</div>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.availableRoom') }}</label>
                  <div class="zv-readonly-field lgi-room-highlight">{{ roomSummary ? fmt(roomSummary.available_room) : '—' }}</div>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGType') }}</label>
                  <select v-model="form.facility_lg_type_id" class="zv-select" :disabled="!form.credit_facility_id" @change="onFacilityLgTypeChange">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="lt in selectedFacility?.lg_types ?? []" :key="lt.id" :value="lt.id">{{ t(lt.lg_type) }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGTypeOutstanding') }}</label>
                  <div class="zv-readonly-field">{{ lgTypeOutstanding }}</div>
                </div>
              </template>

              <!-- CASH_COVER type: pick bank + LG Type directly -->
              <template v-else-if="issuanceType === 'cash_cover'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.bank') }}</label>
                  <select v-model="form.bank_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGType') }}</label>
                  <select v-model="form.lg_type" class="zv-select" @change="onFreeLgTypeChange">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="type in defaultLgTypes" :key="type" :value="type">{{ t(type) }}</option>
                  </select>
                </div>
              </template>

              <!-- TIME_DEPOSIT type: pick bank + the TD account itself -->
              <template v-else-if="issuanceType === 'time_deposit'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.financialBank') }}</label>
                  <select v-model="form.bank_id" class="zv-select" @change="form.time_deposit_account_id = ''">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name_en }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.timeDepositAccount') }}</label>
                  <select v-model="form.time_deposit_account_id" class="zv-select" :disabled="!form.currency_id">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="a in tdAccountsForBank" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                  <p v-if="!form.currency_id" class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceForm.selectTheLGCurrencyFirst') }}</p>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGType') }}</label>
                  <select v-model="form.lg_type" class="zv-select" @change="onFreeLgTypeChange">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="type in defaultLgTypes" :key="type" :value="type">{{ t(type) }}</option>
                  </select>
                </div>
              </template>

              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGCode') }} <span class="field-hint">({{ t('financialInstitutions.treasury.lgIssuanceForm.bankSOwnReferenceNotGenerated') }})</span></label>
                <input v-model="form.lg_code" type="text" class="zv-input"/>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 2 — Beneficiary ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#1490A8">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.beneficiaryInformation') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-3">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.beneficiaryName') }}</label>
                <select v-model="form.beneficiary_partner_id" class="zv-select" :disabled="!form.lg_type" @change="onBeneficiaryChange">
                  <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.pleaseSelect') }}</option>
                  <option v-for="p in beneficiaries" :key="p.id" :value="p.id">{{ p.name_en }}</option>
                </select>
                <p v-if="!form.lg_type" class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceForm.selectAnLGTypeFirst') }}</p>
              </div>

              <template v-if="requiresContract">
                <div class="field-group field-group-span-2">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.contractsSalesOrders') }}</label>
                  <select v-model="selectedContractKey" class="zv-select" @change="onContractSelectionChange">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.nothingSelected') }}</option>
                    <option v-for="c in eligibleContracts" :key="c.kind + '-' + c.id" :value="c.kind + '-' + c.id">{{ c.label }}</option>
                  </select>
                  <p v-if="form.beneficiary_partner_id && eligibleContracts.length === 0" class="field-hint">
                    {{ t('financialInstitutions.treasury.lgIssuanceForm.thisCustomerHasNoActiveContract') }}
                  </p>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.purchaseOrderDate') }}</label>
                  <input v-model="form.purchase_order_date" type="date" class="zv-input"/>
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 3 — LG Information ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#b8860b">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.letterOfGuaranteeInformation') }}</div>
          </div>
          <div class="form-section-body">
            <div class="field-grid field-grid-4">

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.issuanceDate') }}</label>
                <input v-model="form.issuance_date" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.endDate') }}</label>
                <input v-model="form.end_date" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.durationMonths') }}</label>
                <div class="zv-readonly-field">{{ computedDuration }}</div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGAmount') }}</label>
                <input v-model="form.lg_amount" type="number" step="0.01" class="zv-input"/>
              </div>

              <!-- Cash cover — facility + cash_cover only, never time_deposit -->
              <template v-if="issuanceType !== 'time_deposit'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.cashCoverRate') }}</label>
                  <input v-model="form.cash_cover_pct" type="number" step="0.01" class="zv-input" :readonly="issuanceType === 'cash_cover'"/>
                </div>
                <div class="field-group">
                  <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.cashCoverAmount') }}</label>
                  <div class="zv-readonly-field">{{ fmt(computedCashCoverAmount) }}</div>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.cashCoverCurrency') }}</label>
                  <select v-model="form.cash_cover_currency_id" class="zv-select" :disabled="issuanceType === 'cash_cover'">
                    <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                  </select>
                  <p v-if="issuanceType === 'cash_cover'" class="field-hint">{{ t('financialInstitutions.treasury.lgIssuanceForm.lockedToLGCurrency100Cash') }}</p>
                </div>
              </template>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGCommissionRate') }}</label>
                <input v-model="form.commission_rate_pct" type="number" step="0.0001" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGCommissionAmount') }}</label>
                <div class="zv-readonly-field">{{ fmt(computedCommissionAmount) }}</div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.commissionFeesCurrency') }}</label>
                <select v-model="form.commission_fees_currency_id" class="zv-select">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.minLGCommissionFee') }}</label>
                <input v-model="form.min_commission_fee_amount" type="number" step="0.01" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.issuanceFees') }}</label>
                <input v-model="form.issuance_fee_amount" type="number" step="0.01" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.lGCommissionInterval') }}</label>
                <select v-model="form.commission_interval" class="zv-select">
                  <option value="monthly">{{ t('financialInstitutions.treasury.lgIssuanceForm.monthly') }}</option>
                  <option value="quarterly">{{ t('financialInstitutions.treasury.lgIssuanceForm.quarterly') }}</option>
                  <option value="semi_annual">{{ t('financialInstitutions.treasury.lgIssuanceForm.semiAnnual') }}</option>
                  <option value="annual">{{ t('financialInstitutions.treasury.lgIssuanceForm.annual') }}</option>
                </select>
              </div>

              <!-- Accounts — differ by type, now filtered by currency too -->
              <template v-if="issuanceType === 'facility'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.cashCoverFromAccount') }}</label>
                  <select v-model="form.cash_cover_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <optgroup :label="t('financialInstitutions.treasury.lgIssuanceForm.currentAccountCashDebited')">
                      <option v-for="a in accountsForCashCoverCurrent" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                    </optgroup>
                    <optgroup :label="t('financialInstitutions.treasury.lgIssuanceForm.timeDepositBlockedAsCollateralNo')">
                      <option v-for="a in accountsForCashCoverTd" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                    </optgroup>
                  </select>
                  <p v-if="selectedCashCoverAccountIsTd" class="field-hint lgi-td-hint">
                    {{ t('financialInstitutions.treasury.lgIssuanceForm.thisTimeDepositWillBeBlocked') }}
                  </p>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.feesCommissionAccount') }}</label>
                  <select v-model="form.fees_commission_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCommission" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
              </template>

              <template v-else-if="issuanceType === 'cash_cover'">
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.cashCoverAccount') }}</label>
                  <select v-model="form.cash_cover_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCashCover" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
                <div class="field-group">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.commissionAccount') }}</label>
                  <select v-model="form.fees_commission_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCommission" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
              </template>

              <template v-else-if="issuanceType === 'time_deposit'">
                <div class="field-group field-group-span-2">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.lgIssuanceForm.feesCommissionAccount') }}</label>
                  <select v-model="form.fees_commission_account_id" class="zv-select">
                    <option value="">{{ t('financialInstitutions.treasury.lgIssuanceForm.select') }}</option>
                    <option v-for="a in accountsForCommission" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                  </select>
                </div>
              </template>

            </div>
          </div>
        </div>

        </fieldset>

        <!-- ══════════════════ Comment ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-body">
            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.lgIssuanceForm.comment') }}</label>
              <textarea v-model="form.comment" class="zv-input" rows="3"></textarea>
            </div>
          </div>
        </div>

        <!-- ══════════════════ History (edit mode only) ══════════════════
             Every Renew / Decrease / Cancel / Liquidate lands here.
             "Undo" only ever shows on the single most recent
             non-undone row (undoableEvent, computed server-side) —
             correction peels back one layer at a time, in order. -->
        <div v-if="isEdit && issuance.history?.length" class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.history') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.lgIssuanceForm.everyLifecycleActionOnThisLG') }}</div>
          </div>
          <div class="form-section-body">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('financialInstitutions.treasury.lgIssuanceForm.event') }}</th><th>{{ t('financialInstitutions.treasury.lgIssuanceForm.date') }}</th><th>{{ t('financialInstitutions.treasury.lgIssuanceForm.details') }}</th>
                  <th>{{ t('financialInstitutions.treasury.lgIssuanceForm.journalEntry') }}</th><th class="cp-th-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="h in issuance.history" :key="h.id" :class="{ 'lgi-history-undone': h.undone_at }">
                  <td class="font-mono">{{ eventTypeLabel(h.event_type) }}</td>
                  <td>{{ h.event_date }}</td>
                  <td>{{ historyDetails(h) }}</td>
                  <td>{{ h.journal_entry?.entry_number ?? '—' }}</td>
                  <td class="cp-th-actions">
                    <span v-if="h.undone_at" class="lgi-undone-tag">{{ t('financialInstitutions.treasury.lgIssuanceForm.undone') }}</span>
                    <button
                      v-else-if="canUndo(h)"
                      type="button" class="zv-btn zv-btn-danger zv-btn-sm" :disabled="undoing === h.id"
                      @click="confirmUndo(h)"
                    >{{ undoing === h.id ? t('financialInstitutions.treasury.lgIssuanceForm.undoing') : t('financialInstitutions.treasury.lgIssuanceForm.undo') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ══════════════════ GL Postings (read-only, edit mode only) ══════════════════ -->
        <div v-if="isEdit && issuance.movements?.length" class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-title">{{ t('financialInstitutions.treasury.lgIssuanceForm.gLPostings') }}</div>
          </div>
          <div class="form-section-body">
            <table class="zv-table">
              <thead>
                <tr><th>{{ t('financialInstitutions.treasury.lgIssuanceForm.movement') }}</th><th>{{ t('financialInstitutions.treasury.lgIssuanceForm.date') }}</th><th class="cc-th-num">{{ t('common.amount') }}</th><th>{{ t('common.status') }}</th><th>{{ t('financialInstitutions.treasury.lgIssuanceForm.journalEntry') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="m in issuance.movements" :key="m.id">
                  <td>{{ m.description }}</td>
                  <td>{{ m.movement_date }}</td>
                  <td class="zv-table-num">{{ fmt(m.amount) }}</td>
                  <td>{{ movementStatusLabel(m.status) }}</td>
                  <td>{{ m.posted_journal_entry?.entry_number ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.lg-issuance.index')" class="zv-btn zv-btn-ghost">{{ t('financialInstitutions.treasury.lgIssuanceForm.close') }}</Link>

            <!-- Posted, pristine, period open → the reopen action -->
            <button
              v-if="isEdit && isLocked && props.canSetToDraft && !props.periodClosed"
              type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft"
            >{{ t('financialInstitutions.treasury.lgIssuanceForm.setToDraft') }}</button>

            <!-- Draft (only ever reached via Set to Draft, which
                 already reversed everything real) → the actual
                 delete action. Never shown alongside Set to Draft. -->
            <button
              v-if="isEdit && !isLocked"
              type="button" class="zv-btn zv-btn-danger" :disabled="deleting" @click="confirmDelete"
            >{{ deleting ? t('financialInstitutions.treasury.lgIssuanceForm.deleting') : t('common.delete') }}</button>

            <!-- Draft (fresh create, or a reopened one being edited) → Save -->
            <button
              v-if="!isEdit || !isLocked"
              type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary"
            >{{ form.processing ? t('common.saving') : t('financialInstitutions.treasury.lgIssuanceForm.save') }}</button>
          </div>
        </div>
      </form>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { lineVat } from '@/Utils/money'
import { Link, useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

const props = defineProps({
  issuance:             Object,
  issuanceType:         { type: String, required: true },
  banks:                { type: Array, default: () => [] },
  currencies:           { type: Array, default: () => [] },
  lgFacilities:         { type: Array, default: () => [] },
  bankAccounts:         { type: Array, default: () => [] },
  defaultLgTypes:       { type: Array, default: () => [] },
  // Fix (August 2026): the Beneficiary option list used to come only
  // from a watch() on LG Type that never fires on initial load in
  // edit mode — passed directly here instead so it's always present.
  initialBeneficiaries: { type: Array, default: () => [] },
  periodClosed:         { type: Boolean, default: false },
  canSetToDraft:        { type: Boolean, default: false },
  undoableEvent:        { type: Object, default: null },
})

const isEdit = computed(() => !!props.issuance?.id)

// posting_status is only meaningful once issued — a fresh create has
// none yet, and is never "locked". A 'draft' issuance (only reachable
// via Set to Draft) is fully editable, same as create mode.
const isLocked = computed(() => isEdit.value && props.issuance?.posting_status !== 'draft')

const selectedCurrencyCode = computed(() => props.currencies.find(c => c.id == form?.currency_id)?.code ?? 'CCY')

const typeLabel = computed(() => ({
  facility: 'LG Against LG Facility',
  cash_cover: 'LG 100% Cash Cover',
  time_deposit: 'LG Against Time Deposit',
}[props.issuanceType]))

// ── Form ──────────────────────────────────────────────────────────
const i = props.issuance ?? {}
const form = useForm({
  issuance_type: props.issuanceType,
  posting_mode: i.posting_mode ?? 'new_issuance',
  credit_facility_id: i.credit_facility_id ?? '',
  facility_lg_type_id: i.facility_lg_type_id ?? '',
  lg_type: i.lg_type ?? '',
  transaction_name: i.transaction_name ?? '',
  bank_id: i.bank_id ?? '',
  currency_id: i.currency_id ?? '',
  lg_code: i.lg_code ?? '',
  beneficiary_partner_id: i.beneficiary_partner_id ?? '',
  contract_reference_project_id: i.contract_reference_project_id ?? '',
  purchase_order_so_id: i.purchase_order_so_id ?? '',
  purchase_order_date: i.purchase_order_date ?? '',
  issuance_date: i.issuance_date ?? '',
  end_date: i.end_date ?? '',
  lg_amount: i.lg_amount ?? '',
  cash_cover_pct: i.cash_cover_pct ?? (props.issuanceType === 'cash_cover' ? 100 : ''),
  commission_rate_pct: i.commission_rate_pct ?? '',
  min_commission_fee_amount: i.min_commission_fee_amount ?? '',
  issuance_fee_amount: i.issuance_fee_amount ?? '',
  commission_interval: i.commission_interval ?? 'quarterly',
  cash_cover_account_id: i.cash_cover_account_id ?? '',
  fees_commission_account_id: i.fees_commission_account_id ?? '',
  time_deposit_account_id: i.time_deposit_account_id ?? '',
  cash_cover_currency_id: i.cash_cover_currency_id ?? '',
  commission_fees_currency_id: i.commission_fees_currency_id ?? '',
  comment: i.comment ?? '',
})

// FIX (point 7): both settlement currencies default to LG Currency
// the moment it's picked, but stay changeable afterward — EXCEPT
// cash_cover_currency_id on the 100% Cash Cover type, which is
// locked and always mirrors currency_id (enforced again below and
// server-side, not just here).
watch(() => form.currency_id, (newCurrency) => {
  if (!form.commission_fees_currency_id) form.commission_fees_currency_id = newCurrency
  if (props.issuanceType === 'cash_cover') {
    form.cash_cover_currency_id = newCurrency // always locked, every change
  } else if (!form.cash_cover_currency_id) {
    form.cash_cover_currency_id = newCurrency
  }
})

// ── FACILITY type: Bank first, then LG Facility filtered to it ────

const facilitiesForBank = computed(() => props.lgFacilities.filter(f => f.bank_id == form.bank_id))
const selectedFacility = computed(() => props.lgFacilities.find(f => f.id == form.credit_facility_id))

// Slow replies for an earlier choice must never overwrite what is on screen for the
// current one — every lookup below carries a ticket number and is dropped if outdated.
let facilityReq = 0, beneficiariesReq = 0, contractsReq = 0

function onBankChangeForFacility() {
  ++facilityReq
  form.credit_facility_id = ''
  form.facility_lg_type_id = ''
  form.lg_type = ''
  form.beneficiary_partner_id = ''
  roomSummary.value = null
}

// FIX (point 3): live Available Room + LG Type Outstanding — fetched
// from the real facilityRoomSummary endpoint, not fabricated.
const roomSummary = ref(null)
async function onFacilityChange() {
  const seq = ++facilityReq
  form.facility_lg_type_id = ''
  form.lg_type = ''
  form.beneficiary_partner_id = ''
  roomSummary.value = null
  if (!form.credit_facility_id) return
  const { data } = await axios.get(route('fi.lg-issuance.facility-room', form.credit_facility_id))
  if (seq !== facilityReq) return
  roomSummary.value = data
}

const lgTypeOutstanding = computed(() => {
  if (!roomSummary.value || !form.facility_lg_type_id) return '—'
  const raw = roomSummary.value.outstanding_by_lg_type_id?.[form.facility_lg_type_id]
  return fmt(raw ?? 0)
})

function onFacilityLgTypeChange() {
  const rate = selectedFacility.value?.lg_types?.find(lt => lt.id == form.facility_lg_type_id)
  if (!rate) return
  form.lg_type = rate.lg_type
  form.cash_cover_pct = rate.cash_cover_pct
  form.commission_rate_pct = rate.commission_rate_pct
  form.commission_interval = rate.commission_interval
  form.min_commission_fee_amount = rate.min_commission_fee_amount
  form.issuance_fee_amount = rate.issuance_fee_amount
  form.beneficiary_partner_id = ''
}

function onFreeLgTypeChange() {
  form.beneficiary_partner_id = ''
}

// ── Beneficiary rules ─────────────────────────────────────────────

const requiresContract = computed(() => ['Final LG', 'Advanced Payment LG', 'Performance LG'].includes(form.lg_type))

// Fix (August 2026): previously ref([]) — the option list only ever
// got filled by the watch() below, which does not fire on initial
// load in edit mode (the field arrives already set, it never
// "changes"), so the dropdown had no option text to show even though
// beneficiary_partner_id itself was correct. Seeding from the prop
// fixes the display; the watch below still handles genuine LG Type
// changes normally (create mode, or editing a reopened draft).
const beneficiaries = ref(props.initialBeneficiaries ?? [])
watch(() => form.lg_type, async (type) => {
  const seq = ++beneficiariesReq
  form.beneficiary_partner_id = ''
  beneficiaries.value = []
  clearContracts() // the old beneficiary's contracts no longer apply
  if (!type) return
  const { data } = await axios.get(route('fi.lg-issuance.beneficiaries', type))
  if (seq !== beneficiariesReq) return
  beneficiaries.value = data
})

// FIX (point 6): one merged "Contracts / Sales Orders" list — the
// backend already excludes SOs that belong to a Project (they're
// represented by that Project instead) and uses the correct
// partner_id column. selectedContractKey is 'project-12' or
// 'sales_order-7'; splitting it back into the two real form fields
// happens here so the backend's two-column shape doesn't change.
const eligibleContracts = ref([])
const selectedContractKey = ref('')

function clearContracts() {
  ++contractsReq
  form.contract_reference_project_id = ''
  form.purchase_order_so_id = ''
  selectedContractKey.value = ''
  eligibleContracts.value = []
}

async function onBeneficiaryChange() {
  clearContracts()
  const seq = contractsReq
  if (!form.beneficiary_partner_id || !requiresContract.value) return
  const { data } = await axios.get(route('fi.lg-issuance.contracts', form.beneficiary_partner_id))
  if (seq !== contractsReq) return
  eligibleContracts.value = data
}

function onContractSelectionChange() {
  form.contract_reference_project_id = ''
  form.purchase_order_so_id = ''
  if (!selectedContractKey.value) return
  const [kind, id] = selectedContractKey.value.split('-')
  if (kind === 'project') form.contract_reference_project_id = id
  else form.purchase_order_so_id = id
}

// ── Account filtering — by bank AND currency (point 7) ─────────────

const accountsForCashCover = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == (props.issuanceType === 'facility' ? selectedFacility.value?.bank_id : form.bank_id) &&
    a.account_type === 'current' &&
    a.currency_id == form.cash_cover_currency_id
  )
)

// Facility type ONLY (confirmed with Mahmoud): cash cover can come
// from a Current Account (real cash debit) OR a Time Deposit (blocked
// as collateral instead — no cash moves, company keeps the TD's
// interest). 100% Cash Cover is always Current; Against Time Deposit
// is always TD by definition — neither of those two gets this choice.
const accountsForCashCoverCurrent = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == selectedFacility.value?.bank_id &&
    a.account_type === 'current' &&
    a.currency_id == form.cash_cover_currency_id
  )
)
const accountsForCashCoverTd = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == selectedFacility.value?.bank_id &&
    a.account_type === 'time_deposit' &&
    a.currency_id == form.cash_cover_currency_id
  )
)
const selectedCashCoverAccountIsTd = computed(() =>
  accountsForCashCoverTd.value.some(a => a.id == form.cash_cover_account_id)
)

const accountsForCommission = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == (props.issuanceType === 'facility' ? selectedFacility.value?.bank_id : form.bank_id) &&
    a.account_type === 'current' &&
    a.currency_id == form.commission_fees_currency_id
  )
)
// FIX (point 2, TD half): TD account must match the LG Currency —
// it IS the collateral, so a mismatch makes no sense.
const tdAccountsForBank = computed(() =>
  props.bankAccounts.filter(a =>
    a.bank_id == form.bank_id && a.account_type === 'time_deposit' && a.currency_id == form.currency_id
  )
)

// When the bank or a currency changes, an account picked earlier may no longer be one of
// the choices on screen (the list shows blank) yet would still be saved — so drop it.
function dropAccountsNoLongerOffered() {
  const offered = (list, id) => !id || list.some(a => a.id == id)
  if (props.issuanceType !== 'time_deposit') {
    const cashCoverChoices = props.issuanceType === 'facility'
      ? [...accountsForCashCoverCurrent.value, ...accountsForCashCoverTd.value]
      : accountsForCashCover.value
    if (!offered(cashCoverChoices, form.cash_cover_account_id)) form.cash_cover_account_id = ''
  }
  if (!offered(accountsForCommission.value, form.fees_commission_account_id)) form.fees_commission_account_id = ''
  if (props.issuanceType === 'time_deposit' && !offered(tdAccountsForBank.value, form.time_deposit_account_id)) form.time_deposit_account_id = ''
}
watch([accountsForCashCover, accountsForCashCoverCurrent, accountsForCashCoverTd, accountsForCommission, tdAccountsForBank], dropAccountsNoLongerOffered)

// ── Computed preview values (server recalculates authoritatively) ──

// FIX (point 5): Jan 1 → Dec 31 must read 12, not 11 — inclusive
// month counting, +1 on top of the raw calendar-month difference.
// Kept in sync with LgIssuanceService::monthsBetween() server-side.
const computedDuration = computed(() => {
  if (!form.issuance_date || !form.end_date) return '—'
  const start = new Date(form.issuance_date)
  const end = new Date(form.end_date)
  const months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth()) + 1
  return Math.max(months, 0)
})

// Previews use the same exact decimal maths and cut-off rules as the server (Utils/money.js),
// so the figures on screen equal the saved ones to the cent.
const computedCashCoverAmount = computed(() => {
  if (isNaN(parseFloat(form.lg_amount)) || isNaN(parseFloat(form.cash_cover_pct))) return 0
  return Number(lineVat(form.lg_amount, form.cash_cover_pct))
})

const computedCommissionAmount = computed(() => {
  const minFee = parseFloat(form.min_commission_fee_amount)
  if (isNaN(parseFloat(form.lg_amount)) || isNaN(parseFloat(form.commission_rate_pct))) return isNaN(minFee) ? 0 : minFee
  const raw = Number(lineVat(form.lg_amount, form.commission_rate_pct))
  return Math.max(raw, isNaN(minFee) ? 0 : minFee)
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  isEdit.value
    ? form.put(route('fi.lg-issuance.update', props.issuance.id))
    : form.post(route('fi.lg-issuance.store'))
}

// ── Set to Draft / Delete (August 2026) ─────────────────────────────

function confirmSetToDraft() {
  if (! confirm(t('financialInstitutions.treasury.lgIssuanceForm.reopenThisLGForEditingIts'))) return
  router.patch(route('fi.lg-issuance.set-to-draft', props.issuance.id))
}

const deleting = ref(false)
function confirmDelete() {
  if (! confirm(t('financialInstitutions.treasury.lgIssuanceForm.deleteThisLGIssuanceThisIs'))) return
  deleting.value = true
  router.delete(route('fi.lg-issuance.destroy', props.issuance.id), {
    onFinish: () => { deleting.value = false },
  })
}

// ── Undo (per lifecycle action) ─────────────────────────────────────

const undoRouteFor = {
  renewal:  'fi.lg-issuance.undo-renewal',
  decrease: 'fi.lg-issuance.undo-decrease',
  cancel:   'fi.lg-issuance.undo-cancel',
  liquidate: 'fi.lg-issuance.undo-liquidate',
}

function canUndo(h) {
  return !h.undone_at && props.undoableEvent?.id === h.id && !props.periodClosed
}

const undoing = ref(null)
function confirmUndo(h) {
  if (! confirm(t('financialInstitutions.treasury.lgIssuanceForm.undoThisEventAnyJournalEntry', { event: eventTypeLabel(h.event_type) }))) return
  undoing.value = h.id
  router.patch(route(undoRouteFor[h.event_type], props.issuance.id), {}, {
    onFinish: () => { undoing.value = null },
  })
}

function eventTypeLabel(type) {
  return { renewal: t('financialInstitutions.treasury.lgIssuanceForm.renewal'), decrease: t('financialInstitutions.treasury.lgIssuanceForm.decrease'), cancel: t('common.cancel'), liquidate: t('financialInstitutions.treasury.lgIssuanceForm.liquidate') }[type] ?? type
}

function historyDetails(h) {
  if (h.event_type === 'renewal') {
    let parts = [`${h.old_end_date} → ${h.new_end_date}`]
    if (h.renewal_fee_amount > 0) parts.push(`${t('financialInstitutions.treasury.lgIssuanceForm.fee')} ${fmt(h.renewal_fee_amount)}`)
    if (h.new_cash_cover_pct !== null && h.new_cash_cover_pct !== undefined) {
      const direction = h.cash_cover_delta_amount >= 0 ? '+' : ''
      parts.push(`${t('financialInstitutions.treasury.lgIssuanceForm.cashCover')} ${h.old_cash_cover_pct}% → ${h.new_cash_cover_pct}% (${direction}${fmt(h.cash_cover_delta_amount)})`)
    }
    if (h.new_commission_rate_pct !== null && h.new_commission_rate_pct !== undefined) {
      parts.push(`${t('financialInstitutions.treasury.lgIssuanceForm.commission')} ${h.old_commission_rate_pct}% → ${h.new_commission_rate_pct}%`)
    }
    return parts.join(' — ')
  }
  if (h.event_type === 'decrease') return `-${fmt(h.decrease_amount)} → ${fmt(h.balance_after_event)}`
  if (h.event_type === 'liquidate') return h.shortfall_amount > 0 ? `${t('financialInstitutions.treasury.lgIssuanceForm.shortfall')}: ${fmt(h.shortfall_amount)}` : t('financialInstitutions.treasury.lgIssuanceForm.fullyCoveredByCashCover')
  return h.notes ?? '—'
}

function movementStatusLabel(status) {
  return { pending_gl_posting: t('financialInstitutions.treasury.lgIssuanceForm.pending'), posted: t('financialInstitutions.treasury.lgIssuanceForm.posted'), reversed: t('financialInstitutions.treasury.lgIssuanceForm.reversed'), memo_only: t('financialInstitutions.treasury.lgIssuanceForm.memoNoGLEntry') }[status] ?? status
}
</script>

<style>
/* ── Self-contained grid layout ──────────────────────────────────────
   BUG FIX: fields were rendering full-width, one per row, instead of
   3-4 per row. Root cause: this page relies on field-grid/field-grid-4
   classes defined in the GLOBAL app.css, and — same issue hit during
   the Contract Assignment build — a page can render before that global
   CSS is guaranteed loaded on a direct visit/refresh. Defining the
   grid rules here too (not instead of the global ones — as a safety
   copy) means this form renders correctly no matter what else has or
   hasn't loaded yet. */
.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
.field-grid-3 { grid-template-columns: repeat(3, 1fr); }
.field-grid-4 { grid-template-columns: repeat(4, 1fr); }
.field-grid-5 { grid-template-columns: repeat(5, 1fr); }
.field-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field-group-span-2 { grid-column: span 2; }
.field-group-span-3 { grid-column: span 3; }
.field-hint { font-size: 11px; color: var(--zv-text-muted); margin-top: 2px; }
.lgi-td-hint { color: #1490A8; font-weight: 500; }

.lgi-summary-panel {
  min-width: 240px; background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg); padding: 14px 18px; box-shadow: var(--zv-shadow-sm);
}
.lgi-summary-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.lgi-summary-value { font-size: 18px; font-weight: 800; color: #0F2554; font-variant-numeric: tabular-nums; }
[data-theme="dark"] .lgi-summary-value { color: #90a4c4; }
.lgi-summary-sub { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 4px; }

.lgi-room-highlight { color: #10b981 !important; font-weight: 700 !important; }

.lgi-posting-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.lgi-posting-option {
  text-align: start; padding: 14px 16px; border: 1.5px solid var(--zv-border); border-radius: var(--zv-radius-lg);
  background: var(--zv-bg-surface); cursor: pointer; transition: all 0.15s;
}
.lgi-posting-option:hover { border-color: #1490A8; }
.lgi-posting-active { border-color: #FFBF00; background: rgba(255,191,0,0.06); }
.lgi-posting-title { font-size: 14px; font-weight: 700; color: var(--zv-text-primary); margin-bottom: 4px; }
.lgi-posting-sub { font-size: 12px; color: var(--zv-text-muted); line-height: 1.4; }

.zv-readonly-field {
  padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
  background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 600; min-height: 38px;
}

.lgi-history-undone { opacity: 0.5; }
.lgi-undone-tag {
  display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: var(--zv-bg-surface-alt); color: var(--zv-text-muted);
}
</style>
