<!--
══════════════════════════════════════════════════════════════════
  Components/Planning/CogsBody.vue

  Step 3 of 8 — Cost of Goods Sold. One product at a time (tabs,
  same pattern as Sales), form fields change shape by nature —
  matching CFO Tools' own nature-conditional COGS step structure.

  Deliberately simpler than CFO Tools' manufacturing branch (which is
  a full multi-raw-material BOM engine) and its trading/service
  payment-policy modals — both are out of Phase 1 scope, confirmed
  and flagged already in PlanCogsCalculator's own header. This page
  shows exactly the fields that calculator actually uses, with a
  small formula banner (same idea as CFO Tools' own, honestly
  reflecting the simpler formula rather than implying a BOM exists).

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.cogs / valuation.business-plan.cogs (shared)
  @props     plan: Object, wizardSteps: Object
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="t('planning.wizard.tabCogs') + ' — ' + plan.name" />
    <div class="zv-page">
        <PageHeader eyebrow="Planning" :title="t('planning.wizard.costOfGoodsSold')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'>
            <template #subtitle>{{ plan.name }} · {{ t('planning.wizard.stepXOfY', { x: 3, y: 8 }) }}</template>
        </PageHeader>

        <WizardSteps :steps="wizardStepsList" current-key="cogs" :completed-keys="completedKeys" />

        <div v-if="!plan.products?.length" class="zv-card" style="margin-top:16px">
            <EmptyState :title="t('planning.wizard.noProductsFound')" :message="t('planning.wizard.goBackToSetupToAddProducts')" />
        </div>

        <div v-else class="zv-card" style="margin-top:16px; overflow:hidden">
            <div class="sales-tabs">
                <button v-for="(p, i) in plan.products" :key="p.id" type="button"
                    class="sales-tab" :class="{ 'sales-tab-active': activeIdx === i }" @click="activeIdx = i">
                    <span class="zv-status-dot" :class="natureDotClass(p.nature)"></span>
                    {{ p.name }}
                </button>
            </div>

            <div v-if="activeProduct" class="form-section-body">
                <div class="cogs-formula-banner">
                    <strong>{{ t('planning.wizard.formula') }}:</strong> {{ formulaText }}
                </div>

                <!-- MANUFACTURING -->
                <template v-if="activeProduct.nature === 'manufacturing'">
                    <div class="field-divider" style="margin:20px 0 16px">{{ t('planning.wizard.materialAndLaborCost') }}</div>
                    <div class="field-grid field-grid-3">
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.unitMaterialCost') }} ({{ plan.currency?.code }})</label>
                            <input v-model.number="form.unit_material_cost" type="number" min="0" step="0.0001" class="zv-input" />
                        </div>
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.unitLaborCost') }} ({{ plan.currency?.code }})</label>
                            <input v-model.number="form.unit_labor_cost" type="number" min="0" step="0.0001" class="zv-input" />
                        </div>
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.overheadPctOfLabor') }}</label>
                            <input v-model.number="form.overhead_pct_of_labor" type="number" min="0" step="0.01" class="zv-input" />
                        </div>
                    </div>
                    <div class="cogs-unit-cost-preview">
                        {{ t('planning.wizard.unitCostPreview') }}: <strong>{{ fmt(unitCostPreview) }} {{ plan.currency?.code }}</strong>
                    </div>
                </template>

                <!-- TRADING -->
                <template v-else-if="activeProduct.nature === 'trading'">
                    <div class="field-divider" style="margin:20px 0 16px">{{ t('planning.wizard.purchaseCost') }}</div>
                    <div class="field-grid field-grid-3">
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.unitPurchaseCost') }} ({{ plan.currency?.code }})</label>
                            <input v-model.number="form.unit_purchase_cost" type="number" min="0" step="0.0001" class="zv-input" />
                        </div>
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.inventoryCoverageDays') }}</label>
                            <input v-model.number="form.inventory_coverage_days" type="number" min="0" step="1" class="zv-input" />
                            <p class="field-hint">{{ t('planning.wizard.inventoryCoverageHint') }}</p>
                        </div>
                    </div>

                    <div class="field-divider" style="margin:22px 0 16px">{{ t('planning.wizard.beginningInventory') }}</div>
                    <div class="field-grid field-grid-2">
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.beginningInventoryUnits') }}</label>
                            <input v-model.number="form.beginning_inventory_units" type="number" min="0" step="0.0001" class="zv-input" />
                        </div>
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.beginningInventoryValue') }} ({{ plan.currency?.code }})</label>
                            <input v-model.number="form.beginning_inventory_value" type="number" min="0" step="0.01" class="zv-input" />
                        </div>
                    </div>
                </template>

                <!-- SERVICE -->
                <template v-else>
                    <div class="field-divider" style="margin:20px 0 16px">{{ t('planning.wizard.directCostOfService') }}</div>
                    <div class="field-grid field-grid-2">
                        <div class="field-group">
                            <label class="zv-label">{{ t('planning.wizard.directCostPct') }}</label>
                            <input v-model.number="form.direct_cost_pct" type="number" min="0" max="100" step="0.01" class="zv-input" />
                            <p class="field-hint">{{ t('planning.wizard.directCostPctHint') }}</p>
                        </div>
                    </div>
                </template>

                <div class="field-divider" style="margin:22px 0 16px">{{ t('planning.wizard.costGrowth') }}</div>
                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.wizard.annualCostIncreasePct') }}</label>
                        <input v-model.number="form.annual_cost_increase_pct" type="number" min="0" step="0.01" class="zv-input" />
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:20px">
                    <button type="button" class="zv-btn zv-btn-primary" :disabled="saving" @click="save">
                        <svg v-if="saving" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        {{ t('planning.wizard.saveCogsAssumption') }}
                    </button>
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:18px">
            <Link :href="wizardSteps.sales" class="zv-btn zv-btn-secondary">← {{ t('planning.wizard.tabSales') }}</Link>
            <Link :href="wizardSteps.manpower" class="zv-btn zv-btn-primary">{{ t('planning.wizard.saveAndNext') }}</Link>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, reactive, ref, watch } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
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
const hasAnyCogsSet = computed(() => props.plan.products?.some(p => hasCost(p.cogs_assumption)))
function hasCost(a) { return !!a && (Number(a.unit_material_cost) > 0 || Number(a.unit_purchase_cost) > 0 || Number(a.direct_cost_pct) > 0) }
const completedKeys = computed(() => ['setup', 'sales', ...(hasAnyCogsSet.value ? ['cogs'] : [])])

const activeIdx = ref(0)
const activeProduct = computed(() => props.plan.products?.[activeIdx.value] ?? null)

const form = reactive({
    unit_material_cost: 0, unit_labor_cost: 0, overhead_pct_of_labor: 0,
    unit_purchase_cost: 0, inventory_coverage_days: 30,
    direct_cost_pct: 0, annual_cost_increase_pct: 0,
    beginning_inventory_units: 0, beginning_inventory_value: 0,
})
watch(activeProduct, (p) => {
    const a = p?.cogs_assumption
    form.unit_material_cost = Number(a?.unit_material_cost ?? 0)
    form.unit_labor_cost = Number(a?.unit_labor_cost ?? 0)
    form.overhead_pct_of_labor = Number(a?.overhead_pct_of_labor ?? 0)
    form.unit_purchase_cost = Number(a?.unit_purchase_cost ?? 0)
    form.inventory_coverage_days = Number(a?.inventory_coverage_days ?? 30)
    form.direct_cost_pct = Number(a?.direct_cost_pct ?? 0)
    form.annual_cost_increase_pct = Number(a?.annual_cost_increase_pct ?? 0)
    form.beginning_inventory_units = Number(a?.beginning_inventory_units ?? 0)
    form.beginning_inventory_value = Number(a?.beginning_inventory_value ?? 0)
}, { immediate: true })

const unitCostPreview = computed(() => form.unit_material_cost + form.unit_labor_cost * (1 + form.overhead_pct_of_labor / 100))

const formulaText = computed(() => {
    if (! activeProduct.value) return ''
    return {
        manufacturing: t('planning.wizard.formulaManufacturing'),
        trading: t('planning.wizard.formulaTrading'),
        service: t('planning.wizard.formulaService'),
    }[activeProduct.value.nature] ?? ''
})

function natureDotClass(n) { return { manufacturing: 'zv-status-dot-blue', trading: 'zv-status-dot-amber', service: 'zv-status-dot-green' }[n] ?? 'zv-status-dot-gray' }
function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 4 }) }

const saving = ref(false)
function save() {
    saving.value = true
    router.post(route(`${props.routePrefix}.products.cogs-assumption.store`, { planProduct: activeProduct.value.id }), { ...form }, {
        preserveScroll: true, onFinish: () => { saving.value = false },
    })
}
</script>

<style scoped>
.sales-tabs { display: flex; overflow-x: auto; border-bottom: 1px solid var(--zv-border); background: var(--zv-bg-surface); }
.sales-tab { display: flex; align-items: center; gap: 8px; padding: 13px 18px; font-size: 13px; font-weight: 600; white-space: nowrap; border: none; background: none; border-bottom: 2px solid transparent; color: var(--zv-text-muted); cursor: pointer; }
.sales-tab:hover { color: var(--zv-text-primary); }
.sales-tab-active { border-bottom-color: var(--zv-blue); color: var(--zv-text-primary); background: var(--zv-bg-card); }
.cogs-formula-banner { background: var(--zv-bg-surface-alt); border: 1px solid var(--zv-border); border-radius: var(--zv-radius); padding: 10px 14px; font-size: 12.5px; color: var(--zv-text-secondary); }
.cogs-unit-cost-preview { margin-top: 14px; font-size: 13px; color: var(--zv-text-secondary); background: var(--zv-bg-surface-alt); border: 1px solid var(--zv-border); border-radius: var(--zv-radius); padding: 10px 14px; display: inline-block; }
</style>
