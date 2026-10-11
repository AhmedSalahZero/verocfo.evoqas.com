<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/SalesBody.vue

  Step 2 of 8 — Sales Projection. Rebuilt to match CFO Tools' actual
  design: a horizontal spreadsheet-style grid (sticky "Row" column,
  one column per month, a Total column) with a "fill right" shortcut
  under the Price and Volume rows — click it once and every month to
  the right of that cell takes the same value, instead of typing the
  same number twelve times. Products are tabs across the top, each
  showing a colored nature dot and a checkmark once it has revenue.

  For a multi-year plan, one year's 12 months are shown at a time
  (a year selector above the grid) — the same grid, repeated per
  year, rather than trying to fit up to 120 months on screen at once.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.sales / valuation.business-plan.sales (shared)
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.salesProjection') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader
            eyebrow="Planning"
            :title="t('planning.wizard.salesProjection')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'
        >
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 2, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="sales" :completed-keys="completedKeys" />

        <div v-if="!plan.products?.length" class="zv-card" style="margin-top:16px">
            <EmptyState
                :title="t('planning.wizard.noProductsFound')"
                :message="t('planning.wizard.goBackToSetupToAddProducts')"
            />
        </div>

        <div v-else class="zv-card" style="margin-top:16px; overflow:hidden">
            <!-- Product tabs -->
            <div class="sales-tabs">
                <button v-for="(p, i) in plan.products" :key="p.id" type="button"
                    class="sales-tab" :class="{ 'sales-tab-active': activeProductIdx === i }"
                    @click="activeProductIdx = i"
                >
                    <span class="zv-status-dot" :class="natureDotClass(p.nature)"></span>
                    {{ p.name }}
                    <svg v-if="isProductComplete(p)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="w-3.5 h-3.5 sales-tab-check">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </button>
            </div>

            <div v-if="activeProduct" class="form-section-body">
                <div class="sales-meta-row">
                    <span class="zv-badge" :class="natureBadgeClass(activeProduct.nature)">{{ natureIcon(activeProduct.nature) }} {{ activeProduct.nature }}</span>
                    <span class="sales-meta-text">{{ plan.duration_years }}-{{ t('planning.wizard.yearProjection') }}</span>

                    <div v-if="plan.duration_years > 1" class="sales-year-picker">
                        <label class="zv-label" style="margin:0">{{ t('planning.wizard.year') }}</label>
                        <SelectBox
                            v-model="selectedYear"
                            :options="yearOptions"
                            value-key="value"
                            :label-fn="o => o.label"
                            style="width:140px"
                        />
                    </div>
                </div>

                <div class="sales-grid-header">
                    <div>
                        <div class="sales-year-chip">{{ t('planning.wizard.yearN', { n: selectedYear }) }}</div>
                        <p class="sales-grid-hint">{{ t('planning.wizard.hoverCellFillRight') }}</p>
                    </div>
                    <div class="sales-annual-total">
                        <span class="sales-annual-total-label">{{ t('planning.wizard.annualRevenue') }}</span>
                        <span class="sales-annual-total-value">{{ fmt(yearTotalRevenue) }} <span class="sales-annual-total-currency">{{ plan.currency?.code }}</span></span>
                    </div>
                </div>

                <div class="zv-table-wrap">
                    <table class="zv-table sales-grid-table">
                        <thead>
                            <tr>
                                <th class="sales-sticky-col">{{ t('planning.wizard.row') }}</th>
                                <th v-for="(m, i) in monthsInYear" :key="i" class="text-center">{{ monthLabels[i] }}</th>
                                <th class="zv-table-num">{{ t('planning.wizard.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Price row -->
                            <tr>
                                <td class="sales-sticky-col">
                                    <div class="sales-row-label">{{ t('planning.wizard.price') }}</div>
                                    <div class="sales-row-sublabel">{{ plan.currency?.code }}</div>
                                </td>
                                <td v-for="m in monthsInYear" :key="m.period_month" class="sales-input-cell">
                                    <input type="number" step="any" min="0" v-model.number="m.price" class="sales-cell-input" />
                                </td>
                                <td class="zv-table-num">{{ t('planning.wizard.avg') }} {{ fmt(avgPrice) }}</td>
                            </tr>
                            <!-- Fill-right row -->
                            <tr class="sales-fill-row">
                                <td class="sales-sticky-col"></td>
                                <td v-for="(m, i) in monthsInYear" :key="'fp'+m.period_month" class="sales-input-cell">
                                    <button v-if="i < monthsInYear.length - 1" type="button" class="sales-fill-btn" :title="t('planning.wizard.copyValueRight')" @click="fillRight('price', i)">
                                        <span>···</span>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-2.5 h-2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </td>
                                <td></td>
                            </tr>

                            <!-- Volume row -->
                            <tr>
                                <td class="sales-sticky-col">
                                    <div class="sales-row-label">{{ t('planning.wizard.volume') }}</div>
                                    <div class="sales-row-sublabel">{{ activeProduct.uom_code || t('planning.wizard.units') }}</div>
                                </td>
                                <td v-for="m in monthsInYear" :key="m.period_month" class="sales-input-cell">
                                    <input type="number" step="any" min="0" v-model.number="m.volume" class="sales-cell-input" />
                                </td>
                                <td class="zv-table-num">{{ fmt(totalVolume) }}</td>
                            </tr>
                            <!-- Fill-right row -->
                            <tr class="sales-fill-row">
                                <td class="sales-sticky-col"></td>
                                <td v-for="(m, i) in monthsInYear" :key="'fv'+m.period_month" class="sales-input-cell">
                                    <button v-if="i < monthsInYear.length - 1" type="button" class="sales-fill-btn" :title="t('planning.wizard.copyValueRight')" @click="fillRight('volume', i)">
                                        <span>···</span>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-2.5 h-2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </td>
                                <td></td>
                            </tr>

                            <!-- Revenue row (read-only) -->
                            <tr class="sales-revenue-row">
                                <td class="sales-sticky-col"><div class="sales-row-label">{{ t('planning.wizard.revenue') }}</div></td>
                                <td v-for="m in monthsInYear" :key="'r'+m.period_month" class="zv-table-num">{{ fmt(m.price * m.volume) }}</td>
                                <td class="zv-table-num" style="font-weight:700">{{ fmt(yearTotalRevenue) }}</td>
                            </tr>

                            <!-- Local / Export split -->
                            <tr>
                                <td class="sales-sticky-col"><div class="sales-row-label">{{ t('planning.wizard.localPct') }}</div></td>
                                <td v-for="m in monthsInYear" :key="'l'+m.period_month" class="sales-input-cell">
                                    <input type="number" step="0.01" min="0" max="100" v-model.number="m.local_pct" class="sales-cell-input" @change="m.export_pct = 100 - m.local_pct" />
                                </td>
                                <td></td>
                            </tr>
                            <tr>
                                <td class="sales-sticky-col"><div class="sales-row-label">{{ t('planning.wizard.exportPct') }}</div></td>
                                <td v-for="m in monthsInYear" :key="'e'+m.period_month" class="sales-input-cell">
                                    <input type="number" step="0.01" min="0" max="100" v-model.number="m.export_pct" class="sales-cell-input" @change="m.local_pct = 100 - m.export_pct" />
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:14px">
                    <button type="button" class="zv-btn zv-btn-primary" :disabled="saving" @click="saveYear">
                        <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        {{ t('planning.wizard.saveYearN', { n: selectedYear }) }}
                    </button>
                </div>

                <!-- ── Collection Policy ── -->
                <div class="field-divider" style="margin: 26px 0 16px">{{ t('planning.wizard.collectionPolicy') }}</div>
                <div class="sales-tranche-grid">
                    <div v-for="market in ['local', 'export']" :key="market" class="sales-tranche-box">
                        <p class="sales-tranche-title">{{ market === 'local' ? t('planning.wizard.localSales') : t('planning.wizard.exportSales') }}</p>
                        <div v-for="(tr, idx) in tranches[market]" :key="idx" class="sales-tranche-row">
                            <input v-model.number="tr.pct" type="number" step="0.01" min="0" max="100" class="sales-cell-input" style="width:70px" />
                            <span class="sales-meta-text">% {{ t('planning.wizard.pctCollected') }}</span>
                            <input v-model.number="tr.days_after_sale" type="number" step="1" min="0" class="sales-cell-input" style="width:70px" />
                            <span class="sales-meta-text">{{ t('planning.wizard.daysAfterSale') }}</span>
                            <button type="button" class="zv-btn-icon zv-btn-ghost" @click="tranches[market].splice(idx, 1)">×</button>
                        </div>
                        <button type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="tranches[market].push({ pct: 0, days_after_sale: 0 })">+ {{ t('planning.wizard.addTranche') }}</button>
                        <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" style="margin-inline-start:10px" @click="saveTranches(market)">{{ t('planning.wizard.savePolicyFor', { market }) }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps.setup" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.backToSetup') }}</Link>
            <Link :href="wizardSteps.cogs" class="zv-btn zv-btn-primary">{{ t('planning.wizard.saveAndNext') }}</Link>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, reactive, ref, watch } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import EmptyState from '@/Components/EmptyState.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({ plan: Object, wizardSteps: Object, routePrefix: { type: String, required: true } })

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
const completedKeys = computed(() => ['setup', ...(props.plan.products?.some(isProductComplete) ? ['sales'] : [])])

const monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
const activeProductIdx = ref(0)
const activeProduct = computed(() => props.plan.products?.[activeProductIdx.value] ?? null)

const yearOptions = computed(() => Array.from({ length: props.plan.duration_years }, (_, i) => ({ value: i + 1, label: t('planning.wizard.yearN', { n: i + 1 }) })))
const selectedYear = ref(1)

// Local editable copy of the current product+year's 12 months —
// reactive, watched, and posted back on "Save Year N".
const monthsInYear = reactive([])
function loadMonthsForYear() {
    monthsInYear.length = 0
    if (! activeProduct.value) return
    const start = (selectedYear.value - 1) * 12
    const byMonth = new Map((activeProduct.value.sales_lines ?? []).map(l => [l.period_month, l]))
    for (let m = start; m < start + 12; m++) {
        const existing = byMonth.get(m)
        monthsInYear.push({
            period_month: m,
            price: Number(existing?.price ?? 0),
            volume: Number(existing?.volume ?? 0),
            local_pct: Number(existing?.local_pct ?? 100),
            export_pct: Number(existing?.export_pct ?? 0),
        })
    }
}
watch([activeProductIdx, selectedYear], loadMonthsForYear, { immediate: true })

const totalVolume = computed(() => monthsInYear.reduce((s, m) => s + (m.volume || 0), 0))
const yearTotalRevenue = computed(() => monthsInYear.reduce((s, m) => s + (m.price || 0) * (m.volume || 0), 0))
const avgPrice = computed(() => totalVolume.value ? yearTotalRevenue.value / totalVolume.value : 0)

function fillRight(field, fromIndex) {
    const val = monthsInYear[fromIndex][field]
    for (let i = fromIndex + 1; i < monthsInYear.length; i++) monthsInYear[i][field] = val
}

function isProductComplete(p) {
    return (p.sales_lines ?? []).some(l => Number(l.price) > 0 && Number(l.volume) > 0)
}

function natureDotClass(n) { return { manufacturing: 'zv-status-dot-blue', trading: 'zv-status-dot-amber', service: 'zv-status-dot-green' }[n] ?? 'zv-status-dot-gray' }
function natureBadgeClass(n) { return { manufacturing: 'zv-badge-blue', trading: 'zv-badge-amber', service: 'zv-badge-green' }[n] ?? 'zv-badge-gray' }
function natureIcon(n) { return { manufacturing: '🏭', trading: '🛒', service: '⚙️' }[n] ?? '' }

function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) }

const saving = ref(false)
function saveYear() {
    saving.value = true
    router.post(route(`${props.routePrefix}.products.sales-lines.store`, { planProduct: activeProduct.value.id }), {
        lines: monthsInYear.map(m => ({ period_month: m.period_month, price: m.price, volume: m.volume, local_pct: m.local_pct, export_pct: m.export_pct })),
    }, { preserveScroll: true, onFinish: () => { saving.value = false } })
}

const tranches = reactive({ local: [], export: [] })
watch(activeProduct, (p) => {
    tranches.local = (p?.collection_tranches ?? []).filter(x => x.market === 'local').map(x => ({ pct: x.pct, days_after_sale: x.days_after_sale }))
    tranches.export = (p?.collection_tranches ?? []).filter(x => x.market === 'export').map(x => ({ pct: x.pct, days_after_sale: x.days_after_sale }))
}, { immediate: true })

function saveTranches(market) {
    router.post(route(`${props.routePrefix}.products.collection-tranches.store`, { planProduct: activeProduct.value.id }), {
        market, tranches: tranches[market],
    }, { preserveScroll: true })
}
</script>

<style scoped>
.sales-tabs { display: flex; overflow-x: auto; border-bottom: 1px solid var(--zv-border); background: var(--zv-bg-surface); }
.sales-tab { display: flex; align-items: center; gap: 8px; padding: 13px 18px; font-size: 13px; font-weight: 600; white-space: nowrap; border: none; background: none; border-bottom: 2px solid transparent; color: var(--zv-text-muted); cursor: pointer; }
.sales-tab:hover { color: var(--zv-text-primary); }
.sales-tab-active { border-bottom-color: var(--zv-blue); color: var(--zv-text-primary); background: var(--zv-bg-card); }
.sales-tab-check { color: var(--zv-green); }
.sales-meta-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.sales-meta-text { font-size: 12px; color: var(--zv-text-muted); }
.sales-year-picker { display: flex; align-items: center; gap: 8px; margin-inline-start: auto; }
.sales-grid-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
.sales-year-chip { font-size: 13px; font-weight: 700; color: var(--zv-text-primary); }
.sales-grid-hint { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 2px; }
.sales-annual-total { display: flex; align-items: center; gap: 8px; background: var(--zv-green-light); border: 1px solid var(--zv-green-border); padding: 6px 14px; border-radius: 10px; }
.sales-annual-total-label { font-size: 12px; color: var(--zv-green-text); }
.sales-annual-total-value { font-size: 15px; font-weight: 700; color: var(--zv-green-text); font-variant-numeric: tabular-nums; }
.sales-annual-total-currency { font-size: 11px; font-weight: 400; }
.sales-grid-table { min-width: 1100px; }
.sales-sticky-col { position: sticky; left: 0; background: var(--zv-bg-card); z-index: 1; min-width: 110px; }
.sales-grid-table thead th.sales-sticky-col { background: var(--zv-brand-navy); }
.sales-row-label { font-size: 12px; font-weight: 600; color: var(--zv-text-primary); }
.sales-row-sublabel { font-size: 10.5px; color: var(--zv-text-muted); }
.sales-input-cell { padding: 4px 5px !important; }
.sales-cell-input { width: 100%; padding: 6px 7px; border: 1px solid var(--zv-border); border-radius: 6px; font-size: 12px; text-align: end; font-variant-numeric: tabular-nums; background: var(--zv-bg-input); color: var(--zv-text-primary); }
.sales-cell-input:hover { border-color: var(--zv-blue-border); }
.sales-cell-input:focus { outline: none; border-color: var(--zv-border-focus); box-shadow: var(--zv-shadow-focus); }
.sales-fill-row td { padding: 1px 5px !important; border-bottom: none !important; }
.sales-fill-btn { width: 100%; display: flex; align-items: center; justify-content: center; gap: 1px; background: none; border: none; color: var(--zv-text-muted); font-size: 11px; font-weight: 700; border-radius: 4px; cursor: pointer; padding: 1px 0; }
.sales-fill-btn:hover { color: #fff; background: var(--zv-blue); }
.sales-revenue-row { background: var(--zv-green-light); }
.sales-tranche-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.sales-tranche-box { border: 1px solid var(--zv-border); border-radius: var(--zv-radius-lg); padding: 14px; }
.sales-tranche-title { font-size: 13px; font-weight: 700; color: var(--zv-text-primary); margin: 0 0 10px; }
.sales-tranche-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
@media (max-width: 700px) { .sales-tranche-grid { grid-template-columns: 1fr; } }
</style>
