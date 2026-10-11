<!--
══════════════════════════════════════════════════════════════════
  Statements/WithdrawalStatement/Index.vue

  Financial Institutions > Statement > Withdrawal Statement — the
  picker form. Reads facility_withdrawals directly (already kept
  correct by the Settlement Engine) — no GL involved.

  @layout    AppLayout
  @route     fi.statements.withdrawal.index
  @props     banks, facilities
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.statements.withdrawalStatementIndex.statement') }, { label: t('financialInstitutions.statements.withdrawalStatementIndex.withdrawalStatement') }]"
      :title="t('financialInstitutions.statements.withdrawalStatementIndex.withdrawalStatement')"
      :subtitle="t('financialInstitutions.statements.withdrawalStatementIndex.subtitle')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.withdrawalStatementIndex.bank') }}</label>
            <select v-model="form.bank_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.withdrawalStatementIndex.allBanks') }}</option>
              <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.withdrawalStatementIndex.facility') }}</label>
            <select v-model="form.credit_facility_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.withdrawalStatementIndex.allFacilities') }}</option>
              <option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.withdrawalStatementIndex.status') }}</label>
            <select v-model="form.status" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.withdrawalStatementIndex.allStatuses') }}</option>
              <option value="open">{{ t('financialInstitutions.statements.withdrawalStatementIndex.statusOpen') }}</option>
              <option value="partial">{{ t('financialInstitutions.statements.withdrawalStatementIndex.statusPartial') }}</option>
              <option value="settled">{{ t('financialInstitutions.statements.withdrawalStatementIndex.statusSettled') }}</option>
              <option value="overdue">{{ t('financialInstitutions.statements.withdrawalStatementIndex.statusOverdue') }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.withdrawalStatementIndex.groupBy') }}</label>
            <select v-model="form.group_by" class="zv-select">
              <option value="none">{{ t('financialInstitutions.statements.withdrawalStatementIndex.noGrouping') }}</option>
              <option value="bank">{{ t('financialInstitutions.statements.withdrawalStatementIndex.byBank') }}</option>
              <option value="facility">{{ t('financialInstitutions.statements.withdrawalStatementIndex.byFacility') }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input"/>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" :max="today" :class="{ 'zv-input-error': endDateError }"/>
            <p v-if="endDateError" class="zv-field-error">{{ endDateError }}</p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!!endDateError" @click="viewStatement">
            {{ t('financialInstitutions.statements.withdrawalStatementIndex.viewStatement') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

defineProps({
  banks:      { type: Array, default: () => [] },
  facilities: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  bank_id: '',
  credit_facility_id: '',
  status: '',
  group_by: 'none',
  start_date: '',
  end_date: '',
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('financialInstitutions.statements.withdrawalStatementIndex.endDateCannotBeInTheFuture')
  }
  return ''
})

function viewStatement() {
  if (endDateError.value) return
  router.get(route('fi.statements.withdrawal.result'), {
    bank_id: form.bank_id || undefined,
    credit_facility_id: form.credit_facility_id || undefined,
    status: form.status || undefined,
    group_by: form.group_by,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
  })
}
</script>
