<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/PayrollRunCreate.vue

  Starts a new monthly Payroll Run — snapshots every active employee
  into a line the moment it's created (PayrollRunService::create()).
  No edit page for the run itself; period/date are fixed once
  created, everything else happens on PayrollRunShow.

  @layout    AppLayout
  @route     hr.payroll.create
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.payrollRunCreate.startAPayrollRun')"
      :subtitle="t('accounting.hr.payrollRunCreate.everyActiveEmployeeGetsSnapshottedIn')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>'
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
            <div class="form-section-title">{{ t('accounting.hr.payrollRunCreate.periodSection') }}</div>
            <div class="form-section-sub">{{ t('accounting.hr.payrollRunCreate.periodSectionSub') }}</div>
          </div>
        </div>

        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.payrollRunCreate.month') }}</label>
              <select v-model.number="form.period_month" class="zv-input" :class="{ 'zv-input-error': form.errors.period_month }">
                <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <p v-if="form.errors.period_month" class="zv-field-error">{{ form.errors.period_month }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.payrollRunCreate.year') }}</label>
              <input v-model.number="form.period_year" type="number" min="2020" max="2100" class="zv-input" :class="{ 'zv-input-error': form.errors.period_year }"/>
              <p v-if="form.errors.period_year" class="zv-field-error">{{ form.errors.period_year }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.hr.payrollRunCreate.runDate') }}</label>
              <input v-model="form.run_date" type="date" class="zv-input" :class="{ 'zv-input-error': form.errors.run_date }"/>
              <p v-if="form.errors.run_date" class="zv-field-error">{{ form.errors.run_date }}</p>
            </div>
          </div>
          <p class="text-sm opacity-70 mt-2">{{ t('accounting.hr.payrollRunCreate.theDateEveryJournalEntryFromThis') }}</p>
        </div>
      </div>

      <!-- M-36: bonus / final-settlement runs -->
      <div class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-2">
            <div class="field-group">
              <label class="zv-label">Run type</label>
              <select v-model="form.run_type" class="zv-input">
                <option value="regular">Regular monthly run</option>
                <option value="bonus">Bonus run</option>
                <option value="final_settlement">Final settlement (leaver)</option>
              </select>
            </div>
            <div v-if="form.run_type !== 'regular'" class="field-group">
              <label class="zv-label">Label (optional)</label>
              <input v-model="form.label" type="text" maxlength="120" class="zv-input" placeholder="e.g. Eid bonus"/>
            </div>
          </div>
          <div v-if="form.run_type !== 'regular'" class="field-group mt-3">
            <label class="zv-label zv-label-required">Employees in this run</label>
            <select v-model="form.partner_ids" multiple size="8" class="zv-input">
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}{{ e.active ? '' : ' (inactive)' }}</option>
            </select>
            <p v-if="form.errors.partner_ids" class="zv-field-error">{{ form.errors.partner_ids }}</p>
            <p class="field-hint">Hold Ctrl (or Cmd) to pick several. A bonus / final run starts with zero salary — add the amounts on the next page.</p>
          </div>
        </div>
      </div>

      <div class="zv-card">
        <div class="zv-card-footer flex items-center justify-end gap-3">
          <Link :href="route('hr.payroll.index')" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</Link>
          <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">{{ form.processing ? t('common.saving') : t('accounting.hr.payrollRunCreate.createRun') }}</button>
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

defineProps({ employees: { type: Array, default: () => [] } })

const { t } = useI18n()

const now = new Date()
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// Built as {value, label} pairs (value = 1-12) instead of relying on a
// v-if alongside v-for in the template — in Vue 3, v-if on the same
// element as v-for runs BEFORE the v-for scope exists, so it can't see
// the loop's own index variable. That silently broke the dropdown.
const monthOptions = computed(() => MONTHS.map((label, idx) => ({ value: idx + 1, label })))

const form = useForm({
  period_month: now.getMonth() + 1,
  period_year:  now.getFullYear(),
  run_date:     todayLocal(),
  run_type:     'regular',
  partner_ids:  [],
  label:        '',
})

function submit() {
  // A regular run covers everyone: do not send the label / employee list that were
  // typed earlier while another run type was selected.
  form.transform(d => d.run_type === 'regular' ? { ...d, label: '', partner_ids: [] } : d)
    .post(route('hr.payroll.store'))
}
</script>
