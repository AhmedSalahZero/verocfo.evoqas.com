<!--
══════════════════════════════════════════════════════════════════
  Accounting/IncomeStatement/Index.vue

  Accounting > Financial Reports > Income Statement (Financial
  Reports Module Structure v1.0, Section 3.4). Same three view modes
  as Trial Balance (September 2026, per Mahmoud):
    - Single date range — one column (default)
    - Monthly   — one fiscal year's real accounting periods, side by side
    - Quarterly — those same periods grouped into 4 quarters

  Sections render in a fixed order (Revenue → Cost of Goods Sold →
  Gross Profit → the remaining expense categories → Net Income) —
  IncomeStatementService already returns them pre-ordered.

  Audit H19 (Sept 2026): subtotals down to Profit After Tax —
  Gross Profit → Operating Profit (EBIT) → Profit Before Tax →
  Income Tax (its own section, not an expense category) → Profit
  After Tax. Finance Expenses now come after Depreciation.

  @layout    AppLayout
  @route     accounting.pl.index
  @props     report, filters, costCenters, fiscalYears
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.incomeStatement.index.incomeStatement') }]"
      :title="t('accounting.incomeStatement.index.incomeStatement')"
      :subtitle="t('accounting.incomeStatement.index.revenueMinusExpensesOverADateRange')"
    />

    <!-- ── Filters ────────────────────────────────────────────── -->
    <div class="zv-card mb-5">
      <div class="form-section-body">
        <label class="zv-label mb-2 d-block">{{ t('accounting.incomeStatement.index.viewMode') }}</label>
        <div class="zv-tile-group fr-mode-group">
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'single' }" @click="form.mode = 'single'">
            <div class="zv-tile-label">{{ t('accounting.incomeStatement.index.singleRange') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'monthly' }" @click="form.mode = 'monthly'">
            <div class="zv-tile-label">{{ t('accounting.incomeStatement.index.monthly') }}</div>
          </button>
          <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.mode === 'quarterly' }" @click="form.mode = 'quarterly'">
            <div class="zv-tile-label">{{ t('accounting.incomeStatement.index.quarterly') }}</div>
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
            <label class="zv-label">{{ t('accounting.incomeStatement.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.incomeStatement.index.allCostCenters')" />
          </div>
        </div>

        <div v-else class="field-grid field-grid-3">
          <div class="field-group">
            <label class="zv-label zv-label-required">{{ t('accounting.incomeStatement.index.fiscalYear') }}</label>
            <SelectBox v-model="form.fiscal_year_id" :options="fiscalYears" value-key="id" :label-fn="fy => `${fy.name} (${fy.status})`" :placeholder="t('accounting.incomeStatement.index.selectFiscalYear')" :clearable="false" />
          </div>
          <div class="field-group">
            <label class="zv-label">{{ t('accounting.incomeStatement.index.costCenter') }}</label>
            <SelectBox v-model="form.cost_center_id" :options="costCenters" value-key="id" :label-fn="cc => `${cc.code} — ${cc.name_en}`" :placeholder="t('accounting.incomeStatement.index.allCostCenters')" />
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="!canSubmit" @click="viewReport">
            {{ t('accounting.incomeStatement.index.viewReport') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="!report" class="zv-card"><div class="zv-empty"><div class="zv-empty-title">{{ t('accounting.incomeStatement.index.pickAFiscalYearToSeeMonthlyQuarterly') }}</div></div></div>

    <template v-else>
      <!-- ── Report ─────────────────────────────────────────────── -->
      <div class="zv-card">
        <div v-if="report.revenue.rows.length === 0 && report.expense_sections.length === 0" class="zv-empty">
          <div class="zv-empty-title">{{ t('accounting.incomeStatement.index.noActivityInThisRange') }}</div>
        </div>

        <div v-else class="zv-table-wrap fr-table-wrap">
          <table class="zv-table fr-table">
            <thead>
              <tr>
                <th class="fr-sticky-col">{{ t('accounting.incomeStatement.index.account') }}</th>
                <th v-for="period in report.periods" :key="period.key" class="zv-table-num">{{ period.label }}</th>
              </tr>
            </thead>
            <tbody>
              <!-- Revenue (M8: Sales Revenue only — it alone feeds Gross Profit) -->
              <tr class="is-section-row"><td class="fr-sticky-col" :colspan="1">{{ salesBlock === report.revenue ? t('accounting.incomeStatement.index.revenue') : t('accounting.incomeStatement.index.salesRevenue') }}</td><td v-for="p in report.periods" :key="'rev-h-'+p.key"></td></tr>
              <tr v-for="row in salesBlock.rows" :key="'rev-' + row.id">
                <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                <td v-for="period in report.periods" :key="row.id + '-' + period.key" class="zv-table-num">{{ fmt(row.periods[period.key]) }}</td>
              </tr>
              <tr class="zv-summary-row">
                <td class="fr-sticky-col">{{ salesBlock === report.revenue ? t('accounting.incomeStatement.index.totalRevenue') : t('accounting.incomeStatement.index.totalSalesRevenue') }}</td>
                <td v-for="period in report.periods" :key="'rev-t-'+period.key" class="zv-table-num">{{ fmt(salesBlock.totals[period.key]) }}</td>
              </tr>

              <!-- Expense sections, in server-given order -->
              <template v-for="section in report.expense_sections" :key="section.account_type">
                <tr class="is-section-row"><td class="fr-sticky-col">{{ expenseTypeLabel(section.account_type) }}</td><td v-for="p in report.periods" :key="section.account_type+'-h-'+p.key"></td></tr>
                <tr v-for="row in section.rows" :key="section.account_type + '-' + row.id">
                  <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                  <td v-for="period in report.periods" :key="row.id + '-' + period.key" class="zv-table-num">{{ fmt(row.periods[period.key]) }}</td>
                </tr>
                <tr class="zv-summary-row">
                  <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.total') }} {{ expenseTypeLabel(section.account_type) }}</td>
                  <td v-for="period in report.periods" :key="section.account_type+'-t-'+period.key" class="zv-table-num">{{ fmt(section.totals[period.key]) }}</td>
                </tr>

                <tr v-if="section.account_type === 'Cost of Goods Sold'" class="is-subtotal-row">
                  <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.grossProfit') }}</td>
                  <td v-for="period in report.periods" :key="'gp-'+period.key" class="zv-table-num">{{ fmt(report.gross_profit[period.key]) }}</td>
                </tr>

                <!-- H19: Operating Profit (EBIT) right after the last operating category -->
                <tr v-if="report.operating_profit && section.account_type === lastOperatingType" class="is-subtotal-row">
                  <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.operatingProfitEbit') }}</td>
                  <td v-for="period in report.periods" :key="'op-'+period.key" class="zv-table-num">{{ fmt(report.operating_profit[period.key]) }}</td>
                </tr>

                <!-- M8: Other income and FX gains come AFTER Operating Profit -->
                <template v-if="report.other_income && report.other_income.rows.length">
                  <tr class="is-section-row"><td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.otherIncomeFxGains') }}</td><td v-for="p in report.periods" :key="'oi-h-'+p.key"></td></tr>
                  <tr v-for="row in report.other_income.rows" :key="'oi-' + row.id">
                    <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                    <td v-for="period in report.periods" :key="row.id + '-' + period.key" class="zv-table-num">{{ fmt(row.periods[period.key]) }}</td>
                  </tr>
                  <tr class="zv-summary-row">
                    <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.totalOtherIncomeFxGains') }}</td>
                    <td v-for="period in report.periods" :key="'oi-t-'+period.key" class="zv-table-num">{{ fmt(report.other_income.totals[period.key]) }}</td>
                  </tr>
                </template>
              </template>

              <!-- H19: Profit Before Tax, Income Tax -->
              <template v-if="report.profit_before_tax">
                <tr class="is-subtotal-row">
                  <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.profitBeforeTax') }}</td>
                  <td v-for="period in report.periods" :key="'pbt-'+period.key" class="zv-table-num">{{ fmt(report.profit_before_tax[period.key]) }}</td>
                </tr>
                <tr class="is-section-row"><td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.incomeTax') }}</td><td v-for="p in report.periods" :key="'tax-h-'+p.key"></td></tr>
                <tr v-for="row in (report.income_tax?.rows || [])" :key="'tax-' + row.id">
                  <td class="fr-sticky-col"><span class="zv-code">{{ row.code }}</span> {{ row.name_en }}</td>
                  <td v-for="period in report.periods" :key="row.id + '-' + period.key" class="zv-table-num">{{ fmt(row.periods[period.key]) }}</td>
                </tr>
                <tr class="zv-summary-row">
                  <td class="fr-sticky-col">{{ t('accounting.incomeStatement.index.totalIncomeTax') }}</td>
                  <td v-for="period in report.periods" :key="'tax-t-'+period.key" class="zv-table-num">{{ fmt(report.income_tax?.totals?.[period.key]) }}</td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr class="zv-summary-row is-net-income-row">
                <td class="fr-sticky-col">{{ report.profit_after_tax ? t('accounting.incomeStatement.index.profitAfterTax') : t('accounting.incomeStatement.index.netIncome') }}</td>
                <td v-for="period in report.periods" :key="'ni-'+period.key" class="zv-table-num">{{ fmt(report.net_income[period.key]) }}</td>
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
  router.get(route('accounting.pl.index'), {
    mode: form.mode,
    fiscal_year_id: form.mode !== 'single' ? form.fiscal_year_id : undefined,
    cost_center_id: form.cost_center_id || undefined,
    from_date: form.mode === 'single' ? (form.from_date || undefined) : undefined,
    to_date: form.mode === 'single' ? (form.to_date || undefined) : undefined,
  }, { preserveState: true, preserveScroll: true })
}

const TYPE_LABELS = {
  'Cost of Goods Sold': 'index.costOfGoodsSold',
  'Sales Expenses': 'index.salesExpenses',
  'Marketing Expenses': 'index.marketingExpenses',
  'General & Administrative Expenses': 'index.generalAdministrativeExpenses',
  'Finance Expenses': 'index.financeExpenses',
  'Depreciation & Amortization': 'index.depreciationAmortization',
}
// M8: older/cached reports without the split fall back to all revenue.
const salesBlock = computed(() => props.report?.sales_revenue || props.report?.revenue || { rows: [], totals: {} })
// H19: Operating Profit is shown after the last section that is not Finance.
const lastOperatingType = computed(() => {
  const types = (props.report?.expense_sections || []).map(s => s.account_type).filter(t => t !== 'Finance Expenses')
  return types.length ? types[types.length - 1] : null
})

function expenseTypeLabel(type) {
  const key = TYPE_LABELS[type];
  return key ? t('accounting.incomeStatement.' + key) : type
}

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
.is-subtotal-row td { font-weight: 700; border-top: 1px solid var(--zv-border); }
.is-net-income-row td { font-size: 1.0625rem; border-top: 2px solid var(--zv-border); }
.zv-summary-row .fr-sticky-col, .is-subtotal-row .fr-sticky-col, .is-net-income-row .fr-sticky-col { background: var(--zv-bg-surface); }
</style>
