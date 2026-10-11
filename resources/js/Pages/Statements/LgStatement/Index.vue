<!--
══════════════════════════════════════════════════════════════════
  Statements/LgStatement/Index.vue

  Financial Institutions > Statement > LG / Cash Cover Statement —
  the picker form. Confirmed with Mahmoud (2026-09): this report
  reads directly from lg_issuances + lg_issuance_history, never the
  GL — every filter here is optional, leave all blank to see every
  LG movement for the company.

  @layout    AppLayout
  @route     fi.statements.lg.index
  @props     banks, beneficiaries, lgTypes
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.statements.lgStatementIndex.statement') }, { label: t('financialInstitutions.statements.lgStatementIndex.lgStatement') }]"
      :title="t('financialInstitutions.statements.lgStatementIndex.lgStatement')"
      :subtitle="t('financialInstitutions.statements.lgStatementIndex.subtitle')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lgStatementIndex.bank') }}</label>
            <select v-model="form.bank_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lgStatementIndex.allBanks') }}</option>
              <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lgStatementIndex.beneficiary') }}</label>
            <select v-model="form.beneficiary_partner_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lgStatementIndex.allBeneficiaries') }}</option>
              <option v-for="p in beneficiaries" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lgStatementIndex.lgType') }}</label>
            <select v-model="form.lg_type" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lgStatementIndex.allTypes') }}</option>
              <option v-for="ty in lgTypes" :key="ty" :value="ty">{{ ty }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lgStatementIndex.groupBy') }}</label>
            <select v-model="form.group_by" class="zv-select">
              <option value="none">{{ t('financialInstitutions.statements.lgStatementIndex.noGrouping') }}</option>
              <option value="beneficiary">{{ t('financialInstitutions.statements.lgStatementIndex.byBeneficiary') }}</option>
              <option value="bank">{{ t('financialInstitutions.statements.lgStatementIndex.byBank') }}</option>
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

        <label class="zv-toggle-row mt-3">
          <span class="zv-toggle" :class="{ 'zv-toggle-on': form.include_reversed }" @click="form.include_reversed = !form.include_reversed">
            <span class="zv-toggle-knob"/>
          </span>
          {{ t('financialInstitutions.statements.lgStatementIndex.includeReversed') }}
        </label>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!!endDateError" @click="viewStatement">
            {{ t('financialInstitutions.statements.lgStatementIndex.viewStatement') }}
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
  banks:         { type: Array, default: () => [] },
  beneficiaries: { type: Array, default: () => [] },
  lgTypes:       { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  bank_id: '',
  beneficiary_partner_id: '',
  lg_type: '',
  group_by: 'none',
  start_date: '',
  end_date: '',
  include_reversed: false,
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('financialInstitutions.statements.lgStatementIndex.endDateCannotBeInTheFuture')
  }
  return ''
})

function viewStatement() {
  if (endDateError.value) return
  router.get(route('fi.statements.lg.result'), {
    bank_id: form.bank_id || undefined,
    beneficiary_partner_id: form.beneficiary_partner_id || undefined,
    lg_type: form.lg_type || undefined,
    group_by: form.group_by,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
    include_reversed: form.include_reversed ? 1 : undefined,
  })
}
</script>
