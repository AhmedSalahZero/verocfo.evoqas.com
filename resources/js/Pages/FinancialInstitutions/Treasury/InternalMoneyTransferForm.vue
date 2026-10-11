<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Treasury/InternalMoneyTransferForm.vue

  Create-only — record() posts immediately (no draft-then-post step,
  same shape as Customer/Supplier Payment), so there is no edit mode
  here. Reopening a mistaken transfer means Cancel (while in transit)
  or a new correcting transfer, never an edit.

  Each side (From / To) is independently either a Bank account or a
  Safe (a 131xxx Chart of Accounts sub-account — no separate Safe/
  Branch entity, confirmed by Mahmoud) — four leg combinations in
  total. Both account lists are filtered client-side by the chosen
  currency, same convention as Customer/Supplier Payment's own
  safe-account picker.

  Transfer Days = 0 posts one entry, instantly. Above 0 posts through
  COA 145 Cash in Transit and needs a separate "Confirm Received"
  action (from the Index page) once the money actually lands.

  @layout    AppLayout
  @route     fi.internal-money-transfers.create
  @props     bankAccounts, safeAccounts, facilities, currencies, functionalCurrencyId
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.treasury.internalMoneyTransferForm.internalMoneyTransfer'), href: route('fi.internal-money-transfers.index') }, { label: t('financialInstitutions.treasury.internalMoneyTransferForm.new') }]"
      :title="t('financialInstitutions.treasury.internalMoneyTransferForm.newInternalMoneyTransfer')"
      :subtitle="t('financialInstitutions.treasury.internalMoneyTransferForm.movesCashBetweenTwoOfThe')"
    />

    <form @submit.prevent="submit">

      <!-- ══════════════════ Transfer Details ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.transferDetails') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.currencyAmountAndHowManyDays') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.currency') }}</label>
              <select v-model="form.currency_id" class="zv-select" :class="{ 'zv-input-error': form.errors.currency_id }" @change="onCurrencyChange">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectCurrency') }}</option>
                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
              </select>
              <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
            </div>

            <div class="field-group" v-if="isForeignCurrency">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.exchangeRate') }}</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001" min="0.000001" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.exchange_rate }"/>
              <p v-if="form.errors.exchange_rate" class="zv-field-error">{{ form.errors.exchange_rate }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.amount') }}</label>
              <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }" placeholder="0.00"/>
              <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.transferDate') }}</label>
              <input v-model="form.transfer_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.transfer_date }"/>
              <p v-if="form.errors.transfer_date" class="zv-field-error">{{ form.errors.transfer_date }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.transferDays') }}</label>
              <input v-model="form.transfer_days" type="number" step="1" min="0" max="90" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.transfer_days }"/>
              <p class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.0InstantPostsFullyTodayMore') }}</p>
              <p v-if="form.errors.transfer_days" class="zv-field-error">{{ form.errors.transfer_days }}</p>
            </div>

            <div class="field-group" v-if="Number(form.transfer_days) > 0">
              <label class="zv-label">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.expectedClearingDate') }}</label>
              <input :value="expectedClearingDate" type="text" class="zv-input imt-readonly" readonly/>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ From ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#DC2626">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4m0 0l6-6m-6 6l6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.from') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.whereTheMoneyIsLeaving') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <OptionTiles
            :model-value="form.from_leg_type" variant="pill" class="mb-4"
            :options="[{ value: 'bank', label: t('financialInstitutions.treasury.internalMoneyTransferForm.bankAccount') }, { value: 'safe', label: t('financialInstitutions.treasury.internalMoneyTransferForm.safe') }, { value: 'facility', label: t('financialInstitutions.treasury.internalMoneyTransferForm.facility') }]"
            @update:model-value="(v) => setLegType('from', v)"
          />

          <div class="field-grid field-grid-3">
            <div class="field-group" v-if="form.from_leg_type === 'bank'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.bankAccount') }}</label>
              <select v-model="form.from_bank_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.from_bank_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectAccount') }}</option>
                <option v-for="a in fromBankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
              <p v-if="fromBankAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noBankAccountInThisCurrency') }}</p>
              <p v-if="form.errors.from_bank_account_id" class="zv-field-error">{{ form.errors.from_bank_account_id }}</p>
            </div>
            <div class="field-group" v-else-if="form.from_leg_type === 'safe'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.safe') }}</label>
              <select v-model="form.from_safe_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.from_safe_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectSafe') }}</option>
                <option v-for="s in fromSafeAccounts" :key="s.id" :value="s.id">{{ s.name_en }}</option>
              </select>
              <p v-if="fromSafeAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noSafeInThisCurrency') }}</p>
              <p v-if="form.errors.from_safe_account_id" class="zv-field-error">{{ form.errors.from_safe_account_id }}</p>
            </div>
            <div class="field-group" v-else>
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.facility') }}</label>
              <select v-model="form.from_credit_facility_id" class="zv-select" :class="{ 'zv-input-error': form.errors.from_credit_facility_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectFacility') }}</option>
                <option v-for="f in fromFacilities" :key="f.id" :value="f.id">{{ f.bank?.name_en }} — {{ f.facility_reference || facilityTypeLabel(f.facility_type) }}</option>
              </select>
              <p v-if="fromFacilities.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noFacilityInThisCurrency') }}</p>
              <p v-if="form.errors.from_credit_facility_id" class="zv-field-error">{{ form.errors.from_credit_facility_id }}</p>
              <p class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.drawsDownTheOverdraft') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════ To ══════════════════ -->
      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#059669">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m0 0l-6-6m6 6l-6 6"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.to') }}</div>
            <div class="form-section-sub">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.whereTheMoneyIsLanding') }}</div>
          </div>
        </div>
        <div class="form-section-body">
          <OptionTiles
            :model-value="form.to_leg_type" variant="pill" class="mb-4"
            :options="[{ value: 'bank', label: t('financialInstitutions.treasury.internalMoneyTransferForm.bankAccount') }, { value: 'safe', label: t('financialInstitutions.treasury.internalMoneyTransferForm.safe') }, { value: 'facility', label: t('financialInstitutions.treasury.internalMoneyTransferForm.facility') }]"
            @update:model-value="(v) => setLegType('to', v)"
          />

          <div class="field-grid field-grid-3">
            <div class="field-group" v-if="form.to_leg_type === 'bank'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.bankAccount') }}</label>
              <select v-model="form.to_bank_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.to_bank_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectAccount') }}</option>
                <option v-for="a in toBankAccounts" :key="a.id" :value="a.id">{{ a.bank?.name_en }} — {{ a.account_number }}</option>
              </select>
              <p v-if="toBankAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noBankAccountInThisCurrency') }}</p>
              <p v-if="form.errors.to_bank_account_id" class="zv-field-error">{{ form.errors.to_bank_account_id }}</p>
            </div>
            <div class="field-group" v-else-if="form.to_leg_type === 'safe'">
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.safe') }}</label>
              <select v-model="form.to_safe_account_id" class="zv-select" :class="{ 'zv-input-error': form.errors.to_safe_account_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectSafe') }}</option>
                <option v-for="s in toSafeAccounts" :key="s.id" :value="s.id">{{ s.name_en }}</option>
              </select>
              <p v-if="toSafeAccounts.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noSafeInThisCurrency') }}</p>
              <p v-if="form.errors.to_safe_account_id" class="zv-field-error">{{ form.errors.to_safe_account_id }}</p>
            </div>
            <div class="field-group" v-else>
              <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.facility') }}</label>
              <select v-model="form.to_credit_facility_id" class="zv-select" :class="{ 'zv-input-error': form.errors.to_credit_facility_id }">
                <option value="" disabled>{{ t('financialInstitutions.treasury.internalMoneyTransferForm.selectFacility') }}</option>
                <option v-for="f in toFacilities" :key="f.id" :value="f.id">{{ f.bank?.name_en }} — {{ f.facility_reference || facilityTypeLabel(f.facility_type) }}</option>
              </select>
              <p v-if="toFacilities.length === 0" class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.noFacilityInThisCurrency') }}</p>
              <p v-if="form.errors.to_credit_facility_id" class="zv-field-error">{{ form.errors.to_credit_facility_id }}</p>
              <p class="field-hint">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.paysTheOverdraftDown') }}</p>
            </div>
          </div>

          <p v-if="sameAccountError" class="zv-field-error mt-3">{{ sameAccountError }}</p>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.comment') }}</label>
            <textarea v-model="form.comment" class="zv-input" rows="3"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('fi.internal-money-transfers.index')" class="zv-btn zv-btn-ghost">{{ t('financialInstitutions.treasury.internalMoneyTransferForm.close') }}</Link>
          <button type="submit" :disabled="form.processing || !!sameAccountError" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('financialInstitutions.treasury.internalMoneyTransferForm.posting') : t('financialInstitutions.treasury.internalMoneyTransferForm.postTransfer') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal, addDaysIso } from '@/Utils/dates'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import OptionTiles from '@/Components/OptionTiles.vue'

const { t } = useI18n()

const props = defineProps({
  bankAccounts:          { type: Array,  default: () => [] },
  safeAccounts:           { type: Array,  default: () => [] },
  facilities:             { type: Array,  default: () => [] },
  currencies:             { type: Array,  default: () => [] },
  functionalCurrencyId:   { type: [Number, String], default: null },
})

const form = useForm({
  currency_id:            props.functionalCurrencyId ?? '',
  exchange_rate:           '1',
  amount:                  '',
  transfer_date:           todayLocal(),
  transfer_days:           '0',
  from_leg_type:           'bank',
  from_bank_account_id:    '',
  from_safe_account_id:    '',
  from_credit_facility_id: '',
  to_leg_type:             'bank',
  to_bank_account_id:      '',
  to_safe_account_id:      '',
  to_credit_facility_id:   '',
  comment:                 '',
})

const isForeignCurrency = computed(() => !!form.currency_id && String(form.currency_id) !== String(props.functionalCurrencyId))

function onCurrencyChange() {
  if (! isForeignCurrency.value) form.exchange_rate = '1'
  // A previously picked account may not exist in the new currency —
  // clear rather than silently keep an impossible combination, same
  // convention as Supplier Bill's Purchase Order field.
  form.from_bank_account_id = ''
  form.from_safe_account_id = ''
  form.from_credit_facility_id = ''
  form.to_bank_account_id = ''
  form.to_safe_account_id = ''
  form.to_credit_facility_id = ''
}

function setLegType(side, type) {
  form[`${side}_leg_type`] = type
  form[`${side}_bank_account_id`] = ''
  form[`${side}_safe_account_id`] = ''
  form[`${side}_credit_facility_id`] = ''
}

function facilityTypeLabel(type) {
  return {
    clean_od: t('financialInstitutions.treasury.internalMoneyTransferForm.cleanOverdraft'),
    commercial_paper: t('financialInstitutions.treasury.internalMoneyTransferForm.commercialPaperOverdraft'),
    contract_so: t('financialInstitutions.treasury.internalMoneyTransferForm.contractAssignmentOverdraft'),
    fully_secured: t('financialInstitutions.treasury.internalMoneyTransferForm.fullySecuredOverdraft'),
  }[type] ?? type
}

const fromBankAccounts = computed(() => (props.bankAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_id)))
const toBankAccounts   = computed(() => (props.bankAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_id)))
const fromSafeAccounts = computed(() => (props.safeAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_id)))
const toSafeAccounts   = computed(() => (props.safeAccounts ?? []).filter(a => String(a.currency_id) === String(form.currency_id)))
const fromFacilities   = computed(() => (props.facilities ?? []).filter(f => String(f.currency_id) === String(form.currency_id)))
const toFacilities     = computed(() => (props.facilities ?? []).filter(f => String(f.currency_id) === String(form.currency_id)))

const expectedClearingDate = computed(() => {
  const days = Number(form.transfer_days || 0)
  if (! form.transfer_date || days <= 0) return ''
  return addDaysIso(form.transfer_date, days)
})

const sameAccountError = computed(() => {
  if (form.from_leg_type === 'bank' && form.to_leg_type === 'bank'
    && form.from_bank_account_id && form.to_bank_account_id
    && form.from_bank_account_id === form.to_bank_account_id) {
    return t('financialInstitutions.treasury.internalMoneyTransferForm.sourceAndDestinationCannotBeThe')
  }
  if (form.from_leg_type === 'safe' && form.to_leg_type === 'safe'
    && form.from_safe_account_id && form.to_safe_account_id
    && form.from_safe_account_id === form.to_safe_account_id) {
    return t('financialInstitutions.treasury.internalMoneyTransferForm.sourceAndDestinationCannotBeThe2')
  }
  if (form.from_leg_type === 'facility' && form.to_leg_type === 'facility'
    && form.from_credit_facility_id && form.to_credit_facility_id
    && form.from_credit_facility_id === form.to_credit_facility_id) {
    return t('financialInstitutions.treasury.internalMoneyTransferForm.sourceAndDestinationCannotBeThe3')
  }
  return ''
})

function submit() {
  form.post(route('fi.internal-money-transfers.store'))
}
</script>

<style>
.imt-readonly { background: var(--zv-bg-surface); color: var(--zv-text-secondary); }
</style>
