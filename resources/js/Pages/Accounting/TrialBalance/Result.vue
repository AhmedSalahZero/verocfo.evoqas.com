<!--
══════════════════════════════════════════════════════════════════
  Accounting/TrialBalance/Result.vue

  The report itself — one row per postable account, a repeating
  Beginning/Debit/Credit/Ending block per period (1 block for Single
  Date, up to 12 for Monthly, 4 for Quarterly), plus a grand-total
  row proving Debit total == Credit total for every period shown —
  the actual arithmetic proof a Trial Balance exists to give.

  Column count is dynamic (driven entirely by report.periods, built
  server-side in TrialBalanceController) — this page never hardcodes
  how many periods there are. Horizontally scrollable for the wider
  Monthly view (up to 49 columns).

  @layout    AppLayout
  @route     accounting.trial-balance.result
  @props     report ({periods, rows, totals}), costCenter, filters
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[
        { label: t('accounting.trialBalance.result.backToTrialBalance'), href: route('accounting.trial-balance.index') },
        { label: t('accounting.trialBalance.result.trialBalance') },
      ]"
      :title="t('accounting.trialBalance.result.trialBalance')"
      :subtitle="modeLabel + (costCenterLabel ? ' · ' + t('accounting.trialBalance.result.costCenter') + ': ' + costCenterLabel : '')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>'
    >
      <template #actions>
        <a :href="exportUrl" class="zv-btn zv-btn-secondary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
          </svg>
          {{ t('accounting.trialBalance.result.exportToExcel') }}
        </a>
      </template>
    </PageHeader>

    <div v-if="filters.cost_center_id" class="zv-alert zv-alert-info mb-3">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span class="text-sm">{{ t('accounting.trialBalance.result.costCenterOpeningBalanceNote') }}</span>
    </div>

    <div v-if="!balances" class="zv-alert zv-alert-warning mb-3">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      </svg>
      <span class="text-sm">{{ t('accounting.trialBalance.result.doesNotBalanceWarning') }}</span>
    </div>

    <div v-if="proofKpi" class="zv-kpi-row-3 mb-4">
      <div class="zv-mini-kpi">
        <div class="zv-mini-kpi-label">{{ t('accounting.trialBalance.result.debit') }}</div>
        <div class="zv-mini-kpi-value">{{ fmt(proofKpi.debit) }}</div>
      </div>
      <div class="zv-mini-kpi">
        <div class="zv-mini-kpi-label">{{ t('accounting.trialBalance.result.credit') }}</div>
        <div class="zv-mini-kpi-value">{{ fmt(proofKpi.credit) }}</div>
      </div>
      <div class="zv-mini-kpi">
        <div class="zv-mini-kpi-label">{{ t('accounting.trialBalance.result.debit') }} − {{ t('accounting.trialBalance.result.credit') }}</div>
        <div class="zv-mini-kpi-value" :class="Math.abs(proofKpi.diff) < 0.01 ? '' : 'zv-num-danger'">{{ fmt(proofKpi.diff) }}</div>
      </div>
    </div>

    <div class="zv-card">
      <div v-if="report.rows.length === 0" class="zv-empty">
        <div class="zv-empty-title">{{ t('accounting.trialBalance.result.noAccountsToShow') }}</div>
      </div>

      <div v-else class="zv-table-wrap tb-table-wrap">
        <table class="zv-table tb-table">
          <thead>
            <tr>
              <th rowspan="2" class="tb-sticky-col">{{ t('accounting.trialBalance.result.code') }}</th>
              <th rowspan="2" class="tb-sticky-col-2">{{ t('accounting.trialBalance.result.account') }}</th>
              <th v-for="period in report.periods" :key="period.key" colspan="4" class="tb-period-header">{{ period.label }}</th>
            </tr>
            <tr>
              <template v-for="period in report.periods" :key="period.key + '-sub'">
                <th class="zv-table-num">{{ t('accounting.trialBalance.result.beginning') }}</th>
                <th class="zv-table-num">{{ t('accounting.trialBalance.result.debit') }}</th>
                <th class="zv-table-num">{{ t('accounting.trialBalance.result.credit') }}</th>
                <th class="zv-table-num">{{ t('accounting.trialBalance.result.ending') }}</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in report.rows" :key="row.id" class="tb-row">
              <td class="gl-muted tb-sticky-col"><span class="zv-code">{{ row.code }}</span></td>
              <td class="tb-sticky-col-2">{{ row.name_en }}</td>
              <template v-for="period in report.periods" :key="row.id + '-' + period.key">
                <td class="zv-table-num">{{ fmt(row.periods[period.key].beginning) }}</td>
                <td class="zv-table-num">{{ fmt(row.periods[period.key].debit) }}</td>
                <td class="zv-table-num">{{ fmt(row.periods[period.key].credit) }}</td>
                <td class="zv-table-num tb-ending">{{ fmt(row.periods[period.key].ending) }}</td>
              </template>
            </tr>
          </tbody>
          <tfoot>
            <tr class="zv-summary-row tb-total-row">
              <td class="tb-sticky-col"></td>
              <td class="tb-sticky-col-2">{{ t('accounting.trialBalance.result.total') }}</td>
              <template v-for="period in report.periods" :key="'t-' + period.key">
                <td class="zv-table-num">
                  <div>{{ t('accounting.trialBalance.result.debit') }} {{ fmt(report.totals[period.key]?.beginning_debit) }}</div>
                  <div>{{ t('accounting.trialBalance.result.credit') }} {{ fmt(report.totals[period.key]?.beginning_credit) }}</div>
                </td>
                <td class="zv-table-num">{{ fmt(report.totals[period.key]?.debit) }}</td>
                <td class="zv-table-num">{{ fmt(report.totals[period.key]?.credit) }}</td>
                <td class="zv-table-num">
                  <div>{{ t('accounting.trialBalance.result.debit') }} {{ fmt(report.totals[period.key]?.ending_debit) }}</div>
                  <div>{{ t('accounting.trialBalance.result.credit') }} {{ fmt(report.totals[period.key]?.ending_credit) }}</div>
                </td>
              </template>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  report:      { type: Object, required: true }, // { periods, rows, totals }
  costCenter:  { type: Object, default: null },
  filters:     { type: Object, default: () => ({}) },
})

const costCenterLabel = computed(() => {
  return props.costCenter ? `${props.costCenter.code} — ${props.costCenter.name_en}` : null
})

const modeLabel = computed(() => {
  const labels = {
    single: t('accounting.trialBalance.result.modeSingle'),
    monthly: t('accounting.trialBalance.result.modeMonthly'),
    quarterly: t('accounting.trialBalance.result.modeQuarterly'),
  }
  return labels[props.filters.mode] ?? labels.single
})

// The balancing proof — every period's Debit total should equal its
// Credit total. null (not false) means "nothing to check" (no
// periods), so the warning banner only fires when there's a real
// mismatch to flag.
const balances = computed(() => {
  const periods = props.report.periods || []
  if (periods.length === 0) return null
  return periods.every((period) => {
    const totals = props.report.totals[period.key]
    if (!totals) return true
    return Math.abs(Number(totals.debit) - Number(totals.credit)) < 0.01
  })
})

const proofKpi = computed(() => {
  const periods = props.report.periods || []
  if (periods.length === 0) return null
  const last = periods[periods.length - 1]
  const totals = props.report.totals[last.key] || {}
  const debit = Number(totals.debit || 0)
  const credit = Number(totals.credit || 0)
  return { debit, credit, diff: debit - credit }
})

const exportUrl = computed(() => {
  const params = new URLSearchParams()
  Object.entries(props.filters).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      params.set(key, value)
    }
  })
  return route('accounting.trial-balance.export') + '?' + params.toString()
})

function fmt(val) { return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style>
.tb-table-wrap { overflow-x: auto; }
.tb-table { border-collapse: collapse; }
.tb-period-header { text-align: center; border-bottom: 1px solid var(--zv-border); }
.tb-sticky-col { position: sticky; left: 0; background: var(--zv-bg-surface); z-index: 2; min-width: 90px; }
.tb-sticky-col-2 { position: sticky; left: 90px; background: var(--zv-bg-surface); z-index: 2; min-width: 200px; text-align: left; }
.gl-muted { color: var(--zv-text-muted); }
.tb-ending { color: #f59e0b; font-weight: 600; }
.tb-row:hover td:not(.tb-sticky-col):not(.tb-sticky-col-2) { background: var(--zv-bg-surface-alt); }
.tb-row:hover .tb-sticky-col, .tb-row:hover .tb-sticky-col-2 { background: var(--zv-bg-surface-alt); }
.tb-total-row td { border-top: 2px solid var(--zv-border); font-weight: 700; }
.tb-total-row .tb-sticky-col, .tb-total-row .tb-sticky-col-2 { background: var(--zv-bg-surface); }

@media (max-width: 900px) { .zv-page-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; } }
</style>
