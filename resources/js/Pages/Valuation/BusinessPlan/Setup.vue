<!--
══════════════════════════════════════════════════════════════════
  Valuation/BusinessPlan/Setup.vue

  Step 1 of 8 — Setup & Products, in one page. Same structure as
  Planning/AnnualPlan/Setup.vue (merged Create/Edit, per this app's
  Rule 2, plus inline Products management matching CFO Tools' own
  single first step) — with the fields specific to this product:
  user-picked 3–10 year duration, WACC, perpetual growth rate, and
  the Multiplier Valuation basis + number, all required here since
  this product always produces a Valuation page.

  @layout    AppLayout (applied automatically)
  @route     valuation.business-plan.create (plan=null) /
             valuation.business-plan.edit (plan=Object) /
             .store / .update
  @props     currencies: Currency[], plan: Object|null,
             wizardSteps: Object|null (present only when editing)
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="isEdit ? plan.name : t('planning.businessPlanCreate.newBusinessPlan')" />
    <div class="zv-page">
        <PageHeader
            eyebrow="Valuation"
            :title="isEdit ? plan.name : t('planning.businessPlanCreate.newBusinessPlan')"
            :subtitle="t('planning.businessPlanCreate.threeToTenYearProjection')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 2l3 7h7l-5.5 4.5L18.5 21 12 16.5 5.5 21l2-7.5L2 9h7z"/>'
        />

        <WizardSteps v-if="isEdit" :steps="wizardStepsList" current-key="setup" :completed-keys="completedKeys" />

        <form class="zv-card" style="margin-top:16px" @submit.prevent="submit">
            <div class="form-section-header">
                <p class="form-section-title">{{ t('planning.businessPlanCreate.businessDetails') }}</p>
            </div>
            <div class="form-section-body">
                <div class="field-grid field-grid-2">
                    <div class="field-group field-group-span-2">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.planName') }}</label>
                        <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }" :placeholder="t('planning.businessPlanCreate.planNamePlaceholder')" />
                        <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.currency') }}</label>
                        <SelectBox
                            v-model="form.currency_id"
                            :options="currencies"
                            value-key="id"
                            :label-fn="c => c.code"
                            :placeholder="t('planning.businessPlanCreate.selectCurrency')"
                            :disabled="isEdit"
                        />
                        <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.startDate') }}</label>
                        <input v-model="form.start_date" type="date" class="zv-input" />
                        <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.durationYears') }}</label>
                        <select v-model.number="form.duration_years" class="zv-select" :disabled="isEdit">
                            <option v-for="y in [3,4,5,6,7,8,9,10]" :key="y" :value="y">{{ y }} {{ t('planning.businessPlanCreate.yearsSuffix') }}</option>
                        </select>
                        <p v-if="form.errors.duration_years" class="zv-field-error">{{ form.errors.duration_years }}</p>
                    </div>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.businessPlanCreate.businessType') }}</div>
                <div class="zv-tile-group" style="grid-template-columns: repeat(4, 1fr)">
                    <button v-for="opt in businessTypeOptions" :key="opt.value" type="button"
                        class="zv-tile" :class="{ 'zv-tile-active': form.business_type === opt.value }"
                        @click="form.business_type = opt.value"
                    >
                        <div class="zv-tile-label">{{ opt.label }}</div>
                    </button>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.businessPlanCreate.corporateTaxRate') }}</div>
                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.businessPlanCreate.corporateTaxRate') }}</label>
                        <input v-model.number="form.corporate_tax_rate" type="number" min="0" max="100" step="0.01" class="zv-input" />
                        <p v-if="form.errors.corporate_tax_rate" class="zv-field-error">{{ form.errors.corporate_tax_rate }}</p>
                    </div>
                </div>
            </div>

            <div class="form-section-header" style="border-top: 1px solid var(--zv-border)">
                <p class="form-section-title">{{ t('planning.businessPlanCreate.valuationAssumptions') }}</p>
            </div>
            <div class="form-section-body">
                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.wacc') }}</label>
                        <input v-model.number="form.wacc_pct" type="number" min="0" max="100" step="0.01" class="zv-input" />
                        <p v-if="form.errors.wacc_pct" class="zv-field-error">{{ form.errors.wacc_pct }}</p>
                    </div>
                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.perpetualGrowthRate') }}</label>
                        <input v-model.number="form.perpetual_growth_pct" type="number" min="0" max="20" step="0.01" class="zv-input" />
                        <p v-if="form.errors.perpetual_growth_pct" class="zv-field-error">{{ form.errors.perpetual_growth_pct }}</p>
                    </div>
                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.multiplierValuationBasis') }}</label>
                        <select v-model="form.valuation_basis" class="zv-select">
                            <option value="revenue">{{ t('planning.businessPlanCreate.revenue') }}</option>
                            <option value="ebitda">{{ t('planning.businessPlanCreate.ebitda') }}</option>
                            <option value="net_profit">{{ t('planning.businessPlanCreate.netProfit') }}</option>
                        </select>
                        <p v-if="form.errors.valuation_basis" class="zv-field-error">{{ form.errors.valuation_basis }}</p>
                    </div>
                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.businessPlanCreate.multiplier') }}</label>
                        <input v-model.number="form.valuation_multiplier" type="number" min="0" step="0.01" class="zv-input" :placeholder="t('planning.businessPlanCreate.multiplierPlaceholder')" />
                        <p v-if="form.errors.valuation_multiplier" class="zv-field-error">{{ form.errors.valuation_multiplier }}</p>
                        <p class="field-hint">{{ t('planning.businessPlanCreate.multiplierHint', { basis: basisLabel }) }}</p>
                    </div>
                </div>
            </div>

            <div class="form-section-header" style="border-top: 1px solid var(--zv-border)">
                <p class="form-section-title">{{ t('planning.businessPlanCreate.manpowerAndOpeningBalance') }}</p>
            </div>
            <div class="form-section-body">
                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.businessPlanCreate.annualSalaryIncrease') }}</label>
                        <input v-model.number="form.manpower_annual_increase_pct" type="number" min="0" max="100" step="0.01" class="zv-input" />
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.businessPlanCreate.openingBalance') }}</label>
                        <select v-model="form.opening_balance_source" class="zv-select">
                            <option value="auto">{{ t('planning.businessPlanCreate.openingBalanceAuto') }}</option>
                            <option value="manual">{{ t('planning.businessPlanCreate.openingBalanceManual') }}</option>
                            <option value="none">{{ t('planning.businessPlanCreate.openingBalanceNone') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Products (edit mode only — a product needs a real plan_id) ── -->
            <template v-if="isEdit">
                <div class="form-section-header" style="border-top: 1px solid var(--zv-border)">
                    <div>
                        <p class="form-section-title">{{ t('planning.wizard.products') }}</p>
                        <p class="form-section-sub">{{ t('planning.wizard.defineWhatThisPlanWillProject') }}</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div v-for="p in plan.products" :key="p.id" class="setup-product-row">
                        <div class="setup-product-row-header">
                            <span class="zv-badge" :class="natureBadgeClass(p.nature)">{{ natureIcon(p.nature) }} {{ p.nature }}</span>
                            <span class="setup-product-name">{{ p.name }}</span>
                            <span v-if="!p.product_id" class="zv-badge zv-badge-gray">{{ t('planning.wizard.planOnly') }}</span>
                            <button type="button" class="zv-btn zv-btn-danger zv-btn-sm" style="margin-inline-start:auto" @click="removeProduct(p)">{{ t('planning.wizard.remove') }}</button>
                        </div>
                    </div>

                    <div class="field-divider" style="margin: 18px 0 16px">{{ t('planning.wizard.addAProduct') }}</div>

                    <div class="setup-mode-toggle">
                        <button type="button" class="setup-mode-btn" :class="{ 'setup-mode-btn-active': addMode === 'existing' }" @click="addMode = 'existing'">{{ t('planning.wizard.existingProduct') }}</button>
                        <button type="button" class="setup-mode-btn" :class="{ 'setup-mode-btn-active': addMode === 'new' }" @click="addMode = 'new'">{{ t('planning.wizard.newProduct') }}</button>
                    </div>

                    <template v-if="addMode === 'existing'">
                        <div class="field-group" style="max-width:420px">
                            <label class="zv-label">{{ t('planning.wizard.searchCompanyProducts') }}</label>
                            <SearchableSelect
                                v-model="pickedProductId"
                                v-model:label="pickedProductLabel"
                                :search-url="route('valuation.business-plan.products.search', { plan: plan.id })"
                                :placeholder="t('planning.wizard.searchByCodeOrName')"
                                @update:modelValue="onProductPicked"
                            />
                        </div>

                        <div v-if="previewLoading" class="zv-text-muted" style="margin-top:10px">{{ t('common.loading') }}</div>
                        <div v-else-if="productPreview" class="setup-product-preview">
                            <span class="zv-badge" :class="natureBadgeClass(productPreview.nature)">{{ natureIcon(productPreview.nature) }} {{ productPreview.nature }}</span>
                            <span class="setup-product-name">{{ productPreview.name }}</span>
                            <span v-if="productPreview.uom_code" class="zv-text-muted">{{ productPreview.uom_code }}</span>
                            <span class="zv-text-muted">{{ t('planning.wizard.vatPct') }}: {{ productPreview.vat_rate }}%</span>
                            <span class="zv-text-muted">{{ t('planning.wizard.whtPct') }}: {{ productPreview.wht_rate }}%</span>
                            <span class="zv-badge zv-badge-gray">{{ t('planning.wizard.lockedToSettings') }}</span>
                        </div>

                        <button type="button" class="zv-btn zv-btn-secondary" style="margin-top:14px" :disabled="!pickedProductId" @click="addExistingProduct">{{ t('planning.wizard.addProduct') }}</button>
                    </template>

                    <template v-else>
                        <div class="zv-tile-group" style="grid-template-columns: repeat(3, 1fr); margin-bottom:16px">
                            <button v-for="opt in natureOptions" :key="opt.value" type="button"
                                class="zv-tile" :class="{ 'zv-tile-active': productForm.nature === opt.value }"
                                @click="productForm.nature = opt.value"
                            >
                                <div class="zv-tile-label">{{ opt.icon }} {{ opt.label }}</div>
                                <div class="zv-tile-desc">{{ opt.desc }}</div>
                            </button>
                        </div>
                        <div class="field-grid field-grid-3">
                            <div class="field-group">
                                <label class="zv-label">{{ t('planning.wizard.name') }}</label>
                                <input v-model="productForm.name" type="text" class="zv-input" :placeholder="t('planning.wizard.productNamePlaceholder')" />
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('planning.wizard.vatPct') }}</label>
                                <input v-model="productForm.vat_rate" type="number" step="0.01" min="0" max="100" class="zv-input" />
                            </div>
                            <div class="field-group">
                                <label class="zv-label">{{ t('planning.wizard.whtPct') }}</label>
                                <input v-model="productForm.wht_rate" type="number" step="0.01" min="0" max="100" class="zv-input" />
                            </div>
                        </div>
                        <button type="button" class="zv-btn zv-btn-secondary" style="margin-top:14px" @click="addProduct">{{ t('planning.wizard.addProduct') }}</button>
                    </template>
                </div>
            </template>

            <div class="zv-card-footer" style="display:flex; justify-content:flex-end; gap:10px">
                <Link :href="route('valuation.business-plan.index')" class="zv-btn zv-btn-secondary">{{ t('planning.businessPlanCreate.cancel') }}</Link>
                <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    {{ isEdit ? t('planning.wizard.saveAndNext') : t('planning.businessPlanCreate.createPlan') }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { computed, ref } from 'vue'
import PageHeader from '@/Components/PageHeader.vue'
import SelectBox from '@/Components/SelectBox.vue'
import SearchableSelect from '@/Components/SearchableSelect.vue'
import WizardSteps from '@/Components/Planning/WizardSteps.vue'

const { t } = useI18n()
const props = defineProps({
    currencies: Array,
    plan: { type: Object, default: null },
    wizardSteps: { type: Object, default: null },
})

const isEdit = computed(() => !!props.plan?.id)

const wizardStepsList = computed(() => [
    { key: 'setup', label: t('planning.wizard.tabSetup'), href: props.wizardSteps?.setup },
    { key: 'sales', label: t('planning.wizard.tabSales'), href: props.wizardSteps?.sales },
    { key: 'cogs', label: t('planning.wizard.tabCogs'), href: props.wizardSteps?.cogs },
    { key: 'manpower', label: t('planning.wizard.tabManpower'), href: props.wizardSteps?.manpower },
    { key: 'expenses', label: t('planning.wizard.tabExpenses'), href: props.wizardSteps?.expenses },
    { key: 'fixed-assets', label: t('planning.wizard.tabFixedAssets'), href: props.wizardSteps?.['fixed-assets'] },
    { key: 'opening-balance', label: t('planning.wizard.tabOpeningBalance'), href: props.wizardSteps?.['opening-balance'] },
    { key: 'results', label: t('planning.wizard.tabResults'), href: props.wizardSteps?.results },
])
const completedKeys = computed(() => (props.plan?.products?.length ? ['setup'] : []))

const businessTypeOptions = computed(() => [
    { value: 'trading', label: t('planning.businessPlanCreate.trading') },
    { value: 'manufacturing', label: t('planning.businessPlanCreate.manufacturing') },
    { value: 'service', label: t('planning.businessPlanCreate.service') },
    { value: 'mixed', label: t('planning.businessPlanCreate.mixed') },
])

const natureOptions = computed(() => [
    { value: 'manufacturing', icon: '🏭', label: t('planning.wizard.manufacturing'), desc: t('planning.wizard.producesGoodsFromRawMaterials') },
    { value: 'trading', icon: '🛒', label: t('planning.wizard.trading'), desc: t('planning.wizard.buysAndResellsFinishedGoods') },
    { value: 'service', icon: '⚙️', label: t('planning.wizard.service'), desc: t('planning.wizard.providesServicesNoPhysicalGoods') },
])

function natureIcon(nature) { return { manufacturing: '🏭', trading: '🛒', service: '⚙️' }[nature] ?? '' }
function natureBadgeClass(nature) { return { manufacturing: 'zv-badge-blue', trading: 'zv-badge-amber', service: 'zv-badge-green' }[nature] ?? 'zv-badge-gray' }

const form = useForm({
    name: props.plan?.name ?? '',
    currency_id: props.plan?.currency_id ?? (props.currencies?.[0]?.id ?? ''),
    start_date: props.plan?.start_date ?? todayLocal(),
    duration_years: props.plan?.duration_years ?? 5,
    business_type: props.plan?.business_type ?? 'trading',
    corporate_tax_rate: props.plan?.corporate_tax_rate ?? 22.5,
    wacc_pct: props.plan?.wacc_pct ?? 15,
    perpetual_growth_pct: props.plan?.perpetual_growth_pct ?? 3,
    valuation_basis: props.plan?.valuation_basis ?? 'ebitda',
    valuation_multiplier: props.plan?.valuation_multiplier ?? '',
    manpower_annual_increase_pct: props.plan?.manpower_annual_increase_pct ?? 0,
    opening_balance_source: props.plan?.opening_balance_source ?? 'auto',
})

const basisLabel = computed(() => ({
    revenue: t('planning.businessPlanCreate.revenue'),
    ebitda: t('planning.businessPlanCreate.ebitda'),
    net_profit: t('planning.businessPlanCreate.netProfit'),
}[form.valuation_basis] ?? ''))

function submit() {
    if (isEdit.value) {
        form.put(route('valuation.business-plan.update', props.plan.id))
    } else {
        form.post(route('valuation.business-plan.store'))
    }
}

const productForm = { name: '', nature: 'trading', vat_rate: 0, wht_rate: 0 }
function addProduct() {
    router.post(route('valuation.business-plan.products.store', { plan: props.plan.id }), { ...productForm }, {
        preserveScroll: true,
        onSuccess: () => { productForm.name = ''; productForm.vat_rate = 0; productForm.wht_rate = 0 },
    })
}

// ── Existing Product picker ──────────────────────────────────────
const addMode = ref('existing')
const pickedProductId = ref(null)
const pickedProductLabel = ref('')
const productPreview = ref(null)
const previewLoading = ref(false)

async function onProductPicked(productId) {
    if (! productId) { productPreview.value = null; return }
    previewLoading.value = true
    try {
        const { data } = await window.axios.get(route('valuation.business-plan.products.preview', { plan: props.plan.id, product: productId }))
        productPreview.value = data
    } finally {
        previewLoading.value = false
    }
}

function addExistingProduct() {
    if (! pickedProductId.value) return
    router.post(route('valuation.business-plan.products.store', { plan: props.plan.id }), { product_id: pickedProductId.value }, {
        preserveScroll: true,
        onSuccess: () => { pickedProductId.value = null; pickedProductLabel.value = ''; productPreview.value = null },
    })
}

function removeProduct(p) {
    if (! confirm(t('planning.wizard.removeProductConfirm', { name: p.name }))) return
    router.delete(route('valuation.business-plan.products.destroy', { planProduct: p.id }), { preserveScroll: true })
}
</script>

<style scoped>
.setup-product-row { padding: 10px 0; border-bottom: 1px solid var(--zv-border); }
.setup-product-row:last-of-type { border-bottom: none; }
.setup-product-row-header { display: flex; align-items: center; gap: 10px; }
.setup-product-name { font-size: 13px; font-weight: 600; color: var(--zv-text-primary); }
.setup-mode-toggle { display: flex; gap: 8px; margin-bottom: 16px; }
.setup-mode-btn { font-size: 12.5px; font-weight: 600; padding: 7px 16px; border-radius: 8px; background: var(--zv-bg-surface-alt); color: var(--zv-text-muted); }
.setup-mode-btn-active { background: var(--zv-navy, #0F2554); color: #fff; }
.setup-product-preview { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 14px; border-radius: 8px; background: var(--zv-bg-surface-alt); }
</style>
