<!--
══════════════════════════════════════════════════════════════════
  Analytics/Expenses/Dashboard.vue

  Analytics > Expense Analysis > Dashboard — rebuilt September 2026
  (per Mahmoud) to match the Sales Dashboard's visual language
  exactly (same `fid-`/`sa-` class names, same navy/teal/gold
  palette) and to go well beyond the original KPI+donut+trend page:

    1. KPI cards         — total expense, revenue context, avg
                            monthly, category/account counts
    2. Run-Rate           — full-year expense projection from actuals
       Projection           so far (trend or seasonal method — see
                            ExpenseAnalysisService::runRateProjection
                            for the full methodology). Only shown for
                            a Jan-1-to-today range.
    3. Monthly Trend      — same MultiLineChart as the Sales Dashboard
    4. Category           — chart/table toggle donut, same share math
       Breakdown            as the Reports page
    5. Top Expense Items  — top 10 accounts as horizontal bars
    6. Min/Avg/Max per    — month-to-month volatility per P&L
       Category             category, with a visual range bar
    7. Analyst Notes      — same note system as the Sales Dashboard

  All data comes from the real general ledger (posted journal
  entries) via ExpenseAnalysisController's dashboardData() — nothing
  here depends on an upload.

  @layout    AppLayout
  @route     analytics.expenses.index
  @props     defaultFrom, defaultTo, hasData
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Analytics"
      :title="t('analytics.expenseAnalysis.dashboard.expenseDashboard')"
      :subtitle="t('analytics.expenseAnalysis.dashboard.overviewOfPostedExpenses')"
    >
      <template #actions>
        <Link :href="route('analytics.expenses.reports')" class="zv-btn zv-btn-primary">
          {{ t('analytics.expenseAnalysis.dashboard.viewFullReports') }}
        </Link>
      </template>
    </PageHeader>

    <div v-if="!hasData" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('analytics.expenseAnalysis.dashboard.noPostedExpensesEver') }}</div></div></div>

    <template v-else>
      <!-- ── Date Range ─────────────────────────────────────────── -->
      <div class="zv-card mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label">{{ t('common.startDate') }}</label>
              <input v-model="dateFrom" type="date" class="zv-input" :max="today" @change="load"/>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('common.endDate') }}</label>
              <input v-model="dateTo" type="date" class="zv-input" :max="today" @change="load"/>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('common.loading') }}</div></div></div>

      <div v-else-if="data && data.kpis.total_expense === 0" class="zv-card">
        <div class="zv-empty"><div class="zv-empty-title">{{ t('analytics.expenseAnalysis.dashboard.noExpensesInThisRange') }}</div></div>
      </div>

      <template v-else-if="data">

        <!-- ══════════════════════════════════════════════════════
             SECTION 1 — KPI CARDS
        ═══════════════════════════════════════════════════════ -->
        <div class="fid-kpi-row-3">
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-amber">📉</div>
            <div><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.totalExpense') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.kpis.total_expense) }}</p></div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-green">📈</div>
            <div><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.totalRevenue') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(data.kpis.total_revenue) }}</p></div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon" :class="ratioIconClass">%</div>
            <div><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.expenseToRevenue') }}</p><p class="fid-kpi-value" :class="ratioTextClass">{{ data.kpis.expense_pct_of_revenue }}%</p></div>
          </div>
        </div>
        <div class="fid-kpi-row-3 fid-mini">
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.avgMonthly') }}</p><p class="fid-kpi-value">{{ fmt(data.kpis.avg_monthly) }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.categories') }}</p><p class="fid-kpi-value">{{ data.kpis.category_count }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.expenseItems') }}</p><p class="fid-kpi-value">{{ data.kpis.account_count }}</p></div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 2 — RUN-RATE PROJECTION
        ═══════════════════════════════════════════════════════ -->
        <div v-if="data.run_rate.applicable" class="zv-card fid-chart-card mb-5">
          <div class="fid-chart-header">
            <div>
              <p class="fid-kpi-label">{{ t('analytics.expenseAnalysis.dashboard.runRateProjection', { year: data.run_rate.year }) }}</p>
              <p class="fid-muted mt-1">{{ t('analytics.expenseAnalysis.dashboard.runRateBasedOn', { months: data.run_rate.months_elapsed, asOf: data.run_rate.as_of }) }}</p>
            </div>
            <span class="ea-method-badge" :class="data.run_rate.method === 'seasonal' ? 'ea-method-seasonal' : 'ea-method-trend'">
              {{ data.run_rate.method === 'seasonal' ? '📅 ' + t('analytics.expenseAnalysis.dashboard.seasonalMethod') : '📈 ' + t('analytics.expenseAnalysis.dashboard.trendMethod') }}
            </span>
          </div>

          <div class="ea-runrate-grid mb-3">
            <div class="ea-runrate-box"><p class="fid-muted">{{ t('analytics.expenseAnalysis.dashboard.ytdActual') }}</p><p class="fid-kpi-value">{{ fmt(data.run_rate.ytd_actual) }}</p></div>
            <div class="ea-runrate-box"><p class="fid-muted">{{ t('analytics.expenseAnalysis.dashboard.projectedRemainder') }}</p><p class="fid-kpi-value">{{ fmt(data.run_rate.projected_remainder) }}</p></div>
            <div class="ea-runrate-box ea-runrate-box-wide"><p class="fid-muted">{{ t('analytics.expenseAnalysis.dashboard.projectedFullYear', { year: data.run_rate.year }) }}</p><p class="fid-kpi-value fid-num-amber" style="font-size:19px;">{{ fmt(data.run_rate.projected_full_year) }}</p></div>
          </div>

          <p v-if="data.run_rate.outlier_months_excluded.length" class="fid-muted mb-2">
            ⚠ {{ t('analytics.expenseAnalysis.dashboard.outlierNote', { months: data.run_rate.outlier_months_excluded.join(', ') }) }}
          </p>

          <div class="ea-methodology">
            <span class="ea-methodology-label">{{ t('analytics.expenseAnalysis.dashboard.howCalculated') }}</span>
            {{ methodologyText }}
            {{ t('analytics.expenseAnalysis.dashboard.ytdRangeOnly') }}
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 3 — MONTHLY TREND
        ═══════════════════════════════════════════════════════ -->
        <div class="zv-card fid-chart-card mb-5">
          <h4 class="fid-chart-title">{{ t('analytics.expenseAnalysis.dashboard.monthlyTrend') }}</h4>
          <MultiLineChart :data="trendChartData" :series-def="[{ field: 'value', name: t('analytics.expenseAnalysis.dashboard.totalExpense'), color: '#dc2626' }]" />
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 4 — CATEGORY BREAKDOWN
        ═══════════════════════════════════════════════════════ -->
        <div class="zv-card fid-chart-card mb-5">
          <div class="fid-chart-header">
            <h4 class="fid-chart-title">{{ t('analytics.expenseAnalysis.dashboard.categoryBreakdown') }}</h4>
            <div class="fid-tab-switch">
              <button class="fid-tab" :class="{ 'fid-tab-active': breakdownTab === 'chart' }" @click="breakdownTab = 'chart'">{{ t('analytics.expenseAnalysis.dashboard.chart') }}</button>
              <button class="fid-tab" :class="{ 'fid-tab-active': breakdownTab === 'table' }" @click="breakdownTab = 'table'">{{ t('analytics.expenseAnalysis.dashboard.table') }}</button>
            </div>
          </div>

          <DonutChart3D v-if="breakdownTab === 'chart'" :data="categoryDonutData" :colors="categoryColors" />

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.expenseAnalysis.dashboard.byCategory') }}</th><th class="fid-th-num">{{ t('analytics.expenseAnalysis.reports.amount') }}</th><th class="fid-th-num">{{ t('analytics.expenseAnalysis.dashboard.shareCol') }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in data.category_breakdown" :key="row.label">
                  <td><span class="ea-legend-dot" :style="{ background: categoryColors[i % categoryColors.length] }"></span>{{ row.label }}</td>
                  <td class="fid-num fid-num-amber">{{ fmt(row.value) }}</td>
                  <td class="fid-num fid-muted">{{ row.pct_of_expense }}%</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="fid-tfoot">
                  <td>{{ t('analytics.expenseAnalysis.dashboard.total') }}</td>
                  <td class="fid-num">{{ fmt(data.kpis.total_expense) }}</td>
                  <td class="fid-num fid-muted">100%</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 5 — TOP EXPENSE ITEMS
        ═══════════════════════════════════════════════════════ -->
        <div class="zv-card fid-chart-card mb-5">
          <h4 class="fid-chart-title mb-3">{{ t('analytics.expenseAnalysis.dashboard.topExpenseItems') }}</h4>
          <div class="ea-bar-list">
            <div v-for="item in data.top_items" :key="item.label" class="ea-bar-row">
              <div class="ea-bar-label">
                <span class="fid-muted">{{ categoryLabel(item.category) }}</span>
                <span class="ea-bar-name">{{ item.label }}</span>
              </div>
              <div class="ea-bar-track"><div class="ea-bar-fill" :style="{ width: Math.max(item.pct_of_expense, 1) + '%' }"></div></div>
              <div class="ea-bar-value">{{ fmt(item.value) }} <span class="fid-muted">{{ item.pct_of_expense }}%</span></div>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 6 — MIN / AVG / MAX PER CATEGORY
        ═══════════════════════════════════════════════════════ -->
        <div class="zv-card fid-chart-card mb-5">
          <h4 class="fid-chart-title mb-3">{{ t('analytics.expenseAnalysis.dashboard.minAvgMaxPerCategory') }}</h4>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('analytics.expenseAnalysis.reports.category') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.dashboard.total') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.reports.min') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.reports.avg') }}</th>
                  <th class="fid-th-num">{{ t('analytics.expenseAnalysis.reports.max') }}</th>
                  <th>{{ t('analytics.expenseAnalysis.dashboard.distribution') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in data.min_avg_max" :key="s.label">
                  <td>{{ s.label }}</td>
                  <td class="fid-num">{{ fmt(s.total) }}</td>
                  <td class="fid-num fid-num-green">{{ fmt(s.min) }}</td>
                  <td class="fid-num">{{ fmt(s.avg) }}</td>
                  <td class="fid-num fid-num-red">{{ fmt(s.max) }}</td>
                  <td style="min-width:140px;">
                    <div class="ea-range-track">
                      <div class="ea-range-fill" :style="{ left: minPct(s) + '%', width: rangePct(s) + '%' }"></div>
                      <div class="ea-range-avg" :style="{ left: avgPct(s) + '%' }"></div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 7 — ANALYST NOTES
        ═══════════════════════════════════════════════════════ -->
        <div class="fid-section-heading">
          {{ t('analytics.expenseAnalysis.dashboard.analystNotes') }}
          <span class="fid-muted" style="font-weight:400; text-transform:none; margin-inline-start:6px;">{{ t('analytics.expenseAnalysis.dashboard.savedForRange', { from: dateFrom, to: dateTo }) }}</span>
        </div>
        <div class="zv-card sa-notes-card mb-5">
          <div v-if="data.notes.length" class="sa-notes-list">
            <div v-for="n in data.notes" :key="n.id" class="sa-note-row">
              <div class="sa-note-head">
                <div class="sa-note-author">
                  <div class="sa-note-avatar">{{ (n.author || 'U').charAt(0).toUpperCase() }}</div>
                  <div>
                    <p class="sa-note-author-name">{{ n.author }}</p>
                    <p class="fid-muted">{{ n.updated_at ? new Date(n.updated_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '' }}</p>
                  </div>
                </div>
                <div v-if="n.author_id === currentUserId && editingNoteId !== n.id" class="sa-note-actions">
                  <button class="zv-btn zv-btn-ghost zv-btn-sm" @click="startEdit(n)">{{ t('analytics.expenseAnalysis.dashboard.editNote') }}</button>
                  <button class="zv-btn zv-btn-ghost zv-btn-sm sa-btn-danger" @click="deleteNote(n.id)">{{ t('analytics.expenseAnalysis.dashboard.deleteNote') }}</button>
                </div>
              </div>

              <div v-if="editingNoteId === n.id">
                <RichNoteEditor ref="editEditorRef" :initial-html="n.note" />
                <div class="sa-note-editor-actions">
                  <button class="zv-btn zv-btn-primary zv-btn-sm" @click="submitEdit(n.id)">{{ t('analytics.expenseAnalysis.dashboard.saveNote') }}</button>
                  <button class="zv-btn zv-btn-ghost zv-btn-sm" @click="editingNoteId = null">{{ t('analytics.expenseAnalysis.dashboard.cancelEdit') }}</button>
                </div>
              </div>
              <div v-else class="sa-note-body" v-html="n.note"></div>
            </div>
          </div>
          <div v-else class="fid-muted" style="padding: 14px 4px;">{{ t('analytics.expenseAnalysis.dashboard.noNotesYet') }}</div>

          <div class="sa-note-new">
            <RichNoteEditor ref="newEditorRef" :key="'new-' + dateFrom + dateTo" />
            <button class="zv-btn zv-btn-primary zv-btn-sm mt-2" @click="submitNewNote">{{ t('analytics.expenseAnalysis.dashboard.saveNote') }}</button>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref, computed, onMounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import PageHeader from '@/Components/PageHeader.vue'
import MultiLineChart from '@/Components/Charts/MultiLineChart.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'
import RichNoteEditor from '@/Components/RichNoteEditor.vue'

const { t } = useI18n()
const page = usePage()
const currentUserId = computed(() => page.props.auth?.user?.id)

const props = defineProps({
  defaultFrom: { type: String, default: '' },
  defaultTo:   { type: String, default: '' },
  hasData:     { type: Boolean, default: false },
})

const today = todayLocal()
const dateFrom = ref(props.defaultFrom)
const dateTo = ref(props.defaultTo)

const loading = ref(false)
const data = ref(null)

async function load() {
  if (!dateFrom.value || !dateTo.value) return
  loading.value = true
  try {
    const { data: response } = await axios.get(route('analytics.expenses.data'), {
      params: { date_from: dateFrom.value, date_to: dateTo.value },
    })
    data.value = response
  } finally {
    loading.value = false
  }
}

onMounted(() => { if (props.hasData) load() })

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }

// ── KPI ratio color ──────────────────────────────────────────────
const ratioIconClass = computed(() => {
  const r = data.value?.kpis?.expense_pct_of_revenue ?? 0
  return r > 80 ? 'fid-icon-red' : r > 60 ? 'fid-icon-amber' : 'fid-icon-green'
})
const ratioTextClass = computed(() => {
  const r = data.value?.kpis?.expense_pct_of_revenue ?? 0
  return r > 80 ? 'fid-num-red' : r > 60 ? 'fid-num-amber' : 'fid-num-green'
})

// ── Run-Rate methodology text ─────────────────────────────────────
const methodologyText = computed(() => {
  const rr = data.value?.run_rate
  if (!rr) return ''
  if (rr.method === 'seasonal') {
    return t('analytics.expenseAnalysis.dashboard.seasonalExplanation', {
      years: rr.years_of_history_used,
      yearList: (rr.seasonal_years || []).join(', '),
      pct: rr.seasonal_pct_elapsed_by_now,
      trendValue: fmt(rr.trend_method_full_year),
    })
  }
  return t('analytics.expenseAnalysis.dashboard.trendExplanation', {
    avg: fmt(rr.trailing_avg),
    months: (rr.trailing_months_used || []).join(', '),
  })
})

// ── Trend chart ────────────────────────────────────────────────────
const trendChartData = computed(() => (data.value?.trend || []).map(r => ({ date: r.period, value: r.value })))

// ── Category Breakdown ─────────────────────────────────────────────
const breakdownTab = ref('chart')
const categoryColors = ['#b45309', '#1490A8', '#7c3aed', '#0F2554', '#dc2626', '#16a34a', '#FFBF00', '#0891b2']
const categoryDonutData = computed(() => (data.value?.category_breakdown || []).map(r => ({ category: r.label, value: r.value })))

const categoryLabels = {
  'Cost of Goods Sold': 'COGS', 'Sales Expenses': 'Sales', 'Marketing Expenses': 'Marketing',
  'General & Administrative Expenses': 'G&A', 'Finance Expenses': 'Finance', 'Depreciation & Amortization': 'D&A',
}
function categoryLabel(c) { return categoryLabels[c] || c }

// ── Min/Avg/Max distribution bar ──────────────────────────────────
function rangeBounds() {
  const rows = data.value?.min_avg_max || []
  return { max: rows.length ? Math.max(...rows.map(r => r.max)) : 1 }
}
function minPct(s) { const { max } = rangeBounds(); return max > 0 ? (s.min / max) * 100 : 0 }
function avgPct(s) { const { max } = rangeBounds(); return max > 0 ? (s.avg / max) * 100 : 0 }
function rangePct(s) { const { max } = rangeBounds(); return max > 0 ? ((s.max - s.min) / max) * 100 : 0 }

// ── Analyst Notes ─────────────────────────────────────────────────
const newEditorRef = ref(null)
const editEditorRef = ref(null)
const editingNoteId = ref(null)

async function submitNewNote() {
  const html = newEditorRef.value?.getHtml()?.trim()
  if (!html) return
  await axios.post(route('analytics.expenses.notes.store'), { date_from: dateFrom.value, date_to: dateTo.value, note: html })
  await load()
}

function startEdit(note) { editingNoteId.value = note.id }

async function submitEdit(noteId) {
  const html = editEditorRef.value?.[0]?.getHtml?.() ?? editEditorRef.value?.getHtml?.()
  if (!html || !html.trim()) return
  await axios.put(route('analytics.expenses.notes.update', noteId), { note: html })
  editingNoteId.value = null
  await load()
}

async function deleteNote(noteId) {
  if (!confirm(t('analytics.expenseAnalysis.dashboard.confirmDeleteNote'))) return
  await axios.delete(route('analytics.expenses.notes.destroy', noteId))
  await load()
}
</script>

<!--
  Styles: the `fid-` block and the `sa-` notes classes are copied
  verbatim from Analytics/Sales/Dashboard.vue (each dashboard page in
  this codebase bundles its own copy — see that file's style-block
  comment for why). Only the `ea-` (Expense Analysis) classes below
  are new, for the run-rate card, bar list, and distribution bars.
-->
<style>
.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; }

.fid-kpi-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; }
.fid-mini { margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-3 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-3 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-mini-card { padding: 10px 14px; }
.fid-kpi-icon { font-size: 18px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0; }
.fid-icon-blue { background: rgba(20,144,168,0.12); }
.fid-icon-amber { background: rgba(180,83,9,0.12); }
.fid-icon-green { background: rgba(22,163,74,0.12); }
.fid-icon-red { background: rgba(220,38,38,0.12); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-num-blue { color: #1490A8; }
.fid-num-amber { color: #b45309; }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }

.fid-chart-card { padding: 14px; }
.fid-chart-title { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); margin-bottom: 8px; }
.fid-chart-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }

.fid-tab-switch { display: flex; background: var(--zv-bg-surface-alt, #f3f4f6); border-radius: 8px; padding: 2px; }
.fid-tab { font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: 6px; color: var(--zv-text-muted, #6b7280); }
.fid-tab-active { background: #fff; color: var(--zv-navy, #0F2554); box-shadow: 0 1px 2px rgba(0,0,0,0.08); }

.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; }
.fid-tfoot td { border-top: 2px solid var(--zv-border, #e5e7eb); font-weight: 700; padding-top: 8px; }

/* ── Run-Rate Projection ── */
.ea-method-badge { font-size: 11px; font-weight: 600; padding: 5px 10px; border-radius: 999px; white-space: nowrap; }
.ea-method-seasonal { background: rgba(20,144,168,0.14); color: #0e6e7f; }
.ea-method-trend { background: rgba(255,191,0,0.18); color: #92600a; }
.ea-runrate-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; }
@media (max-width: 700px) { .ea-runrate-grid { grid-template-columns: repeat(2, 1fr); } }
.ea-runrate-box { background: var(--zv-bg-surface-alt, #f9fafb); border-radius: 10px; padding: 12px 14px; }
.ea-runrate-box-wide { grid-column: span 2; }
.ea-methodology { font-size: 12px; color: var(--zv-text-muted, #6b7280); line-height: 1.6; background: var(--zv-bg-surface-alt, #f9fafb); border-radius: 8px; padding: 10px 12px; }
.ea-methodology-label { font-weight: 700; color: var(--zv-navy, #0F2554); margin-inline-end: 4px; }

/* ── Category Breakdown legend dot ── */
.ea-legend-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-inline-end: 8px; }

/* ── Top Expense Items bars ── */
.ea-bar-list { display: flex; flex-direction: column; gap: 10px; }
.ea-bar-row { display: grid; grid-template-columns: 160px 1fr 130px; gap: 12px; align-items: center; }
@media (max-width: 640px) { .ea-bar-row { grid-template-columns: 100px 1fr 90px; } }
.ea-bar-label { text-align: end; font-size: 12px; overflow: hidden; }
.ea-bar-name { display: block; color: var(--zv-navy, #0F2554); font-weight: 600; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
.ea-bar-track { height: 14px; background: var(--zv-bg-surface-alt, #f3f4f6); border-radius: 999px; overflow: hidden; }
.ea-bar-fill { height: 100%; background: linear-gradient(90deg, #b45309, #dc2626); border-radius: 999px; }
.ea-bar-value { font-size: 12px; text-align: end; white-space: nowrap; }

/* ── Min/Avg/Max distribution bar ── */
.ea-range-track { position: relative; height: 14px; background: var(--zv-bg-surface-alt, #f3f4f6); border-radius: 999px; overflow: hidden; }
.ea-range-fill { position: absolute; height: 100%; background: rgba(20,144,168,0.35); border-radius: 999px; }
.ea-range-avg { position: absolute; width: 2px; height: 100%; background: #1490A8; }

/* ── Analyst Notes (identical to Sales Dashboard) ── */
.sa-notes-card { padding: 0; }
.sa-notes-list { border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.sa-note-row { padding: 16px 18px; border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.sa-note-row:last-child { border-bottom: none; }
.sa-note-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.sa-note-author { display: flex; gap: 8px; align-items: center; }
.sa-note-avatar { width: 28px; height: 28px; border-radius: 50%; background: #1490A8; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.sa-note-author-name { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); }
.sa-note-actions { display: flex; gap: 6px; }
.sa-btn-danger { color: #dc2626 !important; }
.sa-note-body { font-size: 13.5px; line-height: 1.6; color: var(--zv-text-primary, #1f2937); }
.sa-note-new { padding: 16px 18px; }
.sa-note-editor-actions { display: flex; gap: 8px; margin-top: 8px; }
</style>
