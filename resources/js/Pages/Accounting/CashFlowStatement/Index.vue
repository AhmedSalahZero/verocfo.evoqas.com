<!--
══════════════════════════════════════════════════════════════════
  Accounting/CashFlowStatement/Index.vue

  Accounting > Financial Reports > Cash Flow Statement (Financial
  Reports Module Structure v1.0, Section 3.6). Indirect method,
  confirmed with Mahmoud (2026-09). Same three view modes as Trial
  Balance: Single date range / Monthly / Quarterly — each period
  column is that period's own Operating/Investing/Financing movement
  (not cumulative), with a per-column reconciliation against the real
  Cash+Bank balance change (see CashFlowStatementService for the
  account-by-account Financing classification, reviewed against the
  actual COA rather than assumed).

  @layout    AppLayout
  @route     accounting.cash-flow.index
  @props     report, filters, costCenters, fiscalYears
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.cashFlowStatement.index.cashFlowStatement') }]"
      :title="t('accounting.cashFlowStatement.index.cashFlowStatement')"
      :subtitle="t('accounting.cashFlowStatement.index.howCashMovedOverADateRangeIndirectMethod')"
    />

    <!-- ── Filters ────────────────────────────────────────────── -->
    <div class="zv-card mb-5">
      <div class="form-section-body">
        <label class="zv-label mb-2 d-block">{{ t('accounting.cashFlowStatement.index.viewMode') }}</label>
        <div class="zv-tile-group fr-mode-group">
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'single' }" @click="form.mode = 'single'">
            <div class="zv-tile-label">{{ t('accounting.cashFlowStatement.index.singleRange') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'monthly' }" @click="form.mode = 'monthly'">
            <div class="zv-tile-label">{{ t('accounting.cashFlowStatement.index.monthly') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'quarterly' }" @click="form.mode = 'quarterly'">
            <div class="zv-tile-label">{{ t('accounting.cashFlowStatement.index.quarterly') }}</div>
          </button>
        </div>

        <div class="field-divider"></div>

        <div v-if="form.mode === 'single'" class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('common.startDate') }}</label>
            <input v-model="form.from_date" type="date" class="zv-input"/>
          </div>
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('common.endDate') }}</label>
            <input v-model="form.to_date" type="date" class="zv-input" :max="today"/>
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.cashFlowStatement.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.cashFlowStatement.index.allCostCenters')" />
          </div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.cashFlowStatement.index.fiscalYear') }}</label>
            <SelectBox v-model="form.fiscal_year_id" :options="fiscalYears" value-key="id" :label-fn="fy => `${fy.name} (${fy.status})`" :placeholder="t('accounting.cashFlowStatement.index.selectFiscalYear')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.cashFlowStatement.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.cashFlowStatement.index.allCostCenters')" />
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit" @click="viewReport">
            {{ t('accounting.cashFlowStatement.index.viewReport') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="!report" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('accounting.cashFlowStatement.index.pickAFiscalYearToSeeMonthlyQuarterly') }}</div></div></div>

    <template v-else>
      <div v-if="anyUnreconciled" class="zv-alert zv-alert-warning mb-3">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 flex-shrink-0">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span class="text-sm">{{ t('accounting.cashFlowStatement.index.doesNotReconcileWarning') }}</span>
      </div>

      <div class="zv-card">
        <div class="zv-table-wrap fr-table-wrap">
          <table class="zv-table fr-table">
            <thead>
              <tr><th class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.item') }}</th><th v-for="p in report.periods" :key="p.key" class="zv-table-num">{{ p.label }}</th></tr>
            </thead>
            <tbody>
              <!-- Operating -->
              <tr class="is-section-row"><td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.operatingActivities') }}</td><td v-for="p in report.periods" :key="'op-h-'+p.key"></td></tr>
              <tr>
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.netIncome') }}</td>
                <td v-for="p in report.periods" :key="'ni-'+p.key" class="zv-table-num">{{ fmt(report.net_income[p.key]) }}</td>
              </tr>
              <tr>
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.addDepreciationAmortization') }}</td>
                <td v-for="p in report.periods" :key="'da-'+p.key" class="zv-table-num">{{ fmt(report.depreciation_amortization[p.key]) }}</td>
              </tr>
              <tr v-for="row in report.operating.rows" :key="'op-' + row.id">
                <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                <td v-for="p in report.periods" :key="row.id+'-'+p.key" class="zv-table-num" :class="Number(row.periods[p.key]) < 0 ? 'zv-num-danger' : ''">{{ fmt(row.periods[p.key]) }}</td>
              </tr>
              <tr class="zv-summary-row">
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.netCashFromOperating') }}</td>
                <td v-for="p in report.periods" :key="'opt-'+p.key" class="zv-table-num">{{ fmt(report.operating.totals[p.key]) }}</td>
              </tr>

              <!-- Investing -->
              <tr class="is-section-row"><td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.investingActivities') }}</td><td v-for="p in report.periods" :key="'inv-h-'+p.key"></td></tr>
              <tr v-for="row in report.investing.rows" :key="'inv-' + row.id">
                <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                <td v-for="p in report.periods" :key="row.id+'-'+p.key" class="zv-table-num" :class="Number(row.periods[p.key]) < 0 ? 'zv-num-danger' : ''">{{ fmt(row.periods[p.key]) }}</td>
              </tr>
              <tr v-if="report.investing.rows.length === 0"><td class="fr-sticky-col text-sm opacity-50">{{ t('accounting.cashFlowStatement.index.noInvestingActivity') }}</td><td v-for="p in report.periods" :key="'ninv-'+p.key"></td></tr>
              <tr class="zv-summary-row">
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.netCashFromInvesting') }}</td>
                <td v-for="p in report.periods" :key="'invt-'+p.key" class="zv-table-num">{{ fmt(report.investing.totals[p.key]) }}</td>
              </tr>

              <!-- Financing -->
              <tr class="is-section-row"><td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.financingActivities') }}</td><td v-for="p in report.periods" :key="'fin-h-'+p.key"></td></tr>
              <tr v-for="row in report.financing.rows" :key="'fin-' + row.id">
                <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                <td v-for="p in report.periods" :key="row.id+'-'+p.key" class="zv-table-num" :class="Number(row.periods[p.key]) < 0 ? 'zv-num-danger' : ''">{{ fmt(row.periods[p.key]) }}</td>
              </tr>
              <tr v-if="report.financing.rows.length === 0"><td class="fr-sticky-col text-sm opacity-50">{{ t('accounting.cashFlowStatement.index.noFinancingActivity') }}</td><td v-for="p in report.periods" :key="'nfin-'+p.key"></td></tr>
              <tr class="zv-summary-row">
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.netCashFromFinancing') }}</td>
                <td v-for="p in report.periods" :key="'fint-'+p.key" class="zv-table-num">{{ fmt(report.financing.totals[p.key]) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="zv-summary-row is-grand-total-row">
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.netChangeInCash') }}</td>
                <td v-for="p in report.periods" :key="'nc-'+p.key" class="zv-table-num">{{ fmt(report.net_change_in_cash[p.key]) }}</td>
              </tr>
              <tr>
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.cashBeginning') }}</td>
                <td v-for="p in report.periods" :key="'cb-'+p.key" class="zv-table-num">{{ fmt(report.cash_beginning[p.key]) }}</td>
              </tr>
              <tr class="is-grand-total-row">
                <td class="fr-sticky-col">{{ t('accounting.cashFlowStatement.index.cashEnding') }}</td>
                <td v-for="p in report.periods" :key="'ce-'+p.key" class="zv-table-num">{{ fmt(report.cash_ending[p.key]) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  report:      { type: Object, default: null },
  filters:     { type: Object, default: () => ({}) },
  costCenters: { type: Array, default: () => [] },
  fiscalYears: { type: Array, default: () => [] },
})

const today = todayLocal()

const form = reactive({
  mode: props.filters.mode || 'single',
  from_date: props.filters.from_date || '',
  to_date: props.filters.to_date || '',
  fiscal_year_id: props.filters.fiscal_year_id || '',
  cost_center_id: props.filters.cost_center_id || '',
})

const canSubmit = computed(() => form.mode === 'single' || !!form.fiscal_year_id)

function viewReport() {
  if (!canSubmit.value) return
  router.get(route('accounting.cash-flow.index'), {
    mode: form.mode,
    fiscal_year_id: form.mode !== 'single' ? form.fiscal_year_id : undefined,
    cost_center_id: form.cost_center_id || undefined,
    from_date: form.mode === 'single' ? (form.from_date || undefined) : undefined,
    to_date: form.mode === 'single' ? (form.to_date || undefined) : undefined,
  }, { preserveState: true, preserveScroll: true })
}

const anyUnreconciled = computed(() => {
  if (!props.report) return false
  return Object.values(props.report.is_reconciled || {}).some(b => !b)
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style>
.fr-mode-group { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 700px) { .fr-mode-group { grid-template-columns: 1fr; } }
.d-block { display: block; }

.fr-table-wrap { overflow-x: auto; }
.fr-table { border-collapse: collapse; }
.fr-sticky-col { position: sticky; left: 0; background: var(--zv-bg-surface); z-index: 2; min-width: 260px; text-align: left; }

.is-section-row td {
  background: var(--zv-bg-surface-alt);
  font-weight: 700;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--zv-text-muted);
}
.is-section-row .fr-sticky-col { background: var(--zv-bg-surface-alt); }
.is-grand-total-row td { font-size: 1.0625rem; border-top: 2px solid var(--zv-border); font-weight: 700; }
.zv-summary-row .fr-sticky-col, .is-grand-total-row .fr-sticky-col { background: var(--zv-bg-surface); }
</style>
