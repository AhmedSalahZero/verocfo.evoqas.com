<!--
══════════════════════════════════════════════════════════════════
  Analytics/Expenses/Reports.vue

  Analytics > Expense Analysis > Reports. Ported from CashVero's CFO
  Tools Expense Analysis Reports page, reading the real general
  ledger (journal_entry_lines) instead of an Excel upload — see
  ExpenseAnalysisService header for why this is more complete, not
  less, than the original (every expense source is included, not
  just Supplier Bills).

  5 report types: Category Breakdown, Account Breakdown, Trend Over
  Time, Min/Avg/Max, Period Comparison. (Breakeven Analysis exists on
  the backend but has its own page, not part of this one — see
  ExpenseAnalysisController::breakevenPage()).

  @layout    AppLayout
  @route     analytics.expenses.reports
  @props     hasData, dimensions, reportTypes, categories
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('analytics.expenseAnalysis.reports.expenseAnalysis'), href: route('analytics.expenses.index') }, { label: t('analytics.expenseAnalysis.reports.reports') }]"
      :title="t('analytics.expenseAnalysis.reports.reports')"
      :subtitle="t('analytics.expenseAnalysis.reports.subtitle')"
    />

    <div v-if="!hasData" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('analytics.expenseAnalysis.reports.noPostedExpenses') }}</div></div></div>

    <template v-else>
      <!-- ── Report Type Picker ─────────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="form-section-body">
          <label class="zv-label mb-2 d-block">{{ t('analytics.expenseAnalysis.reports.reportType') }}</label>
          <div class="zv-tile-group ea-report-type-group">
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

          <div class="field-grid field-grid-4">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
              <input v-model="form.date_from" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('common.endDate') }}</label>
              <input v-model="form.date_to" type="date" class="zv-input" :max="today"/>
            </div>
            <div class="field-group" v-if="needsPeriod">
              <label class="zv-label">{{ t('analytics.expenseAnalysis.reports.period') }}</label>
              <SelectBox v-model="form.period" :options="periodOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
            <div class="field-group" v-if="needsCategoryFilter">
              <label class="zv-label">{{ t('analytics.expenseAnalysis.reports.category') }}</label>
              <SelectBox v-model="form.category" :options="categoryOptions" value-key="value" label-key="label" :placeholder="t('analytics.expenseAnalysis.reports.allCategories')" />
            </div>
            <div class="field-group" v-if="needsDimension1">
              <label class="zv-label">{{ t('analytics.expenseAnalysis.reports.dimension1') }}</label>
              <SelectBox v-model="form.dimension1" :options="dimensionOptions" value-key="value" label-key="label" :clearable="false" />
            </div>
          </div>

          <!-- ── Period Comparison: 2-5 date ranges ──────────────── -->
          <div v-if="form.report_type === 'period_comparison'">
            <div class="field-divider"></div>
            <label class="zv-label mb-2 d-block">{{ t('analytics.expenseAnalysis.reports.periodsToCompare') }}</label>
            <div v-for="(p, i) in form.periods" :key="i" class="field-grid field-grid-4 ea-period-row">
              <div class="field-group">
                <label class="zv-label">{{ t('analytics.expenseAnalysis.reports.periodLabel') }}</label>
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
              <div class="field-group ea-period-remove">
                <button type="button" class="zv-btn zv-btn-ghost" :disabled="form.periods.length <= 2" @click="removePeriod(i)">{{ t('common.remove') }}</button>
              </div>
            </div>
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="form.periods.length >= 5" @click="addPeriod">
              {{ t('analytics.expenseAnalysis.reports.addPeriod') }}
            </button>
          </div>

          <div class="mt-4 flex justify-end" style="gap: 0.5rem;">
            <button type="button" class="zv-btn zv-btn-secondary" :disabled="!canSubmit || loading" @click="exportReport">
              {{ t('analytics.expenseAnalysis.reports.exportToExcel') }}
            </button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit || loading" @click="runReport">
              {{ loading ? t('common.loading') : t('analytics.expenseAnalysis.reports.runReport') }}
            </button>
          </div>
        </div>
      </div>

      <!-- ── Results ────────────────────────────────────────────── -->
      <div v-if="result" class="zv-card">

        <!-- Category / Account Breakdown -->
        <template v-if="result.type === 'category_breakdown' || result.type === 'account_breakdown'">
          <DonutChart3D :data="donutData" class="mb-4" />
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.expenseAnalysis.reports.item') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.amount') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.pctOfExpense') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.pctOfRevenue') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td class="zv-table-num">{{ fmt(row.value) }}</td><td class="zv-table-num">{{ row.pct_of_expense }}%</td><td class="zv-table-num">{{ row.pct_of_revenue }}%</td>
                </tr>
              </tbody>
              <tfoot><tr class="zv-summary-row"><td>{{ t('common.total') }}</td><td class="zv-table-num">{{ fmt(result.total_expense) }}</td><td colspan="2"></td></tr></tfoot>
            </table>
          </div>
        </template>

        <!-- Trend -->
        <template v-else-if="result.type === 'trend'">
          <MultiLineChart :data="trendChartData" :series-def="[{ field: 'value', name: t('analytics.expenseAnalysis.reports.amount'), color: '#dc2626' }]" class="mb-4" />
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.expenseAnalysis.reports.period') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.amount') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.growthRate') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.pctOfRevenue') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.period">
                  <td>{{ row.period }}</td><td class="zv-table-num">{{ fmt(row.value) }}</td>
                  <td class="zv-table-num">{{ row.growth_rate !== null ? row.growth_rate + '%' : '—' }}</td>
                  <td class="zv-table-num">{{ row.revenue_pct }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Min / Avg / Max -->
        <template v-else-if="result.type === 'min_avg_max'">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(result.dimension) }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.min') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.avg') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.max') }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.monthsActive') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td class="zv-table-num">{{ fmt(row.min) }}</td><td class="zv-table-num">{{ fmt(row.avg) }}</td><td class="zv-table-num">{{ fmt(row.max) }}</td><td class="zv-table-num">{{ row.months_active }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <!-- Period Comparison -->
        <template v-else-if="result.type === 'period_comparison'">
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ dimensionLabel(result.dimension) }}</th><th v-for="p in result.periods" :key="p" class="zv-table-num">{{ p }}</th><th class="zv-table-num">{{ t('analytics.expenseAnalysis.reports.changeVsPrev') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in result.rows" :key="row.label">
                  <td>{{ row.label }}</td><td v-for="p in result.periods" :key="p" class="zv-table-num">{{ fmt(row[p]) }}</td>
                  <td class="zv-table-num">{{ row.change_vs_prev !== undefined && row.change_vs_prev !== null ? row.change_vs_prev + '%' : '—' }}</td>
                </tr>
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
  reportTypes: { type: Array, default: () => [] },
  categories:  { type: Array, default: () => [] },
})

const today = todayLocal()
const oneYearAgo = oneYearAgoLocal()

const dimensionOptions = computed(() => Object.entries(props.dimensions).map(([value, label]) => ({ value, label })))
const categoryOptions  = computed(() => props.categories.map(c => ({ value: c, label: c })))
const periodOptions = [
  { value: 'monthly', label: t('analytics.expenseAnalysis.reports.periods.monthly') },
  { value: 'quarterly', label: t('analytics.expenseAnalysis.reports.periods.quarterly') },
  { value: 'semi_annually', label: t('analytics.expenseAnalysis.reports.periods.semiAnnually') },
  { value: 'annually', label: t('analytics.expenseAnalysis.reports.periods.annually') },
]

function dimensionLabel(key) { return props.dimensions[key] || key }

const form = reactive({
  report_type: 'category_breakdown',
  date_from: oneYearAgo,
  date_to: today,
  period: 'monthly',
  category: '',
  dimension1: 'account',
  periods: [
    { label: t('analytics.expenseAnalysis.reports.periodA'), from: oneYearAgo, to: today },
    { label: t('analytics.expenseAnalysis.reports.periodB'), from: oneYearAgo, to: today },
  ],
})

const needsPeriod          = computed(() => form.report_type === 'trend')
const needsCategoryFilter  = computed(() => ['account_breakdown', 'trend'].includes(form.report_type))
const needsDimension1      = computed(() => ['min_avg_max', 'period_comparison'].includes(form.report_type))

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
    period: form.period,
    category: form.category || undefined,
    dimension1: form.dimension1,
    periods: form.report_type === 'period_comparison' ? form.periods : undefined,
  }
}

async function runReport() {
  if (!canSubmit.value) return
  loading.value = true
  try {
    const { data } = await axios.post(route('analytics.expenses.reports.run'), buildPayload())
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
  window.location.href = route('analytics.expenses.reports.export') + '?' + params.toString()
}

const donutData = computed(() => (result.value?.rows || []).map(r => ({ category: r.label, value: r.value })))
const trendChartData = computed(() => (result.value?.rows || []).map(r => ({ date: r.period, value: r.value })))

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.ea-report-type-group { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 900px) { .ea-report-type-group { grid-template-columns: repeat(2, 1fr); } }
.zv-tile-sub { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.d-block { display: block; }
.ea-period-row { align-items: end; margin-bottom: 0.75rem; }
.ea-period-remove { display: flex; align-items: end; }
</style>
