<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/ResultsBody.vue

  Step 8 of 8 — Results (and Valuation, when present). Shared by
  Annual Plan and Business Plan & Valuation (Decision 1) — the two
  thin page wrappers (Pages/Planning/AnnualPlan/Results.vue and
  Pages/Valuation/BusinessPlan/Results.vue) just pass plan/results/
  wizardSteps/routePrefix through to this component. The Valuation
  section renders automatically whenever results.valuation is present
  (Annual Plan's results never have it — PlanCalculationService only
  computes it for Business Plan) — no separate page needed.

  Matches CFO Tools' own ResultsStep.vue structure: KPI summary cards,
  a granularity toggle (Annual/Monthly), and full P&L / Cash Flow /
  Balance Sheet tables with a sticky first column. Left out
  deliberately: CFO Tools' "Manual Overrides" panel (this engine has
  no override mechanism) and its Excel/PDF export buttons (a real
  future addition, not built this pass).

  @layout    — (plain component — hosted inside a thin Results.vue
              page per product, which has AppLayout automatically)
  @props     plan: Object, results: Object, wizardSteps: Object,
             routePrefix: String
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.results')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 8, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="results" :completed-keys="completedKeys" />

        <div style="display:flex; justify-content:flex-end; margin:16px 0 8px">
            <Link :href="route(routePrefix + '.results', { plan: plan.id, refresh: 1 })" class="zv-btn zv-btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                {{ t('planning.wizard.recalculate') }}
            </Link>
        </div>

        <!-- ── KPI Summary ── -->
        <div class="zv-kpi-grid-4">
            <div class="zv-kpi-card">
                <span class="zv-kpi-label">{{ t('planning.wizard.totalRevenue') }}</span>
                <span class="zv-kpi-value">{{ fmt(totalRevenue) }}</span>
            </div>
            <div class="zv-kpi-card" :class="totalNetProfit >= 0 ? 'zv-kpi-card-positive' : 'zv-kpi-card-negative'">
                <span class="zv-kpi-label">{{ t('planning.wizard.totalNetProfit') }}</span>
                <span class="zv-kpi-value">{{ fmt(totalNetProfit) }}</span>
            </div>
            <div class="zv-kpi-card">
                <span class="zv-kpi-label">{{ t('planning.wizard.requiredEquityInjection') }}</span>
                <span class="zv-kpi-value">{{ fmt(results.required_equity_injection) }}</span>
            </div>
            <div class="zv-kpi-card">
                <span class="zv-kpi-label">{{ t('planning.wizard.balanceSheetStatus') }}</span>
                <span class="zv-kpi-value" :class="allBalanced ? 'zv-text-success' : 'zv-text-danger'">
                    {{ allBalanced ? t('planning.wizard.balanced') : t('planning.wizard.mismatch') }}
                </span>
                <!-- PLN-002 — name the failing months and the size of
                     the gap. A bare "Mismatch" gives nobody anything to
                     act on, and this figure feeds the valuation. -->
                <span v-if="!allBalanced" class="zv-kpi-sub zv-text-danger">
                    {{ unbalancedMonths.length }} / {{ results.balance_check?.months_checked }}
                    &middot; {{ fmt(results.balance_check?.worst_difference) }}
                </span>
            </div>
        </div>

        <!-- ── Valuation (Business Plan only) ── -->
        <!-- M-27: the planning engine is a simplified model. Say so, plainly. -->
        <div class="zv-card" style="margin-top:20px; border-left:4px solid #FFBF00">
            <div class="form-section-header"><p class="form-section-title">ℹ️ How to read these results</p></div>
            <div style="padding:12px 16px; font-size:13px; line-height:1.6">
                <p style="margin:0 0 6px">This plan is a simplified planning model. Please keep these limits in mind before relying on the figures:</p>
                <ul style="margin:0; padding-inline-start:18px">
                    <li>Inventory is held at a flat level through the year (it does not follow sales seasonality).</li>
                    <li>Suppliers are assumed to be paid with no credit period.</li>
                    <li>The balance sheet is balanced by a computed adjustment line, so small differences are absorbed there.</li>
                    <li>Income tax is charged once, in month 12, and losses are not carried forward to later years.</li>
                    <li>The valuation (DCF) does not include changes in working capital.</li>
                </ul>
            </div>
        </div>

        <div v-if="results.valuation" class="zv-card" style="margin-top:20px">
            <div class="form-section-header"><p class="form-section-title">💰 {{ t('planning.wizard.valuation') }}</p></div>
            <div class="form-section-body">
                <p class="ob-group-title">{{ t('planning.wizard.dcf') }}</p>
                <div class="zv-kpi-grid-3">
                    <div class="zv-kpi-card"><span class="zv-kpi-label">{{ t('planning.wizard.npv') }}</span><span class="zv-kpi-value">{{ fmt(results.valuation.dcf.npv) }}</span></div>
                    <div class="zv-kpi-card zv-kpi-card-highlight"><span class="zv-kpi-label">{{ t('planning.wizard.irr') }}</span><span class="zv-kpi-value">{{ results.valuation.dcf.irr_pct }}%</span></div>
                    <div class="zv-kpi-card"><span class="zv-kpi-label">{{ t('planning.wizard.terminalValue') }}</span><span class="zv-kpi-value">{{ fmt(results.valuation.dcf.terminal_value) }}</span></div>
                    <div class="zv-kpi-card"><span class="zv-kpi-label">{{ t('planning.wizard.moic') }}</span><span class="zv-kpi-value">{{ results.valuation.dcf.moic }}x</span></div>
                    <div class="zv-kpi-card"><span class="zv-kpi-label">{{ t('planning.wizard.paybackMonths') }}</span><span class="zv-kpi-value">{{ results.valuation.dcf.payback_months ?? '—' }}</span></div>
                    <div class="zv-kpi-card"><span class="zv-kpi-label">{{ t('planning.wizard.breakEvenMonth') }}</span><span class="zv-kpi-value">{{ results.valuation.dcf.break_even_months ?? '—' }}</span></div>
                </div>
                <p class="field-hint" style="margin-top:10px">
                    {{ t('planning.wizard.dcfParameters', { wacc: results.valuation.dcf.wacc_pct, growth: results.valuation.dcf.perpetual_growth_pct, investment: fmt(results.valuation.dcf.total_investment) }) }}
                </p>

                <p class="ob-group-title" style="margin-top:20px">{{ t('planning.wizard.multiplierValuation') }}</p>
                <div class="zv-kpi-grid-3">
                    <div class="zv-kpi-card zv-kpi-card-positive">
                        <span class="zv-kpi-label">{{ t('planning.wizard.year1BasisTimesMultiplier', { basis: basisLabel(results.valuation.valuation_basis), multiplier: results.valuation.valuation_multiplier }) }}</span>
                        <span class="zv-kpi-value">{{ fmt(results.valuation.multiplier_value) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Granularity toggle ── -->
        <div class="ob-toggle-row">
            <div class="zv-tile-group ob-granularity-toggle">
                <button type="button" class="zv-tile" :class="{ 'zv-tile-active': granularity === 'annual' }" @click="granularity = 'annual'">
                    <span class="zv-tile-label">{{ t('planning.wizard.annual') }}</span>
                </button>
                <button type="button" class="zv-tile" :class="{ 'zv-tile-active': granularity === 'monthly' }" @click="granularity = 'monthly'">
                    <span class="zv-tile-label">{{ t('planning.wizard.monthly') }}</span>
                </button>
            </div>
        </div>

        <!-- ── P&L ── -->
        <div class="zv-card" style="margin-top:14px">
            <div class="form-section-header"><p class="form-section-title">{{ t('planning.wizard.incomeStatement') }}</p></div>
            <div class="zv-table-wrap">
                <table class="zv-table results-table">
                    <thead><tr><th class="sales-sticky-col">{{ t('planning.wizard.row') }}</th><th v-for="c in columns" :key="c.key" class="text-center">{{ c.label }}</th></tr></thead>
                    <tbody>
                        <tr v-for="line in plLines" :key="line.key" :class="{ 'results-subtotal-row': line.bold }">
                            <td class="sales-sticky-col" :style="line.bold ? 'font-weight:700' : ''">{{ line.label }}</td>
                            <td v-for="c in columns" :key="c.key" class="zv-table-num" :style="line.bold ? 'font-weight:700' : ''">{{ fmt(rowValue('pl', c.key, line.key)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Cash Flow ── -->
        <div class="zv-card" style="margin-top:14px">
            <div class="form-section-header"><p class="form-section-title">{{ t('planning.wizard.cashFlowStatement') }}</p></div>
            <div class="zv-table-wrap">
                <table class="zv-table results-table">
                    <thead><tr><th class="sales-sticky-col">{{ t('planning.wizard.row') }}</th><th v-for="c in columns" :key="c.key" class="text-center">{{ c.label }}</th></tr></thead>
                    <tbody>
                        <tr>
                            <td class="sales-sticky-col">{{ t('planning.wizard.netCashFlow') }}</td>
                            <td v-for="c in columns" :key="c.key" class="zv-table-num">{{ fmt(rowValue('cf', c.key, 'net_cash_flow')) }}</td>
                        </tr>
                        <tr>
                            <td class="sales-sticky-col">{{ t('planning.wizard.capexPaid') }}</td>
                            <td v-for="c in columns" :key="c.key" class="zv-table-num">{{ fmt(rowValue('cf', c.key, 'capex_paid')) }}</td>
                        </tr>
                        <tr class="results-subtotal-row">
                            <td class="sales-sticky-col" style="font-weight:700">{{ t('planning.wizard.cashBalance') }}</td>
                            <td v-for="c in columns" :key="c.key" class="zv-table-num" style="font-weight:700">{{ fmt(rowValue('cf', c.key, granularity === 'annual' ? 'cash_balance_end' : 'cash_balance')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Balance Sheet ── -->
        <div class="zv-card" style="margin-top:14px">
            <div class="form-section-header"><p class="form-section-title">{{ t('planning.wizard.balanceSheet') }}</p></div>
            <div class="zv-table-wrap">
                <table class="zv-table results-table">
                    <thead><tr><th class="sales-sticky-col">{{ t('planning.wizard.row') }}</th><th v-for="c in columns" :key="c.key" class="text-center">{{ c.label }}</th></tr></thead>
                    <tbody>
                        <tr v-for="line in bsLines" :key="line.key" :class="{ 'results-subtotal-row': line.bold }">
                            <td class="sales-sticky-col" :style="line.bold ? 'font-weight:700' : ''">{{ line.label }}</td>
                            <td v-for="c in columns" :key="c.key" class="zv-table-num" :style="line.bold ? 'font-weight:700' : ''">{{ fmt(rowValue('bs', c.key, line.key)) }}</td>
                        </tr>
                        <tr>
                            <td class="sales-sticky-col">{{ t('planning.wizard.tieOut') }}</td>
                            <td v-for="c in columns" :key="c.key" class="text-center">
                                <span class="zv-status-dot" :class="rowValue('bs', c.key, 'is_balanced') ? 'zv-status-dot-green' : 'zv-status-dot-danger'"></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-start; margin-top:18px">
            <Link :href="wizardSteps['opening-balance']" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabOpeningBalance') }}</Link>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({
    plan: { type: Object, required: true },
    results: { type: Object, required: true },
    wizardSteps: { type: Object, required: true },
    routePrefix: { type: String, required: true },
})

const wizardStepsList = computed(() => [
    { key: 'setup', label: t('planning.wizard.tabSetup'), href: props.wizardSteps.setup },
    { key: 'sales', label: t('planning.wizard.tabSales'), href: props.wizardSteps.sales },
    { key: 'cogs', label: t('planning.wizard.tabCogs'), href: props.wizardSteps.cogs },
    { key: 'manpower', label: t('planning.wizard.tabManpower'), href: props.wizardSteps.manpower },
    { key: 'expenses', label: t('planning.wizard.tabExpenses'), href: props.wizardSteps.expenses },
    { key: 'fixed-assets', label: t('planning.wizard.tabFixedAssets'), href: props.wizardSteps['fixed-assets'] },
    { key: 'opening-balance', label: t('planning.wizard.tabOpeningBalance'), href: props.wizardSteps['opening-balance'] },
    { key: 'results', label: t('planning.wizard.tabResults'), href: props.wizardSteps.results },
])
const completedKeys = computed(() => ['setup', 'sales', 'cogs', 'manpower', 'expenses', 'fixed-assets', 'opening-balance', 'results'])

const granularity = ref('annual')

const monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
function calendarLabel(monthIndex) {
    const start = new Date(props.plan.start_date)
    const d = new Date(start.getFullYear(), start.getMonth() + monthIndex, 1)
    return `${monthLabels[d.getMonth()]} '${String(d.getFullYear()).slice(2)}`
}

const columns = computed(() => {
    if (granularity.value === 'annual') {
        return Array.from({ length: props.plan.duration_years }, (_, y) => ({ key: y, label: t('planning.wizard.yearN', { n: y + 1 }) }))
    }
    return Array.from({ length: props.plan.duration_years * 12 }, (_, m) => ({ key: m, label: calendarLabel(m) }))
})

function rowValue(statement, colKey, field) {
    const source = granularity.value === 'annual' ? props.results.annual[statement] : props.results[statement]
    const row = source?.[colKey]
    if (!row) return '0.00'
    if (field === 'is_balanced') return !!row.is_balanced
    return row[field] ?? '0.00'
}

const plLines = computed(() => [
    { key: 'revenue', label: t('planning.wizard.revenue') },
    { key: 'cogs', label: t('planning.wizard.costOfGoodsSold') },
    { key: 'grossProfit', label: t('planning.wizard.grossProfit'), bold: true },
    { key: 'opex', label: t('planning.wizard.operatingExpenses') },
    { key: 'ebitda', label: t('planning.wizard.ebitda'), bold: true },
    { key: 'depreciation', label: t('planning.wizard.depreciationAndAmortization') },
    { key: 'ebit', label: t('planning.wizard.ebit') },
    { key: 'tax', label: t('planning.wizard.corporateTax') },
    { key: 'netProfit', label: t('planning.wizard.netProfit'), bold: true },
])

const bsLines = computed(() => [
    { key: 'cash', label: t('planning.wizard.cashAndBank') },
    { key: 'accounts_receivable', label: t('planning.wizard.accountsReceivable') },
    { key: 'net_fixed_assets', label: t('planning.wizard.netFixedAssets') },
    { key: 'prepaid_expenses', label: t('planning.wizard.prepaidExpenses') },
    { key: 'total_assets', label: t('planning.wizard.totalAssets'), bold: true },
    { key: 'total_liabilities', label: t('planning.wizard.totalLiabilities') },
    { key: 'total_equity', label: t('planning.wizard.totalEquityLine'), bold: true },
])

const totalRevenue = computed(() => Object.values(props.results.annual.pl).reduce((s, y) => s + Number(y.revenue || 0), 0))
const totalNetProfit = computed(() => Object.values(props.results.annual.pl).reduce((s, y) => s + Number(y.netProfit || 0), 0))
// PLN-002 fix (2026-09) — reads the every-month verdict the engine
// now returns, not annual.bs, which holds only each year's LAST month
// and so showed "Balanced" while eleven other months were out.
// Falls back to the old annual check if an older cached result is
// still being displayed.
const allBalanced = computed(() =>
    props.results.balance_check
        ? props.results.balance_check.all_balanced
        : Object.values(props.results.annual.bs).every(y => y?.is_balanced))

const unbalancedMonths = computed(() => props.results.balance_check?.unbalanced_months ?? [])

function basisLabel(b) { return { revenue: t('planning.wizard.revenue'), ebitda: t('planning.wizard.ebitda'), net_profit: t('planning.wizard.netProfit') }[b] ?? b }
function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) }
</script>

<style scoped>
.zv-kpi-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-top: 4px; }
.zv-kpi-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.zv-kpi-card-positive { background: var(--zv-green-light); border-color: var(--zv-green-border); }
.zv-kpi-card-positive .zv-kpi-value { color: var(--zv-green-text); }
.zv-kpi-card-negative { background: var(--zv-danger-light); border-color: var(--zv-danger-border); }
.zv-kpi-card-negative .zv-kpi-value { color: var(--zv-danger-text); }
.zv-kpi-card-highlight { background: var(--zv-green-light); border-color: var(--zv-green-border); }
.zv-kpi-card-highlight .zv-kpi-value { color: var(--zv-green-text); }
.zv-text-success { color: var(--zv-green-text); }
.zv-kpi-sub { display: block; font-size: 12px; margin-top: 2px; }
.ob-group-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--zv-text-muted); margin: 0 0 10px; }
.ob-toggle-row { display: flex; justify-content: flex-end; margin-top: 20px; }
.ob-granularity-toggle { grid-template-columns: repeat(2, 1fr); width: 220px; }
.sales-sticky-col { position: sticky; left: 0; background: var(--zv-bg-card); z-index: 1; min-width: 160px; }
.results-table thead th.sales-sticky-col { background: var(--zv-brand-navy); }
.results-table { min-width: 900px; }
.results-subtotal-row { background: var(--zv-bg-surface-alt); }
</style>
