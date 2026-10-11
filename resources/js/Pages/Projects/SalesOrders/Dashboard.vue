<!--
══════════════════════════════════════════════════════════════════
  Projects/SalesOrders/Dashboard.vue

  Sales Orders Dashboard (September 2026, per Mahmoud — "same
  design and colors as the Accounting Dashboard"). Single scrolling
  page: backlog overview, status mix, invoicing progress, and
  full-drill-down Top Sales Orders / Top Customers tables. Same
  `fid-` classes as Accounting/Dashboard/Index.vue and the Financial
  Institutions Dashboard — byte-for-byte the same visual language,
  not a close re-creation.

  Two full datasets ("This Month" / "Year to Date") arrive together
  in one payload from SalesOrderDashboardService — EVERY section
  (backlog, invoicing progress, status mix, top orders/customers) is
  scoped to sales orders issued within the selected period, so
  toggling the pill genuinely changes the whole page, not just one
  card (fixed September 2026 — the first version only scoped the
  small "New Sales Orders" figure, which made the toggle look broken
  since nothing else on the page moved).

  Top Sales Orders / Top Customers rows are a real drill-down: each
  links straight to the existing Sales Orders list (filtered by
  partner, or opened directly on that order's own Edit page) rather
  than re-implementing that page's own detail a second time.

  @layout    AppLayout
  @route     projects.sales-orders.dashboard
  @props     data, urls — see SalesOrderDashboardController::index()
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">
    <PageHeader
      eyebrow="Projects & Contracts"
      :title="t('projects.salesOrdersDashboard.index.salesOrdersDashboard')"
      :subtitle="t('projects.salesOrdersDashboard.index.subtitle')"
    />

    <!-- ── Period toggle ─────────────────────────────────────── -->
    <div class="fid-pill-row">
      <button class="fid-pill" :class="{ 'fid-pill-active': period === 'month' }" @click="period = 'month'">{{ data.periods.month }}</button>
      <button class="fid-pill" :class="{ 'fid-pill-active': period === 'ytd' }" @click="period = 'ytd'">{{ t('projects.salesOrdersDashboard.index.yearToDate') }}</button>
    </div>

    <!-- ── Overview KPI row ──────────────────────────────────── -->
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-blue">📦</div>
        <div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.openSalesOrders') }}</p><p class="fid-kpi-value fid-num-blue">{{ current.overview.open_order_count }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-green">📈</div>
        <div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.totalBacklogValue') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(current.overview.total_backlog_value) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-amber">⏳</div>
        <div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.remainingToInvoice') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(current.overview.total_remaining) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-blue">🆕</div>
        <div>
          <p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.newSalesOrders') }}</p>
          <p class="fid-kpi-value fid-num-blue">{{ fmt(current.overview.issued_value) }}</p>
          <p class="fid-kpi-note">{{ current.overview.issued_count }} {{ t('projects.salesOrdersDashboard.index.orders') }}</p>
        </div>
      </div>
    </div>

    <!-- ── Invoicing Progress ────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('projects.salesOrdersDashboard.index.invoicingProgress') }}</div>
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">💼</div><div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.totalOpenValue') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(current.invoicing.total_value) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">✅</div><div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.invoiced') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(current.invoicing.invoiced) }} <span class="fid-kpi-note">({{ current.invoicing.pct_invoiced }}%)</span></p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">⏳</div><div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.remaining') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(current.invoicing.remaining) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">%</div><div><p class="fid-kpi-label">{{ t('projects.salesOrdersDashboard.index.pctInvoiced') }}</p><p class="fid-kpi-value fid-num-green">{{ current.invoicing.pct_invoiced }}%</p></div></div>
    </div>
    <div class="fid-chart-grid-2">
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('projects.salesOrdersDashboard.index.invoicedVsRemaining') }}</h4>
        <DonutChart3D :key="period + '-inv'" :data="current.invoicing.donut" :colors="invoicingColors" />
      </div>
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('projects.salesOrdersDashboard.index.statusMix') }}</h4>
        <DonutChart3D :key="period + '-status'" :data="statusDonutData" :colors="statusColors" />
      </div>
    </div>

    <!-- ── Status breakdown table ────────────────────────────── -->
    <div class="zv-card fid-chart-card" style="margin-bottom: 1rem;">
      <table class="zv-table">
        <thead><tr><th>{{ t('projects.salesOrdersDashboard.index.status') }}</th><th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.count') }}</th><th class="fid-th-num">{{ t('common.amount') }}</th></tr></thead>
        <tbody>
          <tr v-for="row in current.status_mix" :key="row.status" class="fid-row" @click="goTo(urls.salesOrdersIndex, { status: row.status })">
            <td>{{ row.category }}</td>
            <td class="fid-num">{{ row.count }}</td>
            <td class="fid-num">{{ fmt(row.value) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Top Sales Orders ───────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('projects.salesOrdersDashboard.index.topSalesOrders') }}</div>
    <div class="zv-card fid-chart-card">
      <div class="fid-chart-header">
        <h4 class="fid-chart-title">{{ t('projects.salesOrdersDashboard.index.byValue') }}</h4>
        <Link :href="urls.salesOrdersIndex" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('projects.salesOrdersDashboard.index.viewAll') }}</Link>
      </div>
      <table class="zv-table">
        <thead>
          <tr>
            <th>{{ t('projects.salesOrdersDashboard.index.soCode') }}</th>
            <th>{{ t('projects.salesOrdersDashboard.index.customer') }}</th>
            <th>{{ t('projects.salesOrdersDashboard.index.status') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.value') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.invoiced') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.remaining') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in current.top_orders" :key="row.id" class="fid-row" @click="goToOrder(row.id)">
            <td>{{ row.so_code }} <span class="fid-muted">{{ row.subject }}</span></td>
            <td>{{ row.partner }}</td>
            <td><span class="fid-status-badge" :class="'fid-status-' + row.status">{{ statusLabel(row.status) }}</span></td>
            <td class="fid-num fid-num-blue">{{ fmt(row.value) }}</td>
            <td class="fid-num fid-num-green">{{ fmt(row.invoiced) }}</td>
            <td class="fid-num fid-num-amber">{{ fmt(row.remaining) }}</td>
          </tr>
          <tr v-if="!current.top_orders.length"><td colspan="6" class="fid-muted" style="text-align:center;">{{ t('projects.salesOrdersDashboard.index.noOpenSalesOrders') }}</td></tr>
        </tbody>
      </table>
    </div>

    <!-- ── Top Customers ──────────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('projects.salesOrdersDashboard.index.topCustomers') }}</div>
    <div class="zv-card fid-chart-card">
      <table class="zv-table">
        <thead>
          <tr>
            <th>{{ t('projects.salesOrdersDashboard.index.customer') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.openOrders') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.value') }}</th>
            <th class="fid-th-num">{{ t('projects.salesOrdersDashboard.index.remaining') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in current.top_customers" :key="row.partner_id" class="fid-row" @click="goTo(urls.salesOrdersIndex, { partner_id: row.partner_id })">
            <td>{{ row.name }} <span class="fid-muted">({{ row.code }})</span></td>
            <td class="fid-num">{{ row.order_count }}</td>
            <td class="fid-num fid-num-blue">{{ fmt(row.value) }}</td>
            <td class="fid-num fid-num-amber">{{ fmt(row.remaining) }}</td>
          </tr>
          <tr v-if="!current.top_customers.length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('projects.salesOrdersDashboard.index.noOpenSalesOrders') }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import DonutChart3D from '@/Components/Charts/DonutChart3D.vue'

const { t } = useI18n()

const props = defineProps({
  data: { type: Object, required: true },
  urls: { type: Object, default: () => ({}) },
})

const period = ref('month')

// The whole page reads from this single computed — swapping `period`
// swaps every section at once, since SalesOrderDashboardService sends
// a complete, independently-computed dataset per period rather than
// one shared snapshot with a single period-aware field.
const current = computed(() => props.data.data[period.value])

const statusLabels = {
  draft: t('projects.salesOrdersDashboard.index.statusDraft'),
  active: t('projects.salesOrdersDashboard.index.statusActive'),
  on_hold: t('projects.salesOrdersDashboard.index.statusOnHold'),
  closed: t('projects.salesOrdersDashboard.index.statusClosed'),
  cancelled: t('projects.salesOrdersDashboard.index.statusCancelled'),
}
function statusLabel(status) { return statusLabels[status] || status }

const statusDonutData = computed(() =>
  (current.value.status_mix || [])
    .map(row => ({ category: row.category, value: row.value }))
    .filter(row => Number(row.value) > 0)
)

const invoicingColors = ['#16a34a', '#b45309']
const statusColors = ['#6b7280', '#16a34a', '#b45309', '#1490A8', '#dc2626']

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }

function goTo(baseUrl, params) {
  if (!baseUrl) return
  router.get(baseUrl, params, { preserveState: false })
}

function goToOrder(id) {
  router.get(route('projects.sales-orders.edit', id))
}
</script>

<!--
  Styles copied from Accounting/Dashboard/Index.vue, which itself
  copies FinancialInstitutions/Dashboard/CashStatus.vue and
  LgLcStatus.vue (per Mahmoud: "same design and colors as the
  Accounting Dashboard", which itself matches the Financial
  Institutions Dashboard). Kept as the same `fid-` class names
  deliberately — see Accounting/Dashboard/Index.vue's own style-block
  comment for why. Adds one small addition (`fid-status-badge` and
  its per-status color variants) that neither of those two pages
  needed — Sales Orders is the first of these dashboards with a
  status value worth badge-styling inline in a table row.
-->
<style>
.fid-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.fid-pill { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-secondary, #4b5563); }
.fid-pill-active { background: var(--zv-navy, #0F2554); color: #fff; }

.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; }

.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
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

.fid-chart-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 900px) { .fid-chart-grid-2 { grid-template-columns: 1fr; } }

.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; padding: 8px; }
.fid-row { cursor: pointer; }
.fid-row:hover { background: var(--zv-bg-surface-alt, #f9fafb); }

.fid-status-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.fid-status-draft { background: rgba(107,114,128,0.12); color: #6b7280; }
.fid-status-active { background: rgba(22,163,74,0.12); color: #16a34a; }
.fid-status-on_hold { background: rgba(180,83,9,0.12); color: #b45309; }
.fid-status-closed { background: rgba(20,144,168,0.12); color: #1490A8; }
.fid-status-cancelled { background: rgba(220,38,38,0.12); color: #dc2626; }
</style>
