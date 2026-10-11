<!--
══════════════════════════════════════════════════════════════════
  Accounting/Payroll/EosbProvisionRunShow.vue

  One EOSB Provision run — one row per enrolled employee, fully
  auto-calculated at creation (base_salary × eosb_rate_percent, both
  snapshotted). Unlike Payroll Run's line modal, there is nothing to
  edit here — Post and Set to Draft are the only actions.

  Posting creates exactly ONE journal entry (Dr 542 / Cr 241), shown
  at the bottom once posted — see EosbProvisionRunService header.

  @layout    AppLayout
  @route     hr.eosb-provision.show
  @props     run
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="periodLabel"
      :subtitle="t('accounting.hr.eosbProvisionRunShow.runDate') + ': ' + String(run.run_date).slice(0, 10)"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/>'
    >
      <template #actions>
        <button v-if="run.status === 'draft'" type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="confirmPost">
          {{ t('accounting.hr.eosbProvisionRunShow.postRun') }}
        </button>
        <template v-else-if="run.status === 'posted'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.hr.eosbProvisionRunShow.setToDraft') }}</button>
          <StatusBadge :status="run.status" />
        </template>
        <StatusBadge v-else :status="run.status" />
      </template>
    </PageHeader>

    <!-- Summary strip -->
    <div class="eosb-summary-grid mb-4">
      <div class="eosb-summary-card"><div class="eosb-summary-label">{{ t('accounting.hr.eosbProvisionRunShow.enrolledEmployees') }}</div><div class="eosb-summary-value">{{ run.lines.length }}</div></div>
      <div class="eosb-summary-card"><div class="eosb-summary-label">{{ t('accounting.hr.eosbProvisionRunShow.totalProvision') }}</div><div class="eosb-summary-value eosb-summary-highlight">{{ fmt(totals.provision) }}</div></div>
    </div>

    <div class="zv-card">
      <EmptyState
        v-if="!run.lines.length"
        :title="t('accounting.hr.eosbProvisionRunShow.noEnrolledEmployees')"
        :message="t('accounting.hr.eosbProvisionRunShow.noActiveEmployeeHasAnEosbRate')"
        icon-path="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.eosbProvisionRunShow.employee') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunShow.baseSalary') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunShow.eosbRate') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunShow.provisionAmount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in run.lines" :key="l.id">
              <td class="font-semibold">{{ l.partner?.name_en }}</td>
              <td class="zv-table-num">{{ fmt(l.base_salary) }}</td>
              <td class="zv-table-num">{{ Number(l.eosb_rate_percent).toFixed(2) }}%</td>
              <td class="zv-table-num font-semibold">{{ fmt(l.provision_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Journal entry — visible once posted -->
    <div v-if="run.journal_entry" class="zv-card mt-4">
      <div class="eosb-je-title">{{ t('accounting.hr.eosbProvisionRunShow.journalEntryPosted') }}: {{ run.journal_entry.entry_number ?? ('#' + run.journal_entry_id) }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.eosbProvisionRunShow.account') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunShow.debit') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.eosbProvisionRunShow.credit') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="jl in run.journal_entry.lines" :key="jl.id">
              <td>{{ jl.chart_of_account?.code }} — {{ jl.chart_of_account?.name_en }}</td>
              <td class="zv-table-num">{{ Number(jl.debit) > 0 ? fmt(jl.debit) : '' }}</td>
              <td class="zv-table-num">{{ Number(jl.credit) > 0 ? fmt(jl.credit) : '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  run: { type: Object, required: true },
})

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const periodLabel = computed(() => `${MONTHS[props.run.period_month]} ${props.run.period_year} ${t('accounting.hr.eosbProvisionRunShow.eosbProvision')}`)

function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }

const totals = computed(() => props.run.lines.reduce((acc, l) => {
  acc.provision += Number(l.provision_amount ?? 0)
  return acc
}, { provision: 0 }))

function confirmPost() {
  if (! confirm(t('accounting.hr.eosbProvisionRunShow.postThisRunThisWillPostAJournal'))) return
  router.patch(route('hr.eosb-provision.post', props.run.id))
}

function confirmSetToDraft() {
  if (! confirm(t('accounting.hr.eosbProvisionRunShow.reopenThisRunThisWillReverseTheJournal'))) return
  router.patch(route('hr.eosb-provision.set-to-draft', props.run.id))
}
</script>

<style>
.eosb-summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; max-width: 480px; }
.eosb-summary-card { background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e2e8f0); border-radius: var(--zv-radius-md, 10px); padding: 14px 16px; }
.eosb-summary-label { font-size: 12px; color: var(--zv-text-muted, #94a3b8); margin-bottom: 4px; }
.eosb-summary-value { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--zv-text-primary, #0f172a); }
.eosb-summary-highlight { color: #06aff1; }
.eosb-je-title { font-size: 13px; font-weight: 700; padding: 14px 16px 0; color: var(--zv-text-primary, #0f172a); }
</style>
