<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/OpeningBalanceBody.vue

  Step 7 of 8 — Opening Balance. Confirmed design (spec Section 3.3):
  for an existing company, this is never a manual re-entry form —
  it's sourced live from the real Balance Sheet
  (PlanOpeningBalanceService, itself built on BalanceSheetService).
  CFO Tools' own equivalent step is a large manual entry form because
  it has no live GL to read from; this page intentionally looks
  nothing like that, since the whole point of building this inside
  the accounting engine is that there's nothing to re-type.

  Five KPI figures up top (the ones the calculation engine actually
  consumes), with an optional expand into the full, real Balance
  Sheet detail (same report Financial Reports > Balance Sheet
  produces) for anyone who wants to verify where the numbers came
  from — not summarized differently in two places.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.opening-balance / valuation.business-plan.opening-balance (shared)
  @props     plan: Object, figures: Object|null, report: Object|null,
             wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.openingBalance') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.openingBalance')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 3H9m3 3h.01M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 7, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="opening-balance" :completed-keys="completedKeys" />

        <!-- AUTO — sourced live from the real books -->
        <template v-if="plan.opening_balance_source === 'auto' && figures">
            <div class="zv-card" style="margin-top:16px">
                <div class="form-section-header">
                    <div>
                        <p class="form-section-title">{{ t('planning.wizard.pulledFromRealBooks') }}</p>
                        <p class="form-section-sub">{{ t('planning.wizard.asOfDate', { date: formatDate(dayBefore(plan.start_date)) }) }}</p>
                    </div>
                    <span class="zv-badge zv-badge-green">{{ t('planning.wizard.live') }}</span>
                </div>
                <div class="form-section-body">
                    <div class="ob-kpi-grid">
                        <div class="zv-kpi-card">
                            <span class="zv-kpi-label">{{ t('planning.wizard.cashAndBank') }}</span>
                            <span class="zv-kpi-value">{{ fmt(figures.cash_and_bank) }}</span>
                        </div>
                        <div class="zv-kpi-card">
                            <span class="zv-kpi-label">{{ t('planning.wizard.accountsReceivable') }}</span>
                            <span class="zv-kpi-value">{{ fmt(figures.accounts_receivable) }}</span>
                        </div>
                        <div class="zv-kpi-card">
                            <span class="zv-kpi-label">{{ t('planning.wizard.inventory') }}</span>
                            <span class="zv-kpi-value">{{ fmt(figures.inventory) }}</span>
                        </div>
                        <div class="zv-kpi-card">
                            <span class="zv-kpi-label">{{ t('planning.wizard.accountsPayable') }}</span>
                            <span class="zv-kpi-value">{{ fmt(figures.accounts_payable) }}</span>
                        </div>
                        <div class="zv-kpi-card zv-kpi-card-highlight">
                            <span class="zv-kpi-label">{{ t('planning.wizard.totalEquity') }}</span>
                            <span class="zv-kpi-value">{{ fmt(figures.total_equity) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Full Balance Sheet detail, collapsible -->
            <div class="zv-card" style="margin-top:16px">
                <button type="button" class="ob-toggle-btn" @click="showDetail = !showDetail">
                    <span>{{ t('planning.wizard.viewFullBalanceSheetDetail') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4" :class="{ 'ob-chevron-open': showDetail }">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div v-if="showDetail" class="form-section-body" style="padding-top:0">
                    <template v-for="group in ['assets', 'liabilities', 'equity']" :key="group">
                        <p class="ob-group-title">{{ t('planning.wizard.' + group) }}</p>
                        <div v-for="section in report[group].sections" :key="section.account_type" class="zv-table-wrap" style="margin-bottom:14px">
                            <table class="zv-table">
                                <thead><tr><th>{{ section.account_type }}</th><th class="zv-table-num">{{ t('planning.wizard.amount') }}</th></tr></thead>
                                <tbody>
                                    <tr v-for="row in section.rows" :key="row.id ?? row.name_en">
                                        <td>{{ row.code ? row.code + ' — ' : '' }}{{ row.name_en }}</td>
                                        <td class="zv-table-num">{{ fmt(row.periods.opening) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td style="font-weight:700">{{ t('planning.wizard.total') }} {{ section.account_type }}</td>
                                        <td class="zv-table-num" style="font-weight:700">{{ fmt(section.totals.opening) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>

                    <div class="ob-tie-out" :class="report.is_balanced?.opening ? 'ob-tie-out-ok' : 'ob-tie-out-bad'">
                        {{ report.is_balanced?.opening ? t('planning.wizard.balanceSheetTiesOut') : t('planning.wizard.balanceSheetDifference', { amount: fmt(report.difference?.opening) }) }}
                    </div>
                </div>
            </div>
        </template>

        <!-- MANUAL / NONE -->
        <div v-else class="zv-card" style="margin-top:16px">
            <EmptyState
                :title="plan.opening_balance_source === 'manual' ? t('planning.wizard.manualEntryTitle') : t('planning.wizard.noOpeningBalanceTitle')"
                :message="plan.opening_balance_source === 'manual' ? t('planning.wizard.manualEntryMessage') : t('planning.wizard.noOpeningBalanceMessage')"
            />
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps['fixed-assets']" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabFixedAssets') }}</Link>
            <Link :href="wizardSteps.results" class="zv-btn zv-btn-primary">{{ t('planning.wizard.viewResults') }}</Link>
        </div>
    </div>
</template>

<script setup>
import { addDaysIso } from '@/Utils/dates'
import { Head, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({ plan: Object, figures: Object, report: Object, wizardSteps: Object, routePrefix: { type: String, required: true } })

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
// This step has nothing to "complete" (it's read-only) — always
// considered done once reached, so Results is reachable next.
const completedKeys = computed(() => ['setup', 'sales', 'cogs', 'manpower', 'expenses', 'fixed-assets', 'opening-balance'])

const showDetail = ref(false)

function fmt(n) { return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function formatDate(d) { return d ? new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—' }
function dayBefore(dateStr) {
    return addDaysIso(dateStr, -1)
}
</script>

<style scoped>
.ob-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; }
.zv-kpi-card-highlight { background: var(--zv-green-light); border-color: var(--zv-green-border); }
.zv-kpi-card-highlight .zv-kpi-value { color: var(--zv-green-text); }
.ob-toggle-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: none; border: none; font-size: 13px; font-weight: 700; color: var(--zv-text-primary); cursor: pointer; }
.ob-toggle-btn:hover { background: var(--zv-bg-hover); }
.ob-chevron-open { transform: rotate(180deg); }
.ob-group-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--zv-text-muted); margin: 16px 0 8px; }
.ob-tie-out { margin-top: 10px; padding: 10px 14px; border-radius: var(--zv-radius); font-size: 12.5px; font-weight: 600; }
.ob-tie-out-ok { background: var(--zv-green-light); color: var(--zv-green-text); border: 1px solid var(--zv-green-border); }
.ob-tie-out-bad { background: var(--zv-danger-light); color: var(--zv-danger-text); border: 1px solid var(--zv-danger-border); }
</style>
