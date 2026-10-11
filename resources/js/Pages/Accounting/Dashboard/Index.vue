<!--
══════════════════════════════════════════════════════════════════
  Accounting/Dashboard/Index.vue

  Accounting Dashboard — single scrolling page (per Mahmoud,
  September 2026), built to match the Financial Institutions
  Dashboard's visual language exactly: KPI cards, section headings,
  3D donuts, clickable drill-down tables — same `acd-` (Accounting
  Dashboard) CSS prefix mirroring FI's `fid-` classes 1:1, same
  colors (navy #0F2554 / teal #1490A8 / green/amber/red).

  Two periods ("This Month" / "Year to Date") arrive together in one
  payload from AccountingDashboardService — the pill toggle below
  just switches which period key is read, no second request.

  AR/AP partner rows are a REAL drill-down (per Mahmoud: "full
  drill-down now") — each row links straight to the existing,
  fully-built Customer/Supplier Balances page (filtered to that
  partner), which itself drills into the real Invoices Report and
  Statement. This dashboard deliberately does not re-implement that
  invoice-level detail a second time.

  @layout    AppLayout
  @route     accounting.dashboard
  @props     data, urls — see AccountingDashboardController::index()
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page">
    <PageHeader
      eyebrow="Accounting"
      :title="t('accounting.dashboard.index.accountingDashboard')"
      :subtitle="t('accounting.dashboard.index.subtitle')"
    />

    <!-- ── Period toggle ─────────────────────────────────────── -->
    <div class="fid-pill-row">
      <button
        class="fid-pill" :class="{ 'fid-pill-active': period === 'month' }"
        @click="period = 'month'"
      >{{ data.periods.month }}</button>
      <button
        class="fid-pill" :class="{ 'fid-pill-active': period === 'ytd' }"
        @click="period = 'ytd'"
      >{{ t('accounting.dashboard.index.yearToDate') }}</button>
    </div>

    <!-- ── Overview KPI row ──────────────────────────────────── -->
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-green">📈</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.revenue') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(pl.revenue?.[period]) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon" :class="netIncomeIsPositive ? 'fid-icon-green' : 'fid-icon-amber'">Σ</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.netProfit') }}</p><p class="fid-kpi-value" :class="netIncomeIsPositive ? 'fid-num-green' : 'fid-num-red'">{{ fmt(pl.net_income?.[period]) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-blue">🏦</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.cashAndBankBalance') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(data.cash) }}</p></div>
      </div>
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.customerBalances)">
        <div class="fid-kpi-icon fid-icon-amber">💰</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.arOutstanding') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.ar.total_outstanding) }}</p></div>
      </div>
    </div>
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card fid-kpi-card-clickable" @click="goTo(urls.supplierBalances)">
        <div class="fid-kpi-icon fid-icon-amber">📄</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.apOutstanding') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.ap.total_outstanding) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-amber">⚠️</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.overdueAr') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.ar.overdue) }}</p></div>
      </div>
      <div class="fid-kpi-card">
        <div class="fid-kpi-icon fid-icon-amber">⚠️</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.overdueAp') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.ap.overdue) }}</p></div>
      </div>
      <div class="fid-kpi-card" v-if="data.inventory !== null">
        <div class="fid-kpi-icon fid-icon-blue">📦</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.inventoryValue') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(data.inventory) }}</p></div>
      </div>
      <div class="fid-kpi-card" v-else>
        <div class="fid-kpi-icon fid-icon-green">%</div>
        <div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.netMargin') }}</p><p class="fid-kpi-value fid-num-green">{{ pl.margins?.[period]?.net_margin_pct ?? '0.0' }}%</p></div>
      </div>
    </div>

    <!-- ── P&L Snapshot ──────────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.dashboard.index.plSnapshot') }}</div>
    <div class="fid-kpi-row-4">
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">📈</div><div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.revenue') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(pl.revenue?.[period]) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">📉</div><div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.cogs') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(cogsTotal) }}</p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">✅</div><div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.grossProfit') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(pl.gross_profit?.[period]) }} <span class="fid-kpi-note">({{ pl.margins?.[period]?.gross_margin_pct ?? '0.0' }}%)</span></p></div></div>
      <div class="fid-kpi-card"><div class="fid-kpi-icon" :class="netIncomeIsPositive ? 'fid-icon-green' : 'fid-icon-amber'">Σ</div><div><p class="fid-kpi-label">{{ t('accounting.dashboard.index.netProfit') }}</p><p class="fid-kpi-value" :class="netIncomeIsPositive ? 'fid-num-green' : 'fid-num-red'">{{ fmt(pl.net_income?.[period]) }} <span class="fid-kpi-note">({{ pl.margins?.[period]?.net_margin_pct ?? '0.0' }}%)</span></p></div></div>
    </div>
    <div class="fid-chart-grid-2">
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.expensesByCategory') }}</h4>
        <DonutChart3D :key="period" :data="expenseDonutData" :colors="expenseColors" />
      </div>
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.expenseCategoryBreakdown') }}</h4>
        <table class="zv-table">
          <thead><tr><th>{{ t('accounting.dashboard.index.category') }}</th><th class="fid-th-num">{{ t('common.amount') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in expenseDonutData" :key="row.category">
              <td>{{ row.category }}</td><td class="fid-num">{{ fmt(row.value) }}</td>
            </tr>
            <tr v-if="!expenseDonutData.length"><td colspan="2" class="fid-muted" style="text-align:center;">{{ t('accounting.dashboard.index.noExpenseActivityThisPeriod') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Accounts Receivable ───────────────────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.dashboard.index.accountsReceivable') }}</div>
    <div class="fid-kpi-row-4 fid-mini">
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.totalAr') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.ar.total_outstanding) }}</p></div>
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.overdueAr') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.ar.overdue) }}</p></div>
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.customersWithBalance') }}</p><p class="fid-kpi-value">{{ data.ar.partner_count }}</p></div>
    </div>
    <div class="fid-chart-grid-3">
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.arAging') }}</h4>
        <DonutChart3D :data="data.ar.aging" :colors="agingColors" />
      </div>
      <div class="zv-card fid-chart-card fid-chart-card-wide">
        <div class="fid-chart-header">
          <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.topCustomersByBalance') }}</h4>
          <Link :href="urls.customerBalances" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('accounting.dashboard.index.viewAll') }}</Link>
        </div>
        <table class="zv-table">
          <thead><tr><th>{{ t('accounting.dashboard.index.customer') }}</th><th class="fid-th-num">{{ t('accounting.dashboard.index.netBalance') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in data.ar.top_partners" :key="row.partner_id" class="fid-row" @click="goToPartner(urls.customerBalances, row.name)">
              <td>{{ row.name }} <span class="fid-muted">({{ row.code }})</span></td>
              <td class="fid-num fid-num-amber">{{ fmt(row.net_balance) }}</td>
            </tr>
            <tr v-if="!data.ar.top_partners.length"><td colspan="2" class="fid-muted" style="text-align:center;">{{ t('accounting.dashboard.index.noOpenBalances') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Accounts Payable ──────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.dashboard.index.accountsPayable') }}</div>
    <div class="fid-kpi-row-4 fid-mini">
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.totalAp') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(data.ap.total_outstanding) }}</p></div>
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.overdueAp') }}</p><p class="fid-kpi-value fid-num-red">{{ fmt(data.ap.overdue) }}</p></div>
      <div class="fid-kpi-card fid-mini-card"><p class="fid-kpi-label">{{ t('accounting.dashboard.index.suppliersWithBalance') }}</p><p class="fid-kpi-value">{{ data.ap.partner_count }}</p></div>
    </div>
    <div class="fid-chart-grid-3">
      <div class="zv-card fid-chart-card">
        <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.apAging') }}</h4>
        <DonutChart3D :data="data.ap.aging" :colors="agingColors" />
      </div>
      <div class="zv-card fid-chart-card fid-chart-card-wide">
        <div class="fid-chart-header">
          <h4 class="fid-chart-title">{{ t('accounting.dashboard.index.topSuppliersByBalance') }}</h4>
          <Link :href="urls.supplierBalances" class="zv-btn zv-btn-ghost zv-btn-sm">{{ t('accounting.dashboard.index.viewAll') }}</Link>
        </div>
        <table class="zv-table">
          <thead><tr><th>{{ t('accounting.dashboard.index.supplier') }}</th><th class="fid-th-num">{{ t('accounting.dashboard.index.netBalance') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in data.ap.top_partners" :key="row.partner_id" class="fid-row" @click="goToPartner(urls.supplierBalances, row.name)">
              <td>{{ row.name }} <span class="fid-muted">({{ row.code }})</span></td>
              <td class="fid-num fid-num-amber">{{ fmt(row.net_balance) }}</td>
            </tr>
            <tr v-if="!data.ap.top_partners.length"><td colspan="2" class="fid-muted" style="text-align:center;">{{ t('accounting.dashboard.index.noOpenBalances') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Cash & Bank ────────────────────────────────────────── -->
    <div class="fid-section-heading">{{ t('accounting.dashboard.index.cashAndBank') }}</div>
    <div class="zv-card fid-chart-card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
      <div>
        <p class="fid-kpi-label">{{ t('accounting.dashboard.index.totalOperatingCash') }}</p>
        <p class="fid-kpi-value fid-num-blue" style="font-size:22px;">{{ fmt(data.cash) }}</p>
        <p class="fid-kpi-note">{{ t('accounting.dashboard.index.functionalCurrencyNote') }}</p>
      </div>
      <Link :href="urls.fiDashboard" class="zv-btn zv-btn-secondary zv-btn-sm">{{ t('accounting.dashboard.index.viewFullCashPosition') }}</Link>
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
const pl = computed(() => props.data.pl)

const netIncomeIsPositive = computed(() => Number(pl.value.net_income?.[period.value] || 0) >= 0)

const cogsTotal = computed(() => {
  const section = pl.value.expense_sections?.find(s => s.account_type === 'Cost of Goods Sold')
  return section?.totals?.[period.value] ?? '0.00'
})

const categoryLabels = {
  'Cost of Goods Sold': t('accounting.dashboard.index.categoryCogs'),
  'Sales Expenses': t('accounting.dashboard.index.categorySales'),
  'Marketing Expenses': t('accounting.dashboard.index.categoryMarketing'),
  'General & Administrative Expenses': t('accounting.dashboard.index.categoryGna'),
  'Finance Expenses': t('accounting.dashboard.index.categoryFinance'),
  'Depreciation & Amortization': t('accounting.dashboard.index.categoryDna'),
}

const expenseDonutData = computed(() =>
  (pl.value.expense_sections || [])
    .map(s => ({ category: categoryLabels[s.account_type] || s.account_type, value: s.totals?.[period.value] ?? '0.00' }))
    .filter(row => Number(row.value) > 0)
)

const expenseColors = ['#b45309', '#1490A8', '#7c3aed', '#0F2554', '#dc2626', '#16a34a']
const agingColors = ['#16a34a', '#1490A8', '#b45309', '#dc2626', '#7c2d12']

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }

function goTo(url) { if (url) router.get(url) }

function goToPartner(baseUrl, name) {
  if (!baseUrl) return
  router.get(baseUrl, { search: name }, { preserveState: false })
}
</script>

<!--
  Styles copied from FinancialInstitutions/Dashboard/CashStatus.vue and
  LgLcStatus.vue (per Mahmoud, September 2026: "same design and color
  as the Financial Institutions dashboard") — kept as the same `fid-`
  class names deliberately, not renamed to an `acd-` prefix, so this
  page is byte-for-byte visually identical rather than a close copy
  that could drift. Each dashboard page in this codebase bundles its
  own copy of these rules (there is no shared global dashboard
  stylesheet yet) — this follows that same existing convention.
-->
<style>
.fid-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.fid-pill { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-secondary, #4b5563); }
.fid-pill-active { background: var(--zv-navy, #0F2554); color: #fff; }

.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; }

.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
.fid-mini { margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-kpi-card-clickable { cursor: pointer; }
.fid-kpi-card-clickable:hover { border-color: var(--zv-blue, #1490A8); }
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

.fid-chart-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 900px) { .fid-chart-grid-2 { grid-template-columns: 1fr; } }
.fid-chart-grid-3 { display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-chart-grid-3 { grid-template-columns: 1fr; } }

.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; padding: 8px; }
.fid-row { cursor: pointer; }
.fid-row:hover { background: var(--zv-bg-surface-alt, #f9fafb); }
</style>
