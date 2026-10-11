<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/LetterOfCreditForm.vue

  Create + Edit form for Letter of Credit facility AGREEMENTS.
  Same agreement + rate-card pattern as LetterOfGuaranteeForm.vue,
  plus two things LG doesn't have:

    1. Type (Unsecured / Fully Secured) — Fully Secured reveals a
       "CD Or TD Information" section pledging a Time Deposit as
       100% collateral; Limit becomes COMPUTED (TD Amount × TD
       Lending % ÷ 100) and read-only instead of typed directly.
    2. Financing Terms & Conditions — a bank-funded LC gap is a
       revolving, interest-bearing debt (same structure as Clean
       Overdraft), so this section reuses that exact convention:
       Borrowing Rate + Bank Margin Rate → auto-calculated Interest
       Rate, Min Interest Rate, Highest Debt Balance Rate, and the
       CBE Corridor Rate toggle (follows_corridor_rate).

  Rate card (Terms & Conditions) has 5 columns, NOT 6 — no
  Commission Interval column, confirmed genuinely absent from LC
  (unlike LG's periodic billing).

  Deliberately NOT in this form (confirmed — Issuance-layer
  concerns, same reasoning as LG): No COA Account selector, no
  account_number / outstanding_balance / balance_date /
  settled_max_days at the Agreement level.

  @layout    AppLayout
  @route     fi.facilities.lc.create / fi.facilities.lc.edit
  @props     facility, bank, currencies, timeDepositAccounts,
             defaultLcTypes, companyBaseCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <Link :href="route('fi.facilities.lc.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.letterOfCredit') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.facilities.letterOfCreditForm.new') }}</span>
          </div>
          <h1 class="zv-page-title">{{ isEdit ? t('financialInstitutions.banks.facilities.letterOfCreditForm.editLetterOfCredit') : t('financialInstitutions.banks.facilities.letterOfCreditForm.newLetterOfCreditFacility') }}</h1>
          <p class="zv-page-subtitle">
            <span class="lc-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank?.name_en }}
            </span>
          </p>
        </div>

        <div v-if="parseFloat(form.facility_limit) > 0" class="lc-limit-panel">
          <div class="lc-limit-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.facilityLimit') }}</div>
          <div class="lc-limit-value">{{ selectedCurrencyCode }} {{ fmt(form.facility_limit) }}</div>
          <div class="lc-limit-sub">{{ t(form.lc_security_type === 'fully_secured' ? 'Computed from pledged TD' : 'Manually set') }}</div>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        {{ t('financialInstitutions.banks.facilities.letterOfCreditForm.pleaseFixTheErrorsBelowBefore') }}
      </div>

      <form @submit.prevent="submit" novalidate>

        <!-- ══════════════════ Section 1 — Contract Main Information ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#0F2554">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.contractMainInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.agreementLabelSecurityTypeDatesAnd') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-5">
              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.financialInstitution') }}</label>
                <div class="zv-readonly-field">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-4 h-4 opacity-40 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                  <span>{{ bank?.name_en }}</span>
                </div>
              </div>

              <div class="field-group field-group-span-3">
                <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
                <input v-model="form.name" type="text" class="zv-input" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditForm.eGLCFacilityYear2026')"/>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.contractStartDate') }}</label>
                <input v-model="form.start_date" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.contractEndDate') }}</label>
                <input v-model="form.review_date" type="date" class="zv-input"/>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.type') }}</label>
                <select v-model="form.lc_security_type" class="zv-select">
                  <option value="unsecured">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.unsecured') }}</option>
                  <option value="fully_secured">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.fullySecured') }}</option>
                </select>
              </div>

              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.selectCurrency') }}</label>
                <select v-model="form.currency_id" class="zv-select">
                  <option value="">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.facilities.letterOfCreditForm.functional') + ')' : '' }}
                  </option>
                </select>
              </div>

              <!-- Limit: manual for Unsecured, computed+readonly for Fully Secured -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.limit') }}</label>
                <div v-if="form.lc_security_type === 'fully_secured'" class="zv-readonly-field lc-computed-limit">
                  {{ selectedCurrencyCode }} {{ fmt(computedLimit) }}
                </div>
                <div v-else class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.facility_limit" type="number" step="0.01" min="0" class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 2 — CD Or TD Information (Fully Secured only) ══════════════════ -->
        <div v-if="form.lc_security_type === 'fully_secured'" class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#1490A8">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.cDOrTDInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.thePledgedTimeDepositBackingThis') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-4">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.selectCurrency') }}</label>
                <select v-model="form.lc_td_currency_id" class="zv-select" @change="form.lc_td_account_id = ''">
                  <option value="">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.accountType') }}</label>
                <div class="zv-readonly-field">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.timeOfDepositTD') }}</div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.accountNumber') }}</label>
                <select v-model="form.lc_td_account_id" class="zv-select" :disabled="!form.lc_td_currency_id">
                  <option value="">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.select') }}</option>
                  <option v-for="a in tdAccountsForCurrency" :key="a.id" :value="a.id">{{ a.account_number }}</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('common.amount') }}</label>
                <input v-model="form.lc_td_amount" type="number" step="0.01" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.cDOrTDInterestRate') }}</label>
                <div class="zv-input-group">
                  <input v-model="form.lc_td_interest_rate" type="number" step="0.0001" min="0" class="zv-input zv-input-has-suffix"/>
                  <span class="zv-input-suffix text-xs font-semibold">%</span>
                </div>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.cDOrTDLendingPercentage') }}</label>
                <div class="zv-input-group">
                  <input v-model="form.lc_td_lending_pct" type="number" step="0.01" min="0" max="100" class="zv-input zv-input-has-suffix"/>
                  <span class="zv-input-suffix text-xs font-semibold">%</span>
                </div>
              </div>
            </div>
            <p class="lc-callout mt-3">
              {{ t('financialInstitutions.banks.facilities.letterOfCreditForm.limitIsCalculatedAutomatically') }}: {{ t('common.amount') }} × {{ t('financialInstitutions.banks.facilities.letterOfCreditForm.lendingPercentage') }} = {{ selectedCurrencyCode }} {{ fmt(computedLimit) }}
            </p>
          </div>
        </div>

        <!-- ══════════════════ Section 3 — Terms & Conditions (LC type rate card) ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#b8860b">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div class="flex-1">
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.termsConditions') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.oneRowPerLCTypeCash') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div v-if="duplicateTypes.length" class="zv-alert zv-alert-warning mb-4">
              {{ t('financialInstitutions.banks.facilities.letterOfCreditForm.thisLCTypeAppearsMoreThan') }} {{ duplicateTypes.join(', ') }}
            </div>

            <div class="lc-tier-table">
              <div class="lc-tier-header">
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.lCType') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.cashCover') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.commissionRate') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.minCommissionsFeeAmount') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.issuanceFeesAmount') }}</div>
                <div></div>
              </div>

              <div v-for="(row, idx) in form.lc_types" :key="idx" class="lc-tier-row">
                <div class="lc-tier-col">
                  <input v-model="row.lc_type" type="text" class="zv-input" :placeholder="t('financialInstitutions.banks.facilities.letterOfCreditForm.eGSightLC')"/>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.cash_cover_pct" type="number" min="0" max="100" step="0.01" class="zv-input zv-input-has-suffix text-end" placeholder="0"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.commission_rate_pct" type="number" min="0" max="100" step="0.0001" class="zv-input zv-input-has-suffix text-end" placeholder="0.0000"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                    <input v-model="row.min_commission_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                  </div>
                </div>
                <div class="lc-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                    <input v-model="row.issuance_fee_amount" type="number" min="0" step="0.01" class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                  </div>
                </div>
                <div class="lc-tier-col lc-tier-col-action">
                  <button v-if="form.lc_types.length > 1" type="button" class="lc-remove-btn" @click="form.lc_types.splice(idx, 1)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </div>

              <div class="lc-tier-footer">
                <button type="button" class="lc-add-row-btn" @click="form.lc_types.push(defaultRow())">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                  </svg>
                  {{ t('financialInstitutions.banks.facilities.letterOfCreditForm.addLCType') }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- ══════════════════ Section 4 — Financing Terms & Conditions ══════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#7c3aed">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div>
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.financingTermsConditions') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.theRevolvingInterestBearingGapThe') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-5">
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.borrowingRate') }}</label>
                <input v-model="form.borrowing_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.bankMarginRate') }}</label>
                <input v-model="form.bank_margin_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.interestRate') }}</label>
                <div class="zv-readonly-field">{{ fmt(computedInterestRate) }}%</div>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.minInterestRate') }}</label>
                <input v-model="form.min_interest_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.highestDebtBalanceRate') }}</label>
                <input v-model="form.hdb_rate" type="number" step="0.0001" min="0" class="zv-input"/>
              </div>
            </div>

            <div class="zv-toggle-row mt-4">
              <div class="zv-toggle" :class="{ 'zv-toggle-on': form.follows_corridor_rate }" @click="form.follows_corridor_rate = !form.follows_corridor_rate">
                <div class="zv-toggle-knob"></div>
              </div>
              <div>
                <div class="lc-toggle-title">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.followsCBECorridorRate') }}</div>
                <div class="lc-toggle-sub">{{ t('financialInstitutions.banks.facilities.letterOfCreditForm.whenANewCorridorRateIs') }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.facilities.lc.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.facilities.letterOfCreditForm.createLetterOfCreditFacility')) }}
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
  currencies:            Array,
  timeDepositAccounts:   Array,
  defaultLcTypes:        Array,
  companyBaseCurrencyId: Number,
})

const isEdit = computed(() => !!props.facility?.id)

function defaultRow(type = '') {
  return { lc_type: type, cash_cover_pct: '', commission_rate_pct: '', min_commission_fee_amount: '', issuance_fee_amount: '' }
}

const form = useForm({
  bank_id: props.bank?.id ?? '',
  name: props.facility?.name ?? '',
  start_date: props.facility?.start_date?.toString().slice(0, 10) ?? '',
  review_date: props.facility?.review_date?.toString().slice(0, 10) ?? '',
  currency_id: props.facility?.currency_id ?? props.companyBaseCurrencyId ?? '',
  lc_security_type: props.facility?.lc_security_type ?? 'unsecured',
  facility_limit: props.facility?.facility_limit ?? '',

  lc_td_account_id: props.facility?.lc_td_account_id ?? '',
  lc_td_currency_id: props.facility?.lc_td_currency_id ?? '',
  lc_td_amount: props.facility?.lc_td_amount ?? '',
  lc_td_interest_rate: props.facility?.lc_td_interest_rate ?? '',
  lc_td_lending_pct: props.facility?.lc_td_lending_pct ?? '',

  lc_types: props.facility?.lc_types?.length
    ? props.facility.lc_types.map(r => ({
        lc_type: r.lc_type ?? '', cash_cover_pct: r.cash_cover_pct ?? '', commission_rate_pct: r.commission_rate_pct ?? '',
        min_commission_fee_amount: r.min_commission_fee_amount ?? '', issuance_fee_amount: r.issuance_fee_amount ?? '',
      }))
    : (props.defaultLcTypes ?? []).map(type => defaultRow(type)),

  borrowing_rate: props.facility?.borrowing_rate ?? '0.00',
  bank_margin_rate: props.facility?.bank_margin_rate ?? '',
  min_interest_rate: props.facility?.min_interest_rate ?? '',
  hdb_rate: props.facility?.hdb_rate ?? '',
  follows_corridor_rate: props.facility?.follows_corridor_rate ?? false,
})

const selectedCurrencyCode = computed(() => props.currencies.find(c => c.id == form.currency_id)?.code ?? 'CCY')

const tdAccountsForCurrency = computed(() =>
  props.timeDepositAccounts.filter(a => a.bank_id == form.bank_id && a.currency_id == form.lc_td_currency_id)
)

// Computed Limit for Fully Secured — TD Amount × Lending % ÷ 100
const computedLimit = computed(() => {
  const amount = parseFloat(form.lc_td_amount)
  const pct = parseFloat(form.lc_td_lending_pct)
  if (isNaN(amount) || isNaN(pct)) return 0
  return (amount * pct) / 100
})

const computedInterestRate = computed(() => {
  const borrow = parseFloat(form.borrowing_rate)
  const margin = parseFloat(form.bank_margin_rate)
  if (isNaN(borrow) || isNaN(margin)) return 0
  return borrow + margin
})

const duplicateTypes = computed(() => {
  const seen = new Set(), dupes = new Set()
  form.lc_types.forEach(row => {
    const type = (row.lc_type || '').trim().toLowerCase()
    if (!type) return
    if (seen.has(type)) dupes.add(row.lc_type.trim())
    seen.add(type)
  })
  return [...dupes]
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  // Sync computed Limit into the submitted payload for Fully Secured —
  // server recalculates authoritatively too, this just avoids a stale
  // value flashing before the redirect.
  if (form.lc_security_type === 'fully_secured') {
    form.facility_limit = computedLimit.value.toFixed(2)
  }
  if (isEdit.value) {
    form.put(route('fi.facilities.lc.update', props.facility.id))
  } else {
    form.post(route('fi.facilities.lc.store'))
  }
}
</script>

<style>
.lc-bank-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12); font-size: 12.5px; font-weight: 600; color: #0F2554; }
[data-theme="dark"] .lc-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }

.lc-limit-panel { min-width: 220px; background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 14px 18px; box-shadow: var(--zv-shadow-sm); }
.lc-limit-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.lc-limit-value { font-size: 18px; font-weight: 800; color: #0F2554; font-variant-numeric: tabular-nums; }
[data-theme="dark"] .lc-limit-value { color: #90a4c4; }
.lc-limit-sub { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 4px; }

.zv-readonly-field { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius); background: var(--zv-bg-surface-alt); color: var(--zv-text-primary); font-size: 13px; font-weight: 500; min-height: 38px; }
.lc-computed-limit { font-weight: 700; color: #10b981; justify-content: flex-end; }

.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

.lc-callout { padding: 10px 14px; background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.2); border-radius: var(--zv-radius-md); color: #10b981; font-size: 13px; font-weight: 600; }

/* ── Rate card table (5 columns, no interval) ────────────────────── */
.lc-tier-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.lc-tier-header { display: grid; grid-template-columns: 1.6fr 1fr 1.2fr 1.4fr 1.4fr 40px; padding: 9px 14px; gap: 10px; background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--zv-text-muted); }
.lc-tier-row { display: grid; grid-template-columns: 1.6fr 1fr 1.2fr 1.4fr 1.4fr 40px; padding: 10px 14px; gap: 10px; align-items: start; border-bottom: 1px solid var(--zv-border); }
.lc-tier-row:last-of-type { border-bottom: none; }
.lc-tier-row:hover { background: var(--zv-bg-surface-alt); }
.lc-tier-col { display: flex; flex-direction: column; }
.lc-tier-col-action { align-items: center; justify-content: center; padding-top: 2px; }
.lc-remove-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer; color: var(--zv-text-muted); }
.lc-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }
.lc-tier-footer { display: flex; padding: 10px 14px; border-top: 1px dashed var(--zv-border); background: var(--zv-bg-surface); }
.lc-add-row-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px dashed var(--zv-border); border-radius: var(--zv-radius); background: transparent; color: #1490A8; font-size: 13px; cursor: pointer; }
.lc-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

/* ── CBE Corridor toggle ──────────────────────────────────────────── */
.zv-toggle-row { display: flex; align-items: flex-start; gap: 12px; }
.lc-toggle-title { font-size: 13.5px; font-weight: 600; color: var(--zv-text-primary); }
.lc-toggle-sub { font-size: 12px; color: var(--zv-text-muted); margin-top: 2px; max-width: 480px; }

.zv-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); }
.zv-card-footer { padding: 1rem 1.375rem; border-top: 1px solid var(--zv-border); }

@media (max-width: 1100px) { .lc-tier-header, .lc-tier-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 900px) { .field-grid-4, .field-grid-5 { grid-template-columns: 1fr 1fr; } .field-group-span-2, .field-group-span-3 { grid-column: span 2; } .zv-page-header { flex-direction: column; } }
@media (max-width: 600px) { .field-grid-4, .field-grid-5 { grid-template-columns: 1fr; } .lc-tier-header { display: none; } .lc-tier-row { grid-template-columns: 1fr; } }
</style>
