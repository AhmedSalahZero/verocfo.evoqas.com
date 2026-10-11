<!--
══════════════════════════════════════════════════════════════════
  Analytics/Sales/Reports.vue

  Analytics > Sales Analysis > Reports. Ported from CashVero's CFO
  Tools Sales Analysis Reports page, reading real posted Customer
  Invoices instead of an Excel upload (SalesAnalysisService). Same 8
  report types: Single Dimension, Matrix (2D), Ranking, Customer
  Nature, Period Comparison, Trend Over Time, Two Factors Trend,
  Invoice Analysis.

  Every report runs via an AJAX POST to sales.reports.run (JSON
  result rendered in place, no full page reload) — the filter panel
  never resets between runs. Export downloads the same result as an
  Excel file via a plain GET navigation.

  @layout    AppLayout
  @route     analytics.sales.reports
  @props     hasData, dimensions, metrics, reportTypes
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('analytics.salesAnalysis.reports.salesAnalysis'), href: route('analytics.sales.index') }, { label: t('analytics.salesAnalysis.reports.reports') }]"
      :title="t('analytics.salesAnalysis.reports.reports')"
      :subtitle="t('analytics.salesAnalysis.reports.subtitle')"
    />

    <div v-if="!hasData" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('analytics.salesAnalysis.reports.noPostedInvoices') }}</div></div></div>

    <template v-else>
      <!-- ── Report Type Picker ─────────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="form-section-body">
          <label class="zv-label mb-2 d-block">{{ t('analytics.salesAnalysis.reports.reportType') }}</label>
          <div class="zv-tile-group sa-report-type-group">
            <button
              v-for="rt in reportTypes" :key="rt.key" type="button"
              class="zv-tile" :class="{ 'zv-tile-active': form.report_type === rt.key }"
              @click="form.report_type = rt.key"
            >
              <div class="zv-tile-label">{{ rt.label }}</div>
              <div class="zv-tile-sub">{{ rt.description }}</div>
            </button>
          </div>

          <div class="field-divider"></div>

          <!-- ── Common filters ──────────────────────────────────── -->
          <div class="field-grid field-grid-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
              <input v-model="form.date_from" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.endDate') }}</label>
              <input v-model="form.date_to" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('analytics.salesAnalysis.reports.metric') }}</label>
              <SelectBox v-model="form.metric" :options="metricOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
            <div class="field-group" v-if="needsPeriod">
              <label class="zv-label">{{ t('analytics.salesAnalysis.reports.period') }}</label>
              <SelectBox v-model="form.period" :options="periodOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
          </div>

          <!-- ── Dimension pickers ───────────────────────────────── -->
          <div class="field-grid field-grid-3" v-if="needsDimension1 || needsDimension2 || needsTopN || needsThreshold">
            <div class="field-group" v-if="needsDimension1">
              <label class="zv-label zv-label-required">{{ t('analytics.salesAnalysis.reports.dimension1') }}</label>
              <SelectBox v-model="form.dimension1" :options="dimensionOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
            <div class="field-group" v-if="needsDimension2">
              <label class="zv-label zv-label-required">{{ t('analytics.salesAnalysis.reports.dimension2') }}</label>
              <SelectBox v-model="form.dimension2" :options="dimensionOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
            <div class="field-group" v-if="needsTopN">
              <label class="zv-label">{{ t('analytics.salesAnalysis.reports.topN') }}</label>
              <input v-model.number="form.top_n" type="number" min="1" max="500" class="zv-input"/>
            </div>
            <div class="field-group" v-if="needsThreshold">
              <label class="zv-label">{{ t('analytics.salesAnalysis.reports.invoiceThreshold') }}</label>
              <input v-model.number="form.invoice_threshold" type="number" min="0" class="zv-input" :placeholder="t('analytics.salesAnalysis.reports.thresholdPlaceholder')"/>
            </div>
          </div>

          <!-- ── Period Comparison: 2-5 date ranges ──────────────── -->
          <div v-if="form.report_type === 'period_comparison'">
            <div class="field-divider"></div>
            <label class="zv-label mb-2 d-block">{{ t('analytics.salesAnalysis.reports.periodsToCompare') }}</label>
            <div v-for="(p, i) in form.periods" :key="i" class="field-grid field-grid-4 sa-period-row">
              <div class="field-group">
                <label class="zv-label">{{ t('analytics.salesAnalysis.reports.periodLabel') }}</label>
                <input v-model="p.label" type="text" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('common.startDate') }}</label>
                <input v-model="p.from" type="date" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('common.endDate') }}</label>
                <input v-model="p.to" type="date" class="zv-input"/>
              </div>
              <div class="field-group sa-period-remove">
                <button type="button" class="zv-btn zv-btn-ghost" :disabled="form.periods.length <= 2" @click="removePeriod(i)">{{ t('common.remove') }}</button>
              </div>
            </div>
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="form.periods.length >= 5" @click="addPeriod">
              {{ t('analytics.salesAnalysis.reports.addPeriod') }}
            </button>
          </div>

          <div class="mt-4 flex justify-end" style="gap: 0.5rem;">
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="!canSubmit || loading" @click="exportReport">
              {{ t('analytics.salesAnalysis.reports.exportToExcel') }}
            </button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit || loading" @click="runReport">
              {{ loading ? t('common.loading') : t('analytics.salesAnalysis.reports.runReport') }}
            </button>
          </div>
        </div>
      </div>

      <!-- ── Results ────────────────────────────────────────────── -->
      <div v-if="result" class="zv-card">

        <!-- Single Dimension -->
        <template v-if="result.type === 'single_dimension'">
          <DonutChart3D :data="donutData" class="mb-4" />
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(result.dimension) }}</th><th class="zv-table-num">{{ metricLabel }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.transactions') }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.share') }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.accumulated') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td class="zv-table-num">{{ fmt(row.value) }}</td><td class="zv-table-num">{{ row.transactions }}</td><td class="zv-table-num">{{ row.share }}%</td><td class="zv-table-num">{{ row.accumulated_share }}%</td>
                </tr>
              </tbody>
              <tfoot><tr class="zv-summary-row"><td>{{ t('common.total') }}</td><td class="zv-table-num">{{ fmt(result.total) }}</td><td colspan="3"></td></tr></tfoot>
            </table>
          </div>
        </template>

        <!-- Matrix -->
        <template v-else-if="result.type === 'matrix'">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(result.dim1) }}</th><th v-for="c in result.columns" :key="c" class="zv-table-num">{{ c }}</th><th class="zv-table-num">{{ t('common.total') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td v-for="c in result.columns" :key="c" class="zv-table-num">{{ fmt(row[c]) }}</td><td class="zv-table-num">{{ fmt(row.total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Ranking -->
        <template v-else-if="result.type === 'ranking'">
          <div v-for="row in result.rows" :key="row.label" class="sa-rank-block">
            <div class="sa-rank-header"><strong>{{ row.label }}</strong><span class="zv-table-num">{{ fmt(row.total) }}</span></div>
            <table class="zv-table">
              <tbody>
                <tr v-for="b in row.breakdown" :key="b.label"><td>{{ b.label }}</td><td class="zv-table-num">{{ fmt(b.value) }}</td></tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Customer Nature -->
        <template v-else-if="result.type === 'customer_nature'">
          <div class="field-grid field-grid-3">
            <div v-for="(bucket, key) in result.buckets" :key="key" class="zv-kpi-card">
              <div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.natureBuckets.' + key) }}</div>
              <div class="zv-kpi-value">{{ bucket.count }}</div>
              <div class="zv-kpi-sub">{{ fmt(bucket.value) }}</div>
            </div>
          </div>
        </template>

        <!-- Period Comparison -->
        <template v-else-if="result.type === 'period_comparison'">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(form.dimension1) }}</th><th v-for="p in result.periods" :key="p" class="zv-table-num">{{ p }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.changeVsPrev') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td v-for="p in result.periods" :key="p" class="zv-table-num">{{ fmt(row[p]) }}</td>
                  <td class="zv-table-num">{{ row.change_vs_prev !== undefined && row.change_vs_prev !== null ? row.change_vs_prev + '%' : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Trend -->
        <template v-else-if="result.type === 'trend'">
          <MultiLineChart :data="trendChartData" :series-def="[{ field: 'value', name: metricLabel, color: '#1490A8' }]" class="mb-4" />
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.salesAnalysis.reports.period') }}</th><th class="zv-table-num">{{ metricLabel }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.transactions') }}</th><th class="zv-table-num">{{ t('analytics.salesAnalysis.reports.growthRate') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.period">
                  <td>{{ row.period }}</td><td class="zv-table-num">{{ fmt(row.value) }}</td><td class="zv-table-num">{{ row.transactions }}</td>
                  <td class="zv-table-num">{{ row.growth_rate !== null ? row.growth_rate + '%' : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Two Factors Trend -->
        <template v-else-if="result.type === 'two_factors_trend'">
          <MultiLineChart :data="twoFactorChartData" :series-def="twoFactorSeriesDef" class="mb-4" />
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(result.dimension) }}</th><th v-for="p in result.periods" :key="p" class="zv-table-num">{{ p }}</th></tr></thead>
              <tbody>
                <tr v-for="s in result.series" :key="s.label">
                  <td>{{ s.label }}</td><td v-for="pt in s.points" :key="pt.period" class="zv-table-num">{{ fmt(pt.value) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Invoice Analysis -->
        <template v-else-if="result.type === 'invoice_analysis'">
          <div class="field-grid field-grid-3 mb-4">
            <div class="zv-kpi-card"><div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.invoiceCount') }}</div><div class="zv-kpi-value">{{ result.snapshot.invoice_count }}</div></div>
            <div class="zv-kpi-card"><div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.avgInvoiceValue') }}</div><div class="zv-kpi-value">{{ fmt(result.snapshot.avg_invoice_value) }}</div></div>
            <div class="zv-kpi-card"><div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.medianInvoiceValue') }}</div><div class="zv-kpi-value">{{ fmt(result.snapshot.median_invoice_value) }}</div></div>
            <div class="zv-kpi-card"><div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.maxInvoiceValue') }}</div><div class="zv-kpi-value">{{ fmt(result.snapshot.max_invoice_value) }}</div></div>
            <div class="zv-kpi-card"><div class="zv-kpi-label">{{ t('analytics.salesAnalysis.reports.avgLineItems') }}</div><div class="zv-kpi-value">{{ Number(result.snapshot.avg_line_items).toFixed(1) }}</div></div>
          </div>
          <div v-if="result.large_invoices?.length" class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.salesAnalysis.reports.invoiceCode') }}</th><th>{{ t('analytics.salesAnalysis.reports.customer') }}</th><th class="zv-table-num">{{ metricLabel }}</th></tr></thead>
              <tbody>
                <tr v-for="inv in result.large_invoices" :key="inv.id"><td>{{ inv.invoice_code }}</td><td>{{ inv.customer_name }}</td><td class="zv-table-num">{{ fmt(inv.value) }}</td></tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
import { todayLocal, oneYearAgoLocal } from '@/Utils/dates'
import { reactive, computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import MultiLineChart from '@/Components/Charts/MultiLineChart.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'

const { t } = useI18n()

const props = defineProps({
  hasData:     { type: Boolean, default: false },
  dimensions:  { type: Object, default: () => ({}) },
  metrics:     { type: Object, default: () => ({}) },
  reportTypes: { type: Array, default: () => [] },
})

const today = todayLocal()
const oneYearAgo = oneYearAgoLocal()

const dimensionOptions = computed(() => Object.entries(props.dimensions).map(([value, label]) => ({ value, label })))
const metricOptions    = computed(() => Object.entries(props.metrics).map(([value, label]) => ({ value, label })))
const periodOptions = [
  { value: 'monthly', label: t('analytics.salesAnalysis.reports.periods.monthly') },
  { value: 'quarterly', label: t('analytics.salesAnalysis.reports.periods.quarterly') },
  { value: 'semi_annually', label: t('analytics.salesAnalysis.reports.periods.semiAnnually') },
  { value: 'annually', label: t('analytics.salesAnalysis.reports.periods.annually') },
]

function dimensionLabel(key) { return props.dimensions[key] || key }
const metricLabel = computed(() => props.metrics[form.metric] || form.metric)

const form = reactive({
  report_type: 'single_dimension',
  date_from: oneYearAgo,
  date_to: today,
  metric: 'net_amount',
  period: 'monthly',
  dimension1: 'customer',
  dimension2: 'product',
  top_n: 20,
  invoice_threshold: 0,
  periods: [
    { label: t('analytics.salesAnalysis.reports.periodA'), from: oneYearAgo, to: today },
    { label: t('analytics.salesAnalysis.reports.periodB'), from: oneYearAgo, to: today },
  ],
})

const needsDimension1 = computed(() => ['single_dimension', 'matrix', 'ranking', 'two_factors_trend', 'period_comparison'].includes(form.report_type))
const needsDimension2 = computed(() => ['matrix', 'ranking'].includes(form.report_type))
const needsPeriod     = computed(() => ['trend', 'two_factors_trend'].includes(form.report_type))
const needsTopN       = computed(() => form.report_type === 'ranking')
const needsThreshold  = computed(() => form.report_type === 'invoice_analysis')

const canSubmit = computed(() => !!form.date_from && !!form.date_to)

function addPeriod() {
  if (form.periods.length >= 5) return
  form.periods.push({ label: '', from: form.date_from, to: form.date_to })
}
function removePeriod(i) {
  if (form.periods.length <= 2) return
  form.periods.splice(i, 1)
}

const loading = ref(false)
const result = ref(null)

function buildPayload() {
  return {
    report_type: form.report_type,
    date_from: form.date_from,
    date_to: form.date_to,
    metric: form.metric,
    period: form.period,
    dimension1: form.dimension1,
    dimension2: form.dimension2,
    top_n: form.top_n,
    invoice_threshold: form.invoice_threshold,
    periods: form.report_type === 'period_comparison' ? form.periods : undefined,
  }
}

async function runReport() {
  if (!canSubmit.value) return
  loading.value = true
  try {
    const { data } = await axios.post(route('analytics.sales.reports.run'), buildPayload())
    result.value = data
  } finally {
    loading.value = false
  }
}

function exportReport() {
  if (!canSubmit.value) return
  const params = new URLSearchParams()
  const payload = buildPayload()
  Object.entries(payload).forEach(([key, value]) => {
    if (value === undefined) return
    params.append(key, typeof value === 'object' ? JSON.stringify(value) : value)
  })
  window.location.href = route('analytics.sales.reports.export') + '?' + params.toString()
}

// ── Chart data adapters ─────────────────────────────────────────

const donutData = computed(() => (result.value?.rows || []).map(r => ({ category: r.label, value: r.value })))

const trendChartData = computed(() => (result.value?.rows || []).map(r => ({ date: r.period, value: r.value })))

const twoFactorSeriesDef = computed(() => (result.value?.series || []).map((s, i) => ({
  field: 'f' + i, name: s.label, color: ['#1490A8', '#0F2554', '#FFBF00', '#16a34a', '#dc2626', '#7c3aed'][i % 6],
})))
const twoFactorChartData = computed(() => {
  const periods = result.value?.periods || []
  return periods.map((period, pi) => {
    const row = { date: period }
    ;(result.value?.series || []).forEach((s, si) => { row['f' + si] = s.points[pi]?.value || 0 })
    return row
  })
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.sa-report-type-group { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 900px) { .sa-report-type-group { grid-template-columns: repeat(2, 1fr); } }
.zv-tile-sub { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.d-block { display: block; }
.sa-period-row { align-items: end; margin-bottom: 0.75rem; }
.sa-period-remove { display: flex; align-items: end; }
.sa-rank-block { margin-bottom: 1.25rem; }
.sa-rank-header { display: flex; justify-content: space-between; padding: 0.5rem 0; font-size: 0.95rem; border-bottom: 2px solid var(--zv-border); margin-bottom: 0.25rem; }
.zv-kpi-card { background: var(--zv-bg-surface-alt); border-radius: 10px; padding: 1rem; }
.zv-kpi-label { font-size: 0.75rem; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.zv-kpi-value { font-size: 1.5rem; font-weight: 700; margin-top: 0.25rem; }
.zv-kpi-sub { font-size: 0.85rem; color: var(--zv-text-muted); margin-top: 0.25rem; }
</style>
