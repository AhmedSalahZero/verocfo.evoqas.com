<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeForm.vue

  Create + Edit form for Letter of Guarantee facility AGREEMENTS.
  Design matches the ZAVERO premium form standard — same look as
  CleanOverdraftForm.vue / CommercialPaperOverdraftForm.vue.

  This is an agreement-level form only, built from the LG Agreement
  reference image. It does NOT issue individual guarantees
  (beneficiary, amount, expiry) — that is a separate future form
  (LG Issuance), designed once Mahmoud shares that layer.

  Sections:
    1. Contract Main Information — bank (read-only), agreement
       name/label, start/end date, limit, currency
    2. Terms & Conditions — the rate card: one row per LG type
       (Bid Bond / Final LG / Advanced Payment LG / Performance LG,
       or a custom type added on top), each with its own cash
       cover %, commission rate, commission billing interval,
       minimum commission fee floor, and one-time issuance fee

  Deliberately NOT in this form (confirmed with Mahmoud before
  building):
    - No COA Account selector. LG agreements always post to the
      fixed 227/138001 pair — which specific account matters only
      at the individual issuance level, not the agreement level.
    - No Outstanding Balance / interest rate / CBE Corridor toggle.
      This facility type doesn't draw down cash the way the OD
      family does — there's nothing to settle at this level.
    - No Bank Account field — confirmed by Mahmoud this belongs on
      the future LG Issuance form (the cash cover account is
      picked per issuance, not per agreement).

  Key behaviours:
    - Bank name read-only (bank selected before this form)
    - Currency: company active currencies, functional currency pre-selected
    - New agreements pre-seed the rate card with the 4 default LG
      types (server-provided via defaultLgTypes prop) — the person
      can edit their terms, remove any, or add custom types
    - Client-side duplicate-type warning (case-insensitive), same
      pattern as CP Overdraft's duplicate-day-bracket check

  @layout    AppLayout
  @route     fi.facilities.lg.create / fi.facilities.lg.edit
  @props     facility, bank, currencies, defaultLgTypes,
             commissionIntervals, companyBaseCurrencyId
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
            <Link :href="route('fi.banks.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.banks') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <Link :href="route('fi.facilities.lg.index')" class="zv-text-blue">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.letterOfGuarantee') }}</Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('common.edit') : t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.new') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.editLetterOfGuarantee') : t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.newLetterOfGuaranteeFacility') }}
          </h1>
          <p class="zv-page-subtitle">
            <span class="lg-bank-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
              </svg>
              {{ bank?.name_en }}
              <span v-if="bank?.name_ar" class="lg-bank-ar">{{ bank?.name_ar }}</span>
            </span>
          </p>
        </div>

        <!-- Limit summary chip (edit mode only) -->
        <div v-if="isEdit && parseFloat(form.facility_limit) > 0" class="lg-limit-panel">
          <div class="lg-limit-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.facilityLimit') }}</div>
          <div class="lg-limit-value">{{ selectedCurrencyCode }} {{ fmt(form.facility_limit) }}</div>
          <div class="lg-limit-sub">{{ form.lg_types.length }} {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.lGTypeSConfigured') }}</div>
        </div>
      </div>

      <!-- Error banner -->
      <div v-if="Object.keys(form.errors).length" class="zv-alert zv-alert-danger mb-5">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
        <span>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.pleaseFixTheErrorsBelowBefore') }}</span>
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
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.contractMainInformation') }}</div>
              <div class="form-section-sub">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.agreementLabelDatesLimitAndCurrency') }}</div>
            </div>
          </div>

          <div class="form-section-body">
            <div class="field-grid field-grid-5">

              <!-- Financial Institution — read-only -->
              <div class="field-group field-group-span-2">
                <label class="zv-label">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.financialInstitution') }}</label>
                <div class="zv-readonly-field">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-4 h-4 opacity-40 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                  <span>{{ bank?.name_en }}</span>
                  <span v-if="bank?.name_ar" class="zv-readonly-sub">— {{ bank?.name_ar }}</span>
                </div>
              </div>

              <!-- Name -->
              <div class="field-group field-group-span-3">
                <label class="zv-label zv-label-required">{{ t('common.name') }}</label>
                <input v-model="form.name" type="text" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.name }"
                       :placeholder="t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.eGLGContractYearMay')"/>
                <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
              </div>

              <!-- Contract Start Date -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.contractStartDate') }}</label>
                <input v-model="form.start_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.start_date }"/>
                <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
              </div>

              <!-- Contract End Date -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.contractEndDate') }}</label>
                <input v-model="form.review_date" type="date" class="zv-input"
                       :class="{ 'zv-input-error': form.errors.review_date }"/>
                <p v-if="form.errors.review_date" class="zv-field-error">{{ form.errors.review_date }}</p>
              </div>

              <!-- Limit -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.limit') }}</label>
                <div class="zv-input-group">
                  <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                  <input v-model="form.facility_limit" type="number" step="0.01" min="0"
                         class="zv-input zv-input-has-prefix text-end"
                         :class="{ 'zv-input-error': form.errors.facility_limit }"
                         placeholder="0.00"/>
                </div>
                <p v-if="form.errors.facility_limit" class="zv-field-error">{{ form.errors.facility_limit }}</p>
              </div>

              <!-- Currency -->
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.selectCurrency') }}</label>
                <select v-model="form.currency_id" class="zv-select"
                        :class="{ 'zv-input-error': form.errors.currency_id }">
                  <option value="">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.select') }}</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">
                    {{ c.code }} — {{ c.name_en }}{{ c.id == companyBaseCurrencyId ? ' (' + t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.functional') + ')' : '' }}
                  </option>
                </select>
                <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
              </div>

            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 2 — TERMS & CONDITIONS (LG type rate card)
        ══════════════════════════════════════════════════════ -->
        <div class="form-section mb-5">
          <div class="form-section-header">
            <div class="form-section-icon" style="background:#b8860b">
              <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            </div>
            <div class="flex-1">
              <div class="form-section-title">{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.termsConditions') }}</div>
              <div class="form-section-sub">
                {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.oneRowPerLGTypeCash') }}
              </div>
            </div>
          </div>

          <div class="form-section-body">

            <!-- Duplicate type warning -->
            <div v-if="duplicateTypes.length" class="zv-alert zv-alert-warning mb-4">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
              </svg>
              <span>
                {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.thisLGTypeAppearsMoreThan') }} {{ duplicateTypes.join(', ') }} — {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.eachTypeCanOnlyHaveOne') }}
              </span>
            </div>

            <!-- Table -->
            <div class="lg-tier-table">
              <div class="lg-tier-header">
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.lGType') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.cashCover') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.commissionRate') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.commissionInterval') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.minCommissionFeeAmount') }}</div>
                <div>{{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.issuanceFeeAmount') }}</div>
                <div></div>
              </div>

              <!-- Rate card rows -->
              <div v-for="(row, idx) in form.lg_types" :key="idx" class="lg-tier-row">

                <div class="lg-tier-col">
                  <input v-model="row.lg_type" type="text" class="zv-input"
                         :class="{ 'zv-input-error': form.errors['lg_types.' + idx + '.lg_type'] }"
                         :placeholder="t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.eGBidBond')"/>
                  <p v-if="form.errors['lg_types.' + idx + '.lg_type']" class="zv-field-error">
                    {{ form.errors['lg_types.' + idx + '.lg_type'] }}
                  </p>
                </div>

                <div class="lg-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.cash_cover_pct" type="number" min="0" max="100" step="0.01"
                           class="zv-input zv-input-has-suffix text-end"
                           :class="{ 'zv-input-error': form.errors['lg_types.' + idx + '.cash_cover_pct'] }"
                           placeholder="0"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>

                <div class="lg-tier-col">
                  <div class="zv-input-group">
                    <input v-model="row.commission_rate_pct" type="number" min="0" max="100" step="0.0001"
                           class="zv-input zv-input-has-suffix text-end"
                           :class="{ 'zv-input-error': form.errors['lg_types.' + idx + '.commission_rate_pct'] }"
                           placeholder="0.0000"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                </div>

                <div class="lg-tier-col">
                  <select v-model="row.commission_interval" class="zv-select">
                    <option v-for="(label, key) in commissionIntervals" :key="key" :value="key">{{ t(label) }}</option>
                  </select>
                </div>

                <div class="lg-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                    <input v-model="row.min_commission_fee_amount" type="number" min="0" step="0.01"
                           class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                  </div>
                </div>

                <div class="lg-tier-col">
                  <div class="zv-input-group">
                    <span class="zv-input-prefix text-xs font-semibold">{{ selectedCurrencyCode }}</span>
                    <input v-model="row.issuance_fee_amount" type="number" min="0" step="0.01"
                           class="zv-input zv-input-has-prefix text-end" placeholder="0.00"/>
                  </div>
                </div>

                <div class="lg-tier-col lg-tier-col-action">
                  <button v-if="form.lg_types.length > 1" type="button" class="lg-remove-btn"
                          @click="removeTypeRow(idx)" :title="t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.remove')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Footer: Add -->
              <div class="lg-tier-footer">
                <button type="button" class="lg-add-row-btn" @click="addTypeRow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                  </svg>
                  {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.addLGType') }}
                </button>
              </div>
            </div>

            <p v-if="form.errors.lg_types" class="zv-field-error mt-2">{{ form.errors.lg_types }}</p>

            <!-- Info callout -->
            <div class="lg-callout mt-4">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span class="text-sm">
                {{ t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.theseTermsApplyAutomaticallyWheneverA') }}
              </span>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             FORM ACTIONS
        ══════════════════════════════════════════════════════ -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3">
            <Link :href="route('fi.facilities.lg.index')" class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
              <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
              {{ form.processing ? t('common.saving') : (isEdit ? t('common.saveChanges') : t('financialInstitutions.banks.facilities.letterOfGuaranteeForm.createLetterOfGuaranteeFacility')) }}
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
  defaultLgTypes:        Array,   // ['Bid Bond', 'Final LG', 'Advanced Payment LG', 'Performance LG']
  commissionIntervals:   Object,  // { monthly: 'Monthly', quarterly: 'Quarterly', ... }
  companyBaseCurrencyId: Number,  // the company's functional currency id
})

const isEdit = computed(() => !!props.facility?.id)

// New agreements start with one row per default LG type, empty terms
// ready to fill in — matches the reference image's 4-row starting point.
function defaultRow(type = '') {
  return {
    lg_type:                   type,
    cash_cover_pct:             '',
    commission_rate_pct:        '',
    commission_interval:        'quarterly',
    min_commission_fee_amount:  '',
    issuance_fee_amount:        '',
  }
}

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  facility_type: 'letter_guarantee',
  bank_id:       props.bank?.id ?? '',

  // Section 1
  name:           props.facility?.name          ?? '',
  start_date:     props.facility?.start_date?.toString().slice(0, 10) ?? '',
  review_date:    props.facility?.review_date?.toString().slice(0, 10) ?? '',
  currency_id:    props.facility?.currency_id    ?? props.companyBaseCurrencyId ?? '',
  facility_limit: props.facility?.facility_limit ?? '',

  // Section 2 — rate card
  // NOTE: the lgTypes() relationship on CreditFacility serializes to
  // JSON as "lg_types" (Laravel snake-cases relation keys on array/
  // JSON conversion), NOT "lgTypes" — reading props.facility.lgTypes
  // here would silently be undefined and fall through to empty
  // defaults, which is exactly the bug this comment is guarding against.
  lg_types: props.facility?.lg_types?.length
    ? props.facility.lg_types.map(row => ({
        lg_type:                    row.lg_type                    ?? '',
        cash_cover_pct:              row.cash_cover_pct              ?? '',
        commission_rate_pct:         row.commission_rate_pct         ?? '',
        commission_interval:         row.commission_interval         ?? 'quarterly',
        min_commission_fee_amount:   row.min_commission_fee_amount   ?? '',
        issuance_fee_amount:         row.issuance_fee_amount         ?? '',
      }))
    : (props.defaultLgTypes ?? []).map(type => defaultRow(type)),
})

// ── Computed ──────────────────────────────────────────────────────

const selectedCurrencyCode = computed(() => {
  const c = (props.currencies ?? []).find(c => c.id == form.currency_id)
  return c?.code ?? 'CCY'
})

// Client-side mirror of the server's duplicate-type check — catches
// the mistake before submit instead of after a round trip.
const duplicateTypes = computed(() => {
  const seen  = new Set()
  const dupes = new Set()

  form.lg_types.forEach(row => {
    const type = (row.lg_type || '').trim().toLowerCase()
    if (!type) return
    if (seen.has(type)) dupes.add(row.lg_type.trim())
    seen.add(type)
  })

  return [...dupes]
})

// ── Methods ───────────────────────────────────────────────────────

function addTypeRow() {
  form.lg_types.push(defaultRow())
}

function removeTypeRow(idx) {
  form.lg_types.splice(idx, 1)
}

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function submit() {
  if (isEdit.value) {
    form.put(route('fi.facilities.lg.update', props.facility.id))
  } else {
    form.post(route('fi.facilities.lg.store'))
  }
}
</script>

<style>
/* ── Page header ─────────────────────────────────────────────────── */
.lg-bank-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 20px;
  background: rgba(15,37,84,0.07); border: 1px solid rgba(15,37,84,0.12);
  font-size: 12.5px; font-weight: 600; color: #0F2554;
}
[data-theme="dark"] .lg-bank-chip { background: rgba(255,255,255,0.06); color: #90a4c4; border-color: rgba(255,255,255,0.1); }
.lg-bank-ar { color: var(--zv-text-muted); font-weight: 400; }

/* ── Limit summary panel ─────────────────────────────────────────── */
.lg-limit-panel {
  min-width: 220px; background: var(--zv-bg-card);
  border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg);
  padding: 14px 18px; box-shadow: var(--zv-shadow-sm);
}
.lg-limit-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); margin-bottom: 6px; }
.lg-limit-value { font-size: 18px; font-weight: 800; color: #0F2554; font-variant-numeric: tabular-nums; }
[data-theme="dark"] .lg-limit-value { color: #90a4c4; }
.lg-limit-sub   { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 4px; }

/* ── Alert ───────────────────────────────────────────────────────── */
.zv-alert { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: var(--zv-radius-md); font-size: 13px; }
.zv-alert-danger  { background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.2); color: #dc2626; }
.zv-alert-warning { background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2); color: #b45309; }

/* ── Read-only field ─────────────────────────────────────────────── */
.zv-readonly-field {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 12px; border: 1px solid var(--zv-border); border-radius: var(--zv-radius);
  background: var(--zv-bg-surface-alt); color: var(--zv-text-primary);
  font-size: 13px; font-weight: 500; min-height: 38px;
}
.zv-readonly-sub { color: var(--zv-text-muted); font-size: 12px; font-weight: 400; }

/* ── LG type rate card table ─────────────────────────────────────── */
.lg-tier-table { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); overflow: hidden; }
.lg-tier-header {
  display: grid; grid-template-columns: 1.4fr 1fr 1.1fr 1.1fr 1.3fr 1.3fr 40px;
  padding: 9px 14px; gap: 10px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--zv-text-muted);
}
.lg-tier-row {
  display: grid; grid-template-columns: 1.4fr 1fr 1.1fr 1.1fr 1.3fr 1.3fr 40px;
  padding: 10px 14px; gap: 10px; align-items: start;
  border-bottom: 1px solid var(--zv-border); transition: background 0.1s;
}
.lg-tier-row:last-of-type { border-bottom: none; }
.lg-tier-row:hover { background: var(--zv-bg-surface-alt); }
.lg-tier-col { display: flex; flex-direction: column; }
.lg-tier-col-action { align-items: center; justify-content: center; padding-top: 2px; }

.lg-remove-btn {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius); border: none; background: transparent; cursor: pointer;
  color: var(--zv-text-muted); transition: all 0.12s;
}
.lg-remove-btn:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.lg-tier-footer {
  display: flex; align-items: center; justify-content: flex-start;
  padding: 10px 14px; border-top: 1px dashed var(--zv-border);
  background: var(--zv-bg-surface);
}
.lg-add-row-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border: 1px dashed var(--zv-border);
  border-radius: var(--zv-radius); background: transparent;
  color: #1490A8; font-size: 13px; font-family: inherit; cursor: pointer; transition: all 0.15s;
}
.lg-add-row-btn:hover { border-color: #1490A8; background: rgba(20,144,168,0.05); }

/* ── Info callout ────────────────────────────────────────────────── */
.lg-callout {
  display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px;
  background: rgba(20,144,168,0.05); border: 1px solid rgba(20,144,168,0.15);
  border-radius: var(--zv-radius-md); color: #1490A8;
}

/* ── Card footer ─────────────────────────────────────────────────── */
.zv-card { background: var(--zv-bg-card); border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); box-shadow: var(--zv-shadow-sm); }
.zv-card-footer { padding: 1rem 1.375rem; border-top: 1px solid var(--zv-border); }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .lg-tier-header, .lg-tier-row { grid-template-columns: 1fr 1fr; }
  .lg-tier-header > div:nth-child(n+3),
  .lg-tier-row > .lg-tier-col:nth-child(n+3) { grid-column: span 1; }
}
@media (max-width: 900px) {
  .field-grid-3, .field-grid-5 { grid-template-columns: 1fr 1fr; }
  .field-group-span-2, .field-group-span-3 { grid-column: span 2; }
  .zv-page-header { flex-direction: column; }
  .lg-limit-panel { width: 100%; }
}
@media (max-width: 600px) {
  .field-grid-3, .field-grid-5 { grid-template-columns: 1fr; }
  .field-group-span-2, .field-group-span-3 { grid-column: span 1; }
  .lg-tier-header { display: none; }
  .lg-tier-row { grid-template-columns: 1fr; }
}
</style>