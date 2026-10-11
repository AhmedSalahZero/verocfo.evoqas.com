<!--
══════════════════════════════════════════════════════════════════
  Statements/SafeStatement/Index.vue

  Financial Institutions > Statement > Safe Statement — the picker
  form. A safe is a real, postable Chart of Accounts row (131xxx) —
  this report reads the real journal entries against it, exactly
  like Bank Statement does for a bank account (see
  SafeStatementController header).

  @layout    AppLayout
  @route     fi.statements.safe.index
  @props     safeAccounts
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.statements.safeStatementIndex.statement') }, { label: t('financialInstitutions.statements.safeStatementIndex.safeStatement') }]"
      :title="t('financialInstitutions.statements.safeStatementIndex.safeStatement')"
      :subtitle="t('financialInstitutions.statements.safeStatementIndex.pickASafeAndDateRange')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div v-if="safeAccounts.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('financialInstitutions.statements.safeStatementIndex.noSafeAccountsFound') }}</div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('financialInstitutions.statements.safeStatementIndex.safeAccount') }}</label>
            <select v-model="form.safe_account_id" class="zv-select">
              <option value="" disabled>{{ t('financialInstitutions.statements.safeStatementIndex.selectSafe') }}</option>
              <option v-for="a in safeAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.startDate') }}</label>
            <input v-model="form.start_date" type="date" class="zv-input"/>
            <p class="field-hint">{{ t('financialInstitutions.statements.safeStatementIndex.leaveBlankForFromTheBeginning') }}</p>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('common.endDate') }}</label>
            <input v-model="form.end_date" type="date" class="zv-input" :max="today" :class="{ 'zv-input-error': endDateError }"/>
            <p v-if="endDateError" class="zv-field-error">{{ endDateError }}</p>
            <p class="field-hint">{{ t('financialInstitutions.statements.safeStatementIndex.leaveBlankForUpToToday') }}</p>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!form.safe_account_id || !!endDateError" @click="viewStatement">
            {{ t('financialInstitutions.statements.safeStatementIndex.viewStatement') }}
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
  safeAccounts: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  safe_account_id: '',
  start_date: '',
  end_date: '',
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('financialInstitutions.statements.safeStatementIndex.endDateCannotBeInTheFuture')
  }
  return ''
})

function viewStatement() {
  if (endDateError.value) return
  router.get(route('fi.statements.safe.result'), {
    safe_account_id: form.safe_account_id,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
  })
}
</script>
