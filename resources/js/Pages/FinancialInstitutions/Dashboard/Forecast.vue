<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/Dashboard/Forecast.vue

  Served by DashboardController@forecast. The "Cash Forecast" tab —
  Cash Flow Projection (Inflow/Outflow + Accumulated Net Cash),
  Invoice & Cheque Aging (diverging bar charts), and Past Due
  summary tables.

  Structure ported from CashVero's Dashboard/Forecast.vue. Cash Flow
  Projection is a deliberately scoped-down v1 — see
  CashFlowProjectionService's header for exactly what's included and
  what's a documented follow-up (no manual overrides, no saved
  reports, no contract drill-down). Invoice Aging and Cheque Aging
  are faithful ports of CashVero's own day-interval buckets.

  Audit H15 (Sept 2026): every figure on this page is in the selected
  currency tab's own units. Cheque aging and the past-due tables now
  follow the selected tab (cheques used to be shown only under the
  first currency in the list; supplier invoices and installments
  mixed all currencies in one list).

  @layout    AppLayout
  @route     fi.dashboard.forecast
  @props     see DashboardController::forecast()
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">
      <PageHeader
        :title="t('financialInstitutions.dashboard.forecast.dashboard')"
        :subtitle="t('financialInstitutions.dashboard.forecast.subtitle')"
      />

      <DashboardTabs active="forecast" :urls="dashboardTabUrls" />

      <div class="zv-card fid-filter-bar">
        <div class="field-group">
          <label class="zv-label">{{ t('financialInstitutions.dashboard.forecast.reportInterval') }}</label>
          <select v-model="reportIntervalModel" class="zv-select" style="width: 160px;">
            <option value="daily">{{ t('financialInstitutions.dashboard.forecast.daily') }}</option>
            <option value="weekly">{{ t('financialInstitutions.dashboard.forecast.weekly') }}</option>
            <option value="monthly">{{ t('financialInstitutions.dashboard.forecast.monthly') }}</option>
          </select>
        </div>
        <div class="field-group">
          <label class="zv-label">{{ t('common.startDate') }}</label>
          <input v-model="startDateModel" type="date" class="zv-input" style="width: 160px;"/>
        </div>
        <div class="field-group">
          <label class="zv-label">{{ t('common.endDate') }}</label>
          <input v-model="endDateModel" type="date" class="zv-input" style="width: 160px;"/>
        </div>
        <button class="zv-btn zv-btn-primary" @click="applyFilter">{{ t('common.apply') }}</button>
      </div>

      <div class="fid-pill-row">
        <button
          v-for="currency in selectedCurrencies"
          :key="currency"
          class="fid-pill"
          :class="{ 'fid-pill-active': activeCurrency === currency }"
          @click="activeCurrency = currency"
        >
          {{ currency }}
        </button>
      </div>

      <!-- Cash Flow Projection -->
      <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.forecast.cashFlowProjection') }} [{{ activeCurrency }}]</div>
      <div class="fid-chart-grid-2">
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ intervalLabel }} {{ t('financialInstitutions.dashboard.forecast.cashFlow') }}</h4>
          <MultiLineChart :data="cashFlowReport?.[activeCurrency]?.total_cash_in_out_flow || []" :series-def="cashFlowSeries"/>
        </div>
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.forecast.accumulatedNetCash') }}</h4>
          <MultiLineChart :data="cashFlowReport?.[activeCurrency]?.accumulated_net_cash || []" :series-def="netCashSeries"/>
        </div>
      </div>
      <p class="fid-muted" style="margin-bottom: 1rem;">
        {{ t('financialInstitutions.dashboard.forecast.openingBalance') }}: <strong>{{ fmt(cashFlowReport?.[activeCurrency]?.opening_balance) }}</strong> {{ activeCurrency }}
      </p>

      <!-- Invoice Aging -->
      <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.forecast.invoiceAging') }} [{{ activeCurrency }}]</div>
      <div class="fid-chart-grid-2">
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.forecast.customerInvoices') }}</h4>
          <p class="fid-chart-note">{{ t('financialInstitutions.dashboard.forecast.pastDueLeftComingRight') }}</p>
          <AgingDivergingBarChart :data="invoiceAging?.customer?.[activeCurrency]?.chart || []"/>
        </div>
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.forecast.supplierInvoices') }}</h4>
          <p class="fid-chart-note">{{ t('financialInstitutions.dashboard.forecast.pastDueLeftComingRight') }}</p>
          <AgingDivergingBarChart :data="invoiceAging?.supplier?.[activeCurrency]?.chart || []"/>
        </div>
      </div>

      <!-- Cheque Aging -->
      <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.forecast.chequeAging') }} [{{ activeCurrency }}]</div>
      <div class="fid-chart-grid-2">
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.forecast.customerReceivableCheques') }}</h4>
          <p class="fid-chart-note">{{ t('financialInstitutions.dashboard.forecast.pastDueLeftComingRight') }}</p>
          <AgingDivergingBarChart :data="chequeAgingCustomerChart"/>
        </div>
        <div class="zv-card fid-chart-card">
          <h4 class="fid-chart-title">{{ t('financialInstitutions.dashboard.forecast.supplierPayableCheques') }}</h4>
          <p class="fid-chart-note">{{ t('financialInstitutions.dashboard.forecast.pastDueLeftComingRight') }}</p>
          <AgingDivergingBarChart :data="chequeAgingSupplierChart"/>
        </div>
      </div>

      <!-- Past Due Summary -->
      <div class="fid-section-heading">{{ t('financialInstitutions.dashboard.forecast.pastDueSummary') }} [{{ activeCurrency }}]</div>
      <div class="fid-pastdue-grid">
        <div class="zv-card fid-pastdue-card">
          <div class="fid-pastdue-header">{{ t('financialInstitutions.dashboard.forecast.pastDueCustomerInvoices') }}</div>
          <table class="zv-table zv-table-sm">
            <tbody>
              <tr v-for="(row, i) in (pastDueCustomerInvoices?.[activeCurrency] || [])" :key="i">
                <td>{{ row.invoice_number }}</td><td>{{ row.invoice_due_date }}</td>
                <td class="fid-num fid-num-red">{{ fmt(row.net_balance) }}</td>
              </tr>
              <tr v-if="!(pastDueCustomerInvoices?.[activeCurrency] || []).length"><td colspan="3" class="fid-muted" style="text-align:center;">{{ t('financialInstitutions.dashboard.forecast.none') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="zv-card fid-pastdue-card">
          <div class="fid-pastdue-header">{{ t('financialInstitutions.dashboard.forecast.pastDueSupplierInvoices') }}</div>
          <table class="zv-table zv-table-sm">
            <tbody>
              <tr v-for="(row, i) in (pastDueSupplierInvoices?.[activeCurrency] || [])" :key="i">
                <td>{{ row.invoice_number }}</td><td>{{ row.invoice_due_date }}</td>
                <td class="fid-num fid-num-red">{{ fmt(row.net_balance) }}</td>
              </tr>
              <tr v-if="!(pastDueSupplierInvoices?.[activeCurrency] || []).length"><td colspan="3" class="fid-muted" style="text-align:center;">{{ t('financialInstitutions.dashboard.forecast.none') }}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="zv-card fid-pastdue-card">
          <div class="fid-pastdue-header">{{ t('financialInstitutions.dashboard.forecast.pastDueInstallments') }}</div>
          <table class="zv-table zv-table-sm">
            <tbody>
              <tr v-for="(row, i) in activeInstallments" :key="i">
                <td>{{ row.date }}</td><td class="fid-num fid-num-red">{{ fmt(row.remaining) }}</td>
              </tr>
              <tr v-if="!activeInstallments.length"><td colspan="2" class="fid-muted" style="text-align:center;">{{ t('financialInstitutions.dashboard.forecast.none') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="fid-scope-note">{{ t('financialInstitutions.dashboard.forecast.scopeNote') }}</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import DashboardTabs from '@/Components/FinancialInstitutions/DashboardTabs.vue'
import MultiLineChart from '@/Components/Charts/MultiLineChart.vue'
import AgingDivergingBarChart from '@/Components/Charts/AgingDivergingBarChart.vue'

const { t } = useI18n()

const props = defineProps({
  selectedCurrencies: { type: Array, default: () => [] },
  cashFlowReport: { type: Object, default: () => ({}) },
  invoiceAging: { type: Object, default: () => ({}) },
  chequeAging: { type: Object, default: () => ({}) },
  reportInterval: { type: String, default: 'weekly' },
  selectedReportInterval: { type: String, default: 'weekly' },
  cashFlowStartDate: { type: String, default: '' },
  cashFlowEndDate: { type: String, default: '' },
  pastDueCustomerInvoices: { type: Object, default: () => ({}) },
  pastDueSupplierInvoices: { type: Object, default: () => ({}) },
  pastDueInstallments: { type: Array, default: () => [] },
  filterUrl: { type: String, default: '' },
  dashboardTabUrls: { type: Object, default: () => ({}) },
})

const activeCurrency = ref(props.selectedCurrencies[0] || '')
const reportIntervalModel = ref(props.selectedReportInterval || 'weekly')
const startDateModel = ref(props.cashFlowStartDate)
const endDateModel = ref(props.cashFlowEndDate)

function applyFilter() {
  router.get(props.filterUrl, {
    report_interval: reportIntervalModel.value,
    start_date: startDateModel.value,
    end_date: endDateModel.value,
  }, { preserveScroll: true, preserveState: true })
}

const intervalLabels = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' }
const intervalLabel = computed(() => intervalLabels[props.reportInterval] || 'Periodic')

const cashFlowSeries = [
  { field: 'cash_in', name: t('financialInstitutions.dashboard.forecast.cashInflow'), color: '#16a34a' },
  { field: 'cash_out', name: t('financialInstitutions.dashboard.forecast.cashOutflow'), color: '#dc2626' },
]
const netCashSeries = [
  { field: 'value', name: t('financialInstitutions.dashboard.forecast.accumulatedNetCash'), color: '#1490A8' },
]

// Audit H15: cheques are aged in their OWN currency (see
// ChequeAgingService header), so the charts follow the selected tab.
const chequeAgingCustomerChart = computed(() => props.chequeAging?.customer?.[activeCurrency.value]?.aging_chart || [])
const chequeAgingSupplierChart = computed(() => props.chequeAging?.supplier?.[activeCurrency.value]?.aging_chart || [])
const activeInstallments = computed(() => (props.pastDueInstallments || []).filter(r => r.currency === activeCurrency.value))

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }
</script>

<style>
.fid-chart-note { font-size: 11px; color: var(--zv-text-muted, #6b7280); margin-bottom: 8px; }
.fid-pastdue-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-pastdue-grid { grid-template-columns: 1fr; } }
.fid-pastdue-card { overflow: hidden; }
.fid-pastdue-header { padding: 8px 14px; font-size: 12px; font-weight: 600; color: var(--zv-navy, #0F2554); background: var(--zv-bg-surface-alt, #f9fafb); border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.zv-table-sm td { font-size: 12px; padding: 6px 12px; }
.fid-scope-note { font-size: 11px; color: var(--zv-text-muted, #6b7280); margin-top: 1rem; padding: 10px 14px; background: var(--zv-bg-surface-alt, #f9fafb); border-radius: 8px; }
</style>
