<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/EosbProvisionRunCreate.vue

  Starts a new monthly EOSB Provision run — snapshots every enrolled
  employee (EmployeePayrollProfile::isEnrolledInEosb()) into a line
  the moment it's created, fully auto-calculated
  (EosbProvisionRunService::create()). No edit page for the run
  itself; everything happens on EosbProvisionRunShow (Post / Set to
  Draft only — there's nothing to manually adjust, unlike Payroll
  Run's tax/SI/deduction fields).

  @layout    AppLayout
  @route     hr.eosb-provision.create
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.eosbProvisionRunCreate.startAnEosbProvisionRun')"
      :subtitle="t('accounting.hr.eosbProvisionRunCreate.everyEnrolledEmployeeGetsSnapshotted')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/>'
    />

    <form @submit.prevent="submit">

      <div class="form-section mb-5">
        <div class="form-section-header">
          <div class="form-section-icon" style="background:#0F2554">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
            </svg>
          </div>
          <div>
            <div class="form-section-title">{{ t('accounting.hr.eosbProvisionRunCreate.periodSection') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.eosbProvisionRunCreate.periodSectionSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.eosbProvisionRunCreate.month') }}</label>
              <select v-model.number="form.period_month" class="zv-input" :class="{ 'zv-input-error': form.errors.period_month }">
                <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <p v-if="form.errors.period_month" class="zv-field-error">{{ form.errors.period_month }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.eosbProvisionRunCreate.year') }}</label>
              <input v-model.number="form.period_year" type="number" min="2020" max="2100" class="zv-input" :class="{ 'zv-input-error': form.errors.period_year }"/>
              <p v-if="form.errors.period_year" class="zv-field-error">{{ form.errors.period_year }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.eosbProvisionRunCreate.runDate') }}</label>
              <input v-model="form.run_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.run_date }"/>
              <p v-if="form.errors.run_date" class="zv-field-error">{{ form.errors.run_date }}</p>
            </div>
          </div>
          <p class="text-sm opacity-70 mt-2">{{ t('accounting.hr.eosbProvisionRunCreate.provisionIsCalculatedOnBaseSalary') }}</p>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('hr.eosb-provision.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">{{ form.processing ? t('common.saving') : t('accounting.hr.eosbProvisionRunCreate.createRun') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const now = new Date()
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// Same {value, label} pairing as Payroll Run's own Create page — see
// that file's comment on why v-if/v-for on the same element breaks
// this in Vue 3.
const monthOptions = computed(() => MONTHS.map((label, idx) => ({ value: idx + 1, label })))

const form = useForm({
  period_month: now.getMonth() + 1,
  period_year:  now.getFullYear(),
  run_date:     todayLocal(),
})

function submit() {
  form.post(route('hr.eosb-provision.store'))
}
</script>
