<!--
══════════════════════════════════════════════════════════════════
  Analytics/Sales/Dashboard.vue

  Analytics > Sales Analysis > Dashboard — full CFO-Tools-parity
  rebuild (September 2026, per Mahmoud). Five sections, all reading
  real posted Customer Invoices via SalesAnalysisController's
  dashboardData()/achieverTakeaway() endpoints (AJAX):

    1. Sales Update       — KPI cards (current month / last 3 months
                             / YTD, each vs. the prior equivalent
                             period) + the monthly trend chart
    2. Sales Breakdown     — one donut+table card per dimension
       Analysis              (chart/table toggle, same share/accum
                             math as the Reports page)
    3. Top Achievers       — top item per dimension, each with a
                             "Takeaways" button opening a ranked
                             drill-down popup
    4. Customer Nature     — New / Repeating / Active / Stop / Dead /
       Analysis              Reactivated buckets, click any tile for
                             the customer list behind it
    5. Analyst Notes       — free-text commentary saved per date
                             range, own-note edit/delete only

  Auto Insights & Alerts (CFO Tools' 5th section) is intentionally
  NOT built here — skipped per Mahmoud's explicit instruction.

  Visual language deliberately copied from Accounting/Dashboard/
  Index.vue and the Financial Institutions Dashboard — same `fid-`
  class names, same navy/teal/gold palette — NOT CFO Tools' dark
  theme. Content parity, not color parity.

  @layout    AppLayout
  @route     analytics.sales.index
  @props     defaultFrom, defaultTo, hasData
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="Analytics"
      :title="t('analytics.salesAnalysis.dashboard.salesDashboard')"
      :subtitle="t('analytics.salesAnalysis.dashboard.overviewOfPostedSales')"
    >
      <template #actions>
        <Link :href="route('analytics.sales.reports')" class="zv-btn zv-btn-primary">
          {{ t('analytics.salesAnalysis.dashboard.viewFullReports') }}
        </Link>
      </template>
    </PageHeader>

    <div v-if="!hasData" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('analytics.salesAnalysis.dashboard.noPostedInvoices') }}</div></div></div>

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

      <template v-else-if="data">

        <!-- ══════════════════════════════════════════════════════
             SECTION 1 — SALES UPDATE
        ═══════════════════════════════════════════════════════ -->
        <div class="fid-section-heading">{{ t('analytics.salesAnalysis.dashboard.salesUpdate') }}</div>
        <div class="fid-kpi-row-3">
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-blue">📅</div>
            <div>
              <p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.currentMonth') }} <span class="fid-kpi-note">{{ su.current_month_label }}</span></p>
              <p class="fid-kpi-value fid-num-blue">{{ fmt(su.current_month_value) }}</p>
              <p v-if="su.current_month_growth !== null" class="fid-kpi-note" :class="growthClass(su.current_month_growth)">{{ growthArrow(su.current_month_growth) }} {{ Math.abs(su.current_month_growth) }}% {{ t('analytics.salesAnalysis.dashboard.vsPriorPeriod') }}</p>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-blue">📊</div>
            <div>
              <p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.last3Months') }}</p>
              <p class="fid-kpi-value fid-num-blue">{{ fmt(su.last_3_months_value) }}</p>
              <p v-if="su.last_3_months_growth !== null" class="fid-kpi-note" :class="growthClass(su.last_3_months_growth)">{{ growthArrow(su.last_3_months_growth) }} {{ Math.abs(su.last_3_months_growth) }}% {{ t('analytics.salesAnalysis.dashboard.vsPriorPeriod') }}</p>
            </div>
          </div>
          <div class="fid-kpi-card">
            <div class="fid-kpi-icon fid-icon-green">📈</div>
            <div>
              <p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.yearToDate') }}</p>
              <p class="fid-kpi-value fid-num-green">{{ fmt(su.ytd_value) }}</p>
              <p class="fid-kpi-note">{{ t('analytics.salesAnalysis.dashboard.avgMonthly') }}: {{ fmt(su.ytd_avg_monthly) }}</p>
            </div>
          </div>
        </div>
        <div class="fid-kpi-row-4 fid-mini">
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.netSales') }}</p><p class="fid-kpi-value">{{ fmt(su.total_sales) }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.totalInvoices') }}</p><p class="fid-kpi-value">{{ su.total_invoices }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.avgTransaction') }}</p><p class="fid-kpi-value">{{ fmt(su.avg_transaction) }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.uniqueCustomers') }}</p><p class="fid-kpi-value">{{ su.unique_customers }}</p></div>
        </div>
        <div class="fid-kpi-row-4 fid-mini">
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.bestMonth') }} <span class="fid-kpi-note">{{ su.best_month_label }}</span></p><p class="fid-kpi-value fid-num-green">{{ fmt(su.best_month_value) }}</p></div>
          <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.worstMonth') }} <span class="fid-kpi-note">{{ su.worst_month_label }}</span></p><p class="fid-kpi-value fid-num-amber">{{ fmt(su.worst_month_value) }}</p></div>
        </div>

        <div class="zv-card fid-chart-card mb-5">
          <h4 class="fid-chart-title">{{ t('analytics.salesAnalysis.dashboard.monthlyTrend') }}</h4>
          <MultiLineChart :data="trendChartData" :series-def="[{ field: 'value', name: t('analytics.salesAnalysis.dashboard.netSales'), color: '#1490A8' }]" />
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 2 — SALES BREAKDOWN ANALYSIS
        ═══════════════════════════════════════════════════════ -->
        <template v-if="data.breakdowns.length">
          <div class="fid-section-heading">{{ t('analytics.salesAnalysis.dashboard.salesBreakdownAnalysis') }}</div>
          <div class="sa-breakdown-grid mb-5">
            <div v-for="bd in data.breakdowns" :key="bd.dimension" class="zv-card fid-chart-card">
              <div class="fid-chart-header">
                <h4 class="fid-chart-title">{{ bd.label }}</h4>
                <div class="fid-tab-switch">
                  <button class="fid-tab" :class="{ 'fid-tab-active': tab(bd.dimension) === 'chart' }" @click="setTab(bd.dimension, 'chart')">{{ t('analytics.salesAnalysis.dashboard.chart') }}</button>
                  <button class="fid-tab" :class="{ 'fid-tab-active': tab(bd.dimension) === 'table' }" @click="setTab(bd.dimension, 'table')">{{ t('analytics.salesAnalysis.dashboard.table') }}</button>
                </div>
              </div>

              <DonutChart3D v-if="tab(bd.dimension) === 'chart'" :data="donutData(bd.rows)" :colors="breakdownColors" />

              <div v-else class="zv-table-wrap">
                <table class="zv-table">
                  <thead><tr><th>{{ bd.label }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.netSalesCol') }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.shareCol') }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.accumCol') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="row in bd.rows" :key="row.label">
                      <td>{{ row.label }}</td>
                      <td class="fid-num fid-num-green">{{ fmt(row.value) }}</td>
                      <td class="fid-num fid-muted">{{ row.share }}%</td>
                      <td class="fid-num">{{ row.accumulated_share }}%</td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="fid-tfoot">
                      <td>{{ t('analytics.salesAnalysis.dashboard.total') }}</td>
                      <td class="fid-num">{{ fmt(bd.rows.reduce((s, r) => s + Number(r.value), 0)) }}</td>
                      <td class="fid-num fid-muted">100%</td>
                      <td class="fid-num">100%</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>
        </template>

        <!-- ══════════════════════════════════════════════════════
             SECTION 3 — TOP ACHIEVERS
        ═══════════════════════════════════════════════════════ -->
        <template v-if="data.top_achievers.length">
          <div class="fid-section-heading">{{ t('analytics.salesAnalysis.dashboard.topAchievers') }}</div>
          <div class="sa-achiever-grid mb-5">
            <div v-for="a in data.top_achievers" :key="a.dimension" class="zv-card sa-achiever-card">
              <div class="sa-achiever-head">
                <p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.topOf', { label: a.label }) }}</p>
                <button class="zv-btn zv-btn-ghost zv-btn-sm" @click="openTakeaway(a)">{{ t('analytics.salesAnalysis.dashboard.takeaways') }}</button>
              </div>
              <p class="sa-achiever-name">{{ a.top_label }}</p>
              <p class="fid-kpi-value fid-num-green">{{ fmt(a.top_value) }}</p>
              <div class="sa-progress"><div class="sa-progress-fill"></div></div>
              <p class="fid-kpi-note">{{ a.total_items }} {{ t('analytics.salesAnalysis.dashboard.totalItems', { label: a.label.toLowerCase() }) }}</p>
            </div>
          </div>
        </template>

        <!-- ══════════════════════════════════════════════════════
             SECTION 4 — CUSTOMER NATURE ANALYSIS
        ═══════════════════════════════════════════════════════ -->
        <div class="fid-section-heading">{{ t('analytics.salesAnalysis.dashboard.customerNatureAnalysis') }}</div>
        <div class="sa-nature-grid mb-3">
          <div v-for="key in natureOrder" :key="key" class="sa-nature-tile" :class="'sa-nature-' + key" @click="openNaturePopup(key)">
            <p class="sa-nature-label">{{ t('analytics.salesAnalysis.reports.natureBuckets.' + key) }}</p>
            <p class="sa-nature-count">{{ data.customer_nature.buckets[key]?.count ?? 0 }}</p>
            <p class="sa-nature-value">{{ fmt(data.customer_nature.buckets[key]?.value) }}</p>
            <p class="fid-muted sa-nature-hint">{{ t('analytics.salesAnalysis.dashboard.clickForDetails') }}</p>
          </div>
        </div>
        <div class="zv-card fid-chart-card mb-5 sa-retention-bar">
          <div><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.retentionRate') }}</p><p class="fid-kpi-value fid-num-green">{{ retentionRate }}%</p></div>
          <div><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.churnDead') }}</p><p class="fid-kpi-value fid-num-red">{{ data.customer_nature.buckets.dead?.count ?? 0 }}</p></div>
          <div><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.reactivated') }}</p><p class="fid-kpi-value">{{ (data.customer_nature.buckets.stop_reactivated?.count ?? 0) + (data.customer_nature.buckets.dead_reactivated?.count ?? 0) }}</p></div>
          <div><p class="fid-kpi-label">{{ t('analytics.salesAnalysis.dashboard.newThisYear') }}</p><p class="fid-kpi-value fid-num-blue">{{ data.customer_nature.buckets.new?.count ?? 0 }}</p></div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             SECTION 5 — ANALYST NOTES
        ═══════════════════════════════════════════════════════ -->
        <div class="fid-section-heading">
          {{ t('analytics.salesAnalysis.dashboard.analystNotes') }}
          <span class="fid-muted" style="font-weight:400; text-transform:none; margin-inline-start:6px;">{{ t('analytics.salesAnalysis.dashboard.savedForRange', { from: dateFrom, to: dateTo }) }}</span>
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
                  <button class="zv-btn zv-btn-ghost zv-btn-sm" @click="startEdit(n)">{{ t('analytics.salesAnalysis.dashboard.editNote') }}</button>
                  <button class="zv-btn zv-btn-ghost zv-btn-sm sa-btn-danger" @click="deleteNote(n.id)">{{ t('analytics.salesAnalysis.dashboard.deleteNote') }}</button>
                </div>
              </div>

              <div v-if="editingNoteId === n.id">
                <RichNoteEditor ref="editEditorRef" :initial-html="n.note" />
                <div class="sa-note-editor-actions">
                  <button class="zv-btn zv-btn-primary zv-btn-sm" @click="submitEdit(n.id)">{{ t('analytics.salesAnalysis.dashboard.saveNote') }}</button>
                  <button class="zv-btn zv-btn-ghost zv-btn-sm" @click="editingNoteId = null">{{ t('analytics.salesAnalysis.dashboard.cancelEdit') }}</button>
                </div>
              </div>
              <div v-else class="sa-note-body" v-html="n.note"></div>
            </div>
          </div>
          <div v-else class="fid-muted" style="padding: 14px 4px;">{{ t('analytics.salesAnalysis.dashboard.noNotesYet') }}</div>

          <div class="sa-note-new">
            <RichNoteEditor ref="newEditorRef" :key="'new-' + dateFrom + dateTo" />
            <button class="zv-btn zv-btn-primary zv-btn-sm mt-2" @click="submitNewNote">{{ t('analytics.salesAnalysis.dashboard.saveNote') }}</button>
          </div>
        </div>
      </template>
    </template>

    <!-- ── TAKEAWAY POPUP ─────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="takeawayPopup" class="sa-modal-overlay" @click.self="takeawayPopup = null">
        <div class="sa-modal">
          <div class="sa-modal-head">
            <div>
              <p class="sa-modal-title">{{ takeawaySelected || t('analytics.salesAnalysis.dashboard.topOf', { label: takeawayPopup.label }) }}</p>
              <p class="fid-muted">{{ takeawaySelected ? takeawayPopup.label : takeawayPopup.top_label + ' — ' + fmt(takeawayPopup.top_value) }}</p>
            </div>
            <button class="sa-modal-close" @click="takeawayPopup = null">✕</button>
          </div>
          <div class="sa-modal-body">
            <div v-if="takeawayLoading" class="fid-muted" style="text-align:center; padding:24px;">{{ t('common.loading') }}</div>
            <template v-else-if="takeawayData">
              <button v-if="takeawaySelected" class="sa-breadcrumb-back" @click="resetTakeaway">← {{ t('analytics.salesAnalysis.dashboard.allOf', { label: takeawayPopup.label }) }}</button>
              <p class="fid-kpi-label mb-2">{{ t('analytics.salesAnalysis.dashboard.keyStats') }}</p>
              <div class="sa-stats-grid mb-4">
                <div v-for="s in takeawayData.stats" :key="s.label" class="sa-stat-box">
                  <p class="fid-muted">{{ s.label }}</p>
                  <p class="fid-kpi-value">{{ s.value }}</p>
                </div>
              </div>
              <p class="fid-muted mb-2">{{ takeawaySelected ? t('analytics.salesAnalysis.dashboard.backToFullRanking') : t('analytics.salesAnalysis.dashboard.clickRowToDrillDown') }}</p>
              <table class="zv-table">
                <thead><tr><th>#</th><th>{{ takeawayPopup.label }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.netSalesCol') }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.shareCol') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(row, i) in takeawayData.ranking" :key="row.label" class="fid-row" :class="{ 'sa-row-selected': takeawaySelected === row.label }" @click="drillDown(row.label)">
                    <td class="fid-muted">{{ i + 1 }}</td>
                    <td>{{ row.label }}</td>
                    <td class="fid-num fid-num-green">{{ fmt(row.value) }}</td>
                    <td class="fid-num fid-muted">{{ row.pct }}%</td>
                  </tr>
                </tbody>
              </table>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── CUSTOMER NATURE POPUP ──────────────────────────────── -->
    <Teleport to="body">
      <div v-if="naturePopup" class="sa-modal-overlay" @click.self="naturePopup = null">
        <div class="sa-modal">
          <div class="sa-modal-head">
            <div>
              <p class="sa-modal-title">{{ t('analytics.salesAnalysis.reports.natureBuckets.' + naturePopup.key) }}</p>
              <p class="fid-muted">{{ t('analytics.salesAnalysis.dashboard.customersCount', { count: naturePopup.count, total: fmt(naturePopup.value) }) }}</p>
            </div>
            <button class="sa-modal-close" @click="naturePopup = null">✕</button>
          </div>
          <div v-if="naturePopup.is_past_period" class="sa-past-period-banner">
            {{ t('analytics.salesAnalysis.dashboard.salesFromYear', { year: naturePopup.sales_year }) }}
          </div>
          <div class="sa-modal-body">
            <table class="zv-table">
              <thead><tr><th>{{ t('analytics.salesAnalysis.reports.customer') }}</th><th class="fid-th-num">{{ naturePopup.is_past_period ? t('analytics.salesAnalysis.dashboard.salesFromYear', { year: naturePopup.sales_year }) : t('analytics.salesAnalysis.dashboard.netSalesCol') }}</th><th class="fid-th-num">{{ t('analytics.salesAnalysis.dashboard.shareCol') }}</th></tr></thead>
              <tbody>
                <tr v-for="c in naturePopup.customer_rows" :key="c.name">
                  <td>{{ c.name }}</td>
                  <td class="fid-num fid-num-green">{{ fmt(c.value) }}</td>
                  <td class="fid-num fid-muted">{{ c.pct }}%</td>
                </tr>
                <tr v-if="!naturePopup.customer_rows.length"><td colspan="3" class="fid-muted" style="text-align:center;">—</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref, reactive, computed, onMounted } from 'vue'
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
    const { data: response } = await axios.get(route('analytics.sales.data'), {
      params: { date_from: dateFrom.value, date_to: dateTo.value },
    })
    data.value = response
  } finally {
    loading.value = false
  }
}

onMounted(() => { if (props.hasData) load() })

const su = computed(() => data.value?.sales_update || {})
const trendChartData = computed(() => (su.value.trend || []).map(r => ({ date: r.period, value: r.value })))

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }
function growthClass(v) { return Number(v) >= 0 ? 'fid-num-green' : 'fid-num-red' }
function growthArrow(v) { return Number(v) >= 0 ? '▲' : '▼' }

// ── Breakdown chart/table toggle (per card) ─────────────────────
const breakdownTabs = reactive({})
function tab(dim) { return breakdownTabs[dim] || 'chart' }
function setTab(dim, val) { breakdownTabs[dim] = val }
function donutData(rows) { return rows.map(r => ({ category: r.label, value: r.value })) }
const breakdownColors = ['#1490A8', '#0F2554', '#16a34a', '#b45309', '#7c3aed', '#dc2626', '#FFBF00', '#0891b2']

// ── Top Achievers — Takeaways popup ─────────────────────────────
const takeawayPopup = ref(null)
const takeawayLoading = ref(false)
const takeawayData = ref(null)
const takeawaySelected = ref(null)

async function openTakeaway(achiever) {
  takeawayPopup.value = achiever
  takeawaySelected.value = null
  await fetchTakeaway()
}

async function fetchTakeaway() {
  takeawayLoading.value = true
  try {
    const { data: response } = await axios.get(route('analytics.sales.achiever-takeaway'), {
      params: {
        dimension: takeawayPopup.value.dimension,
        date_from: dateFrom.value,
        date_to: dateTo.value,
        selected_label: takeawaySelected.value || undefined,
      },
    })
    takeawayData.value = response
  } finally {
    takeawayLoading.value = false
  }
}

function drillDown(label) { takeawaySelected.value = label; fetchTakeaway() }
function resetTakeaway() { takeawaySelected.value = null; fetchTakeaway() }

// ── Customer Nature popup ────────────────────────────────────────
const natureOrder = ['new', 'repeating', 'active', 'stop', 'dead', 'stop_reactivated', 'dead_reactivated']
const naturePopup = ref(null)

function openNaturePopup(key) {
  const bucket = data.value.customer_nature.buckets[key]
  if (!bucket) return
  naturePopup.value = { key, ...bucket }
}

const retentionRate = computed(() => {
  const buckets = data.value?.customer_nature?.buckets || {}
  const retained = (buckets.repeating?.count || 0) + (buckets.active?.count || 0)
  const total = Object.values(buckets).reduce((s, b) => s + (b.count || 0), 0)
  return total > 0 ? ((retained / total) * 100).toFixed(1) : '0.0'
})

// ── Analyst Notes ─────────────────────────────────────────────────
const newEditorRef = ref(null)
const editEditorRef = ref(null)
const editingNoteId = ref(null)

async function submitNewNote() {
  const html = newEditorRef.value?.getHtml()?.trim()
  if (!html) return
  await axios.post(route('analytics.sales.notes.store'), { date_from: dateFrom.value, date_to: dateTo.value, note: html })
  await load()
}

function startEdit(note) { editingNoteId.value = note.id }

async function submitEdit(noteId) {
  const html = editEditorRef.value?.[0]?.getHtml?.() ?? editEditorRef.value?.getHtml?.()
  if (!html || !html.trim()) return
  await axios.put(route('analytics.sales.notes.update', noteId), { note: html })
  editingNoteId.value = null
  await load()
}

async function deleteNote(noteId) {
  if (!confirm(t('analytics.salesAnalysis.dashboard.confirmDeleteNote'))) return
  await axios.delete(route('analytics.sales.notes.destroy', noteId))
  await load()
}
</script>

<!--
  Styles copied from Accounting/Dashboard/Index.vue's `fid-` block
  (itself copied from Financial Institutions Dashboard) plus a small
  `sa-` (Sales Analysis) set for the pieces unique to this page —
  breakdown card grid, achiever cards, nature tiles, notes list,
  and the shared modal shell for the two popups.
-->
<style>
.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; }

.fid-kpi-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; }
.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
.fid-mini { margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-3, .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-3, .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-mini-card { padding: 10px 14px; }
.fid-kpi-icon { font-size: 18px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0; }
.fid-icon-blue { background: rgba(20,144,168,0.12); }
.fid-icon-amber { background: rgba(180,83,9,0.12); }
.fid-icon-green { background: rgba(22,163,74,0.12); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-kpi-note { font-size: 10.5px; color: var(--zv-text-muted, #6b7280); margin-top: 2px; }
.fid-num-blue { color: #1490A8; }
.fid-num-amber { color: #b45309; }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }

.fid-chart-card { padding: 14px; }
.fid-chart-title { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); margin-bottom: 8px; }
.fid-chart-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }

.fid-tab-switch { display: flex; background: var(--zv-bg-surface-alt, #f3f4f6); border-radius: 8px; padding: 2px; }
.fid-tab { font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: 6px; color: var(--zv-text-muted, #6b7280); }
.fid-tab-active { background: #fff; color: var(--zv-navy, #0F2554); box-shadow: 0 1px 2px rgba(0,0,0,0.08); }

.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; }
.fid-row { cursor: pointer; }
.fid-row:hover { background: var(--zv-bg-surface-alt, #f9fafb); }
.fid-tfoot td { border-top: 2px solid var(--zv-border, #e5e7eb); font-weight: 700; padding-top: 8px; }

/* ── Sales Breakdown Analysis grid ── */
.sa-breakdown-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 900px) { .sa-breakdown-grid { grid-template-columns: 1fr; } }

/* ── Top Achievers ── */
.sa-achiever-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
@media (max-width: 900px) { .sa-achiever-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .sa-achiever-grid { grid-template-columns: 1fr; } }
.sa-achiever-card { padding: 16px; }
.sa-achiever-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.sa-achiever-name { font-size: 16px; font-weight: 700; color: var(--zv-navy, #0F2554); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sa-progress { height: 5px; background: var(--zv-bg-surface-alt, #f3f4f6); border-radius: 4px; overflow: hidden; margin: 8px 0 6px; }
.sa-progress-fill { height: 100%; width: 100%; background: linear-gradient(90deg, #1490A8, #16a34a); }

/* ── Customer Nature tiles ── */
.sa-nature-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; }
@media (max-width: 900px) { .sa-nature-grid { grid-template-columns: repeat(2, 1fr); } }
.sa-nature-tile { border-radius: 10px; border: 1px solid; padding: 14px; cursor: pointer; transition: opacity .15s; }
.sa-nature-tile:hover { opacity: 0.85; }
.sa-nature-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px; }
.sa-nature-count { font-size: 24px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.sa-nature-value { font-size: 12px; font-weight: 600; margin-top: 4px; }
.sa-nature-hint { margin-top: 4px; }
.sa-nature-new { background: rgba(20,144,168,0.08); border-color: #1490A8; } .sa-nature-new .sa-nature-label, .sa-nature-new .sa-nature-value { color: #1490A8; }
.sa-nature-repeating { background: rgba(22,163,74,0.08); border-color: #16a34a; } .sa-nature-repeating .sa-nature-label, .sa-nature-repeating .sa-nature-value { color: #16a34a; }
.sa-nature-active { background: rgba(22,163,74,0.14); border-color: #16a34a; } .sa-nature-active .sa-nature-label, .sa-nature-active .sa-nature-value { color: #15803d; }
.sa-nature-stop { background: rgba(180,83,9,0.08); border-color: #b45309; } .sa-nature-stop .sa-nature-label, .sa-nature-stop .sa-nature-value { color: #b45309; }
.sa-nature-dead { background: rgba(220,38,38,0.08); border-color: #dc2626; } .sa-nature-dead .sa-nature-label, .sa-nature-dead .sa-nature-value { color: #dc2626; }
.sa-nature-stop_reactivated { background: rgba(255,191,0,0.14); border-color: #b45309; } .sa-nature-stop_reactivated .sa-nature-label, .sa-nature-stop_reactivated .sa-nature-value { color: #92600a; }
.sa-nature-dead_reactivated { background: rgba(124,58,237,0.08); border-color: #7c3aed; } .sa-nature-dead_reactivated .sa-nature-label, .sa-nature-dead_reactivated .sa-nature-value { color: #7c3aed; }
.sa-retention-bar { display: flex; gap: 2rem; flex-wrap: wrap; align-items: center; }

/* ── Analyst Notes ── */
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

/* ── Shared modal shell (Takeaway + Nature popups) ── */
.sa-modal-overlay { position: fixed; inset: 0; background: rgba(15,37,84,0.55); display: flex; align-items: center; justify-content: center; z-index: 60; padding: 1rem; }
.sa-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 640px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.sa-modal-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 18px 20px; border-bottom: 1px solid var(--zv-border, #e5e7eb); flex-shrink: 0; }
.sa-modal-title { font-size: 16px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.sa-modal-close { width: 30px; height: 30px; border-radius: 8px; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-muted, #6b7280); flex-shrink: 0; }
.sa-modal-body { padding: 18px 20px; overflow-y: auto; }
.sa-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.sa-stat-box { background: var(--zv-bg-surface-alt, #f9fafb); border-radius: 10px; padding: 10px 12px; }
.sa-breadcrumb-back { font-size: 12px; font-weight: 600; color: #1490A8; margin-bottom: 12px; }
.sa-row-selected { background: rgba(20,144,168,0.10); }
.sa-past-period-banner { background: rgba(180,83,9,0.10); color: #92600a; font-size: 12px; padding: 10px 20px; border-bottom: 1px solid rgba(180,83,9,0.25); }
</style>
