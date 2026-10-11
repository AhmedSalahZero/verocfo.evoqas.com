<!--
══════════════════════════════════════════════════════════════════
  Statements/LcStatement/Index.vue

  Financial Institutions > Statement > LC Statement — the picker
  form. Same pattern as Statements/LgStatement/Index.vue — reads
  directly from letter_of_credit_issuances + lc_issuance_history,
  never the GL.

  @layout    AppLayout
  @route     fi.statements.lc.index
  @props     banks, suppliers, lcTypes
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">

    <PageHeader
      :breadcrumbs="[{ label: t('financialInstitutions.statements.lcStatementIndex.statement') }, { label: t('financialInstitutions.statements.lcStatementIndex.lcStatement') }]"
      :title="t('financialInstitutions.statements.lcStatementIndex.lcStatement')"
      :subtitle="t('financialInstitutions.statements.lcStatementIndex.subtitle')"
    />

    <div class="zv-card">
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lcStatementIndex.bank') }}</label>
            <select v-model="form.bank_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lcStatementIndex.allBanks') }}</option>
              <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lcStatementIndex.supplier') }}</label>
            <select v-model="form.supplier_partner_id" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lcStatementIndex.allSuppliers') }}</option>
              <option v-for="p in suppliers" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lcStatementIndex.lcType') }}</label>
            <select v-model="form.lc_type" class="zv-select">
              <option value="">{{ t('financialInstitutions.statements.lcStatementIndex.allTypes') }}</option>
              <option v-for="ty in lcTypes" :key="ty" :value="ty">{{ ty }}</option>
            </select>
          </div>

          <div class="field-group">
            <label class="zv-label">{{ t('financialInstitutions.statements.lcStatementIndex.groupBy') }}</label>
            <select v-model="form.group_by" class="zv-select">
              <option value="none">{{ t('financialInstitutions.statements.lcStatementIndex.noGrouping') }}</option>
              <option value="supplier">{{ t('financialInstitutions.statements.lcStatementIndex.bySupplier') }}</option>
              <option value="bank">{{ t('financialInstitutions.statements.lcStatementIndex.byBank') }}</option>
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
          {{ t('financialInstitutions.statements.lcStatementIndex.includeReversed') }}
        </label>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!!endDateError" @click="viewStatement">
            {{ t('financialInstitutions.statements.lcStatementIndex.viewStatement') }}
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
  banks:     { type: Array, default: () => [] },
  suppliers: { type: Array, default: () => [] },
  lcTypes:   { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  bank_id: '',
  supplier_partner_id: '',
  lc_type: '',
  group_by: 'none',
  start_date: '',
  end_date: '',
  include_reversed: false,
})

const endDateError = computed(() => {
  if (form.end_date && form.end_date > today) {
    return t('financialInstitutions.statements.lcStatementIndex.endDateCannotBeInTheFuture')
  }
  return ''
})

function viewStatement() {
  if (endDateError.value) return
  router.get(route('fi.statements.lc.result'), {
    bank_id: form.bank_id || undefined,
    supplier_partner_id: form.supplier_partner_id || undefined,
    lc_type: form.lc_type || undefined,
    group_by: form.group_by,
    start_date: form.start_date || undefined,
    end_date: form.end_date || undefined,
    include_reversed: form.include_reversed ? 1 : undefined,
  })
}
</script>
