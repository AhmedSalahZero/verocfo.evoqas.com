<!--
══════════════════════════════════════════════════════════════════
  Statements/BankStatement/Index.vue

  Financial Institutions > Statement > Bank Statement — the picker
  form. Confirmed with Mahmoud (2026-09): this report reads directly
  from the real journal entries (see LedgerStatementService) — this
  page only picks WHICH account and date range to view.

  v1.1 (2026-09, Mahmoud) — "every bank account open in the
  application" includes Overdraft-family Credit Facilities, not just
  bank_accounts rows. The dropdown now has two groups; each option
  carries source_type ('bank_account' | 'credit_facility') alongside
  its id, since a facility and a bank account are different tables
  underneath (see BankStatementController header).

  @layout    AppLayout
  @route     fi.statements.bank.index
  @props     bankAccounts, creditFacilities
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.statements.bankStatementIndex.statement') }, { label: t('financialInstitutions.statements.bankStatementIndex.bankStatement') }]"
      :title="t('financialInstitutions.statements.bankStatementIndex.bankStatement')"
      :subtitle="t('financialInstitutions.statements.bankStatementIndex.pickAnAccountAndDateRange')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div v-if="bankAccounts.length === 0 && creditFacilities.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('financialInstitutions.statements.bankStatementIndex.noBankAccountsFound') }}</div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.statements.bankStatementIndex.bankAccount') }}</label>
            <select v-model="selectedKey" class="zv-select">
              <option value="" disabled>{{ t('financialInstitutions.statements.bankStatementIndex.selectAccount') }}</option>
              <optgroup v-if="bankAccounts.length" :label="t('financialInstitutions.statements.bankStatementIndex.bankAccountsGroup')">
                <option v-for="a in bankAccounts" :key="`bank_account-${a.id}`" :value="`bank_account-${a.id}`">
                  {{ a.bank_name }} — {{ accountTypeLabel(a.account_type) }} — {{ a.account_number }} — {{ a.currency_code }}
                </option>
              </optgroup>
              <optgroup v-if="creditFacilities.length" :label="t('financialInstitutions.statements.bankStatementIndex.creditFacilitiesGroup')">
                <option v-for="f in creditFacilities" :key="`credit_facility-${f.id}`" :value="`credit_facility-${f.id}`">
                  {{ f.bank_name }} — {{ accountTypeLabel(f.account_type) }} — {{ f.account_number }} — {{ f.currency_code }}
                </option>
              </optgroup>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input"/>
            <p class="field-hint">{{ t('financialInstitutions.statements.bankStatementIndex.leaveBlankForFromTheBeginning') }}</p>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" :max="today" :class="{ 'zv-input-error': endDateError }"/>
            <p v-if="endDateError" class="zv-field-error">{{ endDateError }}</p>
            <p class="field-hint">{{ t('financialInstitutions.statements.bankStatementIndex.leaveBlankForUpToToday') }}</p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!selectedKey || !!endDateError" @click="viewStatement">
            {{ t('financialInstitutions.statements.bankStatementIndex.viewStatement') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { reactive, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  bankAccounts:     { type: Array, default: () => [] },
  creditFacilities: { type: Array, default: () => [] },
})

// Mahmoud: end_date can never be after today — the "max" attribute
// stops most cases at the calendar-picker level, but a typed-in date
// bypasses that, so this check is the one that actually matters.
const today = todayLocal()

// Combined "source_type-id" key so one <select> can hold both groups —
// split back into the two real params on submit.
const selectedKey = ref('')

const form = reactive({
  start_date: '',
  end_date: '',
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('financialInstitutions.statements.bankStatementIndex.endDateCannotBeInTheFuture')
  }
  return ''
})

function accountTypeLabel(type) {
  return {
    current: t('financialInstitutions.statements.bankStatementIndex.currentAccount'),
    time_deposit: t('financialInstitutions.statements.bankStatementIndex.timeDeposit'),
    escrow: t('financialInstitutions.statements.bankStatementIndex.escrow'),
    lg_cash_cover: t('financialInstitutions.statements.bankStatementIndex.lgCashCover'),
    lc_cash_cover: t('financialInstitutions.statements.bankStatementIndex.lcCashCover'),
    clean_od: t('financialInstitutions.statements.bankStatementIndex.cleanOverdraft'),
    commercial_paper: t('financialInstitutions.statements.bankStatementIndex.commercialPaperOverdraft'),
    contract_so: t('financialInstitutions.statements.bankStatementIndex.contractAssignmentOverdraft'),
    fully_secured: t('financialInstitutions.statements.bankStatementIndex.fullySecuredOverdraft'),
  }[type] ?? type
}

function viewStatement() {
  if (endDateError.value) return
  const [sourceType, sourceId] = selectedKey.value.split('-')
  router.get(route('fi.statements.bank.result'), {
    source_type: sourceType,
    source_id: sourceId,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
  })
}
</script>
