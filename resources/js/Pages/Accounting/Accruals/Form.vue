<!--
══════════════════════════════════════════════════════════════════
  Accounting/Accruals/Form.vue

  Create only — an Accrual posts its Dr Expense / Cr 228 Accrued
  Expenses journal entry immediately (see AccrualService header),
  so there is no draft state and no edit route. Rule 2 (Create/Edit
  = one page) doesn't apply here since there is no Edit mode at all.

  @layout    AppLayout
  @route     accounting.accruals.create
  @props     suppliers, expenseAccounts, costCenters, projects, overheadPools
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.accruals.form.accruals'), href: route('accounting.accruals.index') }, { label: t('accounting.accruals.form.new') }]"
      :title="t('accounting.accruals.form.newAccrual')"
      :subtitle="t('accounting.accruals.form.booksAnExpenseBeforeTheSupplierS')"
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.accruals.form.accrualDetails') }}</div>
            <div class="form-section-sub">{{ t('accounting.accruals.form.whoItSOwedToWhichAccount') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-6-4-2">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.accruals.form.supplier') }}</label>
              <SelectBox v-model="form.partner_id" :options="suppliers" value-key="id" :label-fn="s => `${s.code} — ${s.name_en}`" :placeholder="t('accounting.accruals.form.selectSupplier')" :clearable="false" :class="{ 'zv-input-error': form.errors.partner_id }" />
              <p v-if="form.errors.partner_id" class="zv-field-error">{{ form.errors.partner_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.accruals.form.expenseAssetAccount') }}</label>
              <SelectBox v-model="form.chart_of_account_id" :options="expenseAccounts" value-key="id" :label-fn="a => `${a.code} — ${a.name_en}`" :placeholder="t('accounting.accruals.form.selectAccount')" :clearable="false" :class="{ 'zv-input-error': form.errors.chart_of_account_id }" />
              <p class="field-hint">{{ t('accounting.accruals.form.theAccountThisExpenseActuallyBelongs') }}</p>
              <p v-if="form.errors.chart_of_account_id" class="zv-field-error">{{ form.errors.chart_of_account_id }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.accruals.form.accrualDate') }}</label>
              <input v-model="form.accrual_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.accrual_date }"/>
              <p v-if="form.errors.accrual_date" class="zv-field-error">{{ form.errors.accrual_date }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-8-4 mt-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.accruals.form.description') }}</label>
              <input v-model="form.description" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.description }" :placeholder="t('accounting.accruals.form.eGJulyOfficeRentBill')"/>
              <p v-if="form.errors.description" class="zv-field-error">{{ form.errors.description }}</p>
            </div>

            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.amount') }}</label>
              <input v-model="form.amount" type="number" step="0.01" min="0.01" class="zv-input text-end" :class="{ 'zv-input-error': form.errors.amount }"/>
              <p v-if="form.errors.amount" class="zv-field-error">{{ form.errors.amount }}</p>
            </div>
          </div>

          <div class="field-grid field-grid-8-4 mt-4">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.accruals.form.costCenter') }}</label>
              <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.accruals.form.none')" />
            </div>

            <div class="field-group">
              <label class="zv-label">{{ t('accounting.accruals.form.project') }}</label>
              <SelectBox v-model="form.project_id" :options="projects" value-key="id" :label-fn="pr => pr.contract_code" :placeholder="t('accounting.accruals.form.none')" />
            </div>
          </div>

          <div class="field-grid mt-4">
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.accruals.form.overheadPool') }}</label>
              <SelectBox v-model="form.overhead_pool_id" :options="overheadPools" value-key="id" label-key="name" :placeholder="t('accounting.accruals.form.none')" />
              <p class="field-hint">{{ t('accounting.accruals.form.optionalOnlyIfThisIsARealManufacturing') }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.accruals.form.internalNotes') }}</label>
            <textarea v-model="form.internal_notes" rows="3" class="zv-input"></textarea>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('accounting.accruals.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" :disabled="form.processing" class="zv-btn zv-btn-primary">
            {{ form.processing ? t('accounting.accruals.form.posting') : t('accounting.accruals.form.postAccrual') }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  suppliers:       { type: Array, default: () => [] },
  expenseAccounts: { type: Array, default: () => [] },
  costCenters:     { type: Array, default: () => [] },
  projects:        { type: Array, default: () => [] },
  overheadPools:   { type: Array, default: () => [] },
})

const form = useForm({
  partner_id:          '',
  chart_of_account_id: '',
  cost_center_id:      '',
  project_id:          '',
  overhead_pool_id:    '',
  description:         '',
  accrual_date:        todayLocal(),
  amount:              '',
  internal_notes:      '',
})

function submit() {
  form.post(route('accounting.accruals.store'))
}
</script>
