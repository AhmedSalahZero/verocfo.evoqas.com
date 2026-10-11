<!--
══════════════════════════════════════════════════════════════════
  Planning/AnnualPlan/Setup.vue

  Step 1 of 8 — Setup & Products, in one page. Matches two
  conventions at once, deliberately: CFO Tools' own Financial Study
  wizard puts Study Info and Products on the same first step (not
  two separate steps), and this app's own Rule 2 ("Create and Edit
  = one Vue page") — isEdit is derived from whether `plan` was
  passed in, same pattern every other Form.vue in this app already
  uses.

  Products here are managed inline (add/remove/set nature) — the
  monthly Sales/COGS detail for each product lives on the Sales and
  COGS step pages, not here, same separation CFO Tools uses.

  @layout    AppLayout (applied automatically)
  @route     planning.annual-plan.create (plan=null) /
             planning.annual-plan.edit (plan=Object) /
             .store / .update
  @props     currencies: Currency[], plan: Object|null,
             wizardSteps: Object|null (present only when editing)
══════════════════════════════════════════════════════════════════
-->
<template>
    <Head :title="isEdit ? plan.name : t('planning.annualPlanCreate.newAnnualPlan')" />
    <div class="zv-page">
        <PageHeader
            eyebrow="Planning"
            :title="isEdit ? plan.name : t('planning.annualPlanCreate.newAnnualPlan')"
            :subtitle="t('planning.annualPlanCreate.twelveMonthOperatingBudget')"
            icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m4 10v-4m4 4V9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
        />

        <WizardSteps v-if="isEdit" :steps="wizardStepsList" current-key="setup" :completed-keys="completedKeys" />

        <form class="zv-card" style="margin-top:16px" @submit.prevent="submit">
            <div class="form-section-header">
                <p class="form-section-title">{{ t('planning.wizard.studyInfo') }}</p>
            </div>
            <div class="form-section-body">
                <div class="field-grid field-grid-2">
                    <div class="field-group field-group-span-2">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.planName') }}</label>
                        <input v-model="form.name" type="text" class="zv-input" :class="{ 'zv-input-error': form.errors.name }" :placeholder="t('planning.annualPlanCreate.planNamePlaceholder')" />
                        <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.currency') }}</label>
                        <SelectBox
                            v-model="form.currency_id"
                            :options="currencies"
                            value-key="id"
                            :label-fn="c => c.code"
                            :placeholder="t('planning.annualPlanCreate.selectCurrency')"
                            :disabled="isEdit"
                        />
                        <p v-if="form.errors.currency_id" class="zv-field-error">{{ form.errors.currency_id }}</p>
                    </div>

                    <div class="field-group">
                        <label class="zv-label zv-label-required">{{ t('planning.annualPlanCreate.startDate') }}</label>
                        <input v-model="form.start_date" type="date" class="zv-input" />
                        <p v-if="form.errors.start_date" class="zv-field-error">{{ form.errors.start_date }}</p>
                        <p class="field-hint">{{ t('planning.annualPlanCreate.startDateHint') }}</p>
                    </div>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.businessType') }}</div>
                <div class="zv-tile-group" style="grid-template-columns: repeat(4, 1fr)">
                    <button v-for="opt in businessTypeOptions" :key="opt.value" type="button"
                        class="zv-tile" :class="{ 'zv-tile-active': form.business_type === opt.value }"
                        @click="form.business_type = opt.value"
                    >
                        <div class="zv-tile-label">{{ opt.label }}</div>
                    </button>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.corporateTaxRate') }}</div>
                <div class="field-grid field-grid-2">
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.annualPlanCreate.corporateTaxRate') }}</label>
                        <input v-model="form.corporate_tax_rate" type="number" step="0.01" min="0" max="100" class="zv-input" />
                        <p v-if="form.errors.corporate_tax_rate" class="zv-field-error">{{ form.errors.corporate_tax_rate }}</p>
                    </div>
                    <div class="field-group">
                        <label class="zv-label">{{ t('planning.annualPlanCreate.annualSalaryIncrease') }}</label>
                        <input v-model="form.manpower_annual_increase_pct" type="number" step="0.01" min="0" max="100" class="zv-input" />
                        <p class="field-hint">{{ t('planning.annualPlanCreate.annualSalaryIncreaseHint') }}</p>
                    </div>
                </div>

                <div class="field-divider" style="margin: 22px 0 16px">{{ t('planning.annualPlanCreate.openingBalance') }}</div>
                <div class="zv-tile-group" style="grid-template-columns: 1fr 1fr 1fr">
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'auto' }" @click="form.opening_balance_source = 'auto'">
                        <div class="zv-tile-label">{{ t('planning.annualPlanCreate.recommended') }}</div>
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceAuto') }}</div>
                    </button>
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'manual' }" @click="form.opening_balance_source = 'manual'">
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceManual') }}</div>
                    </button>
                    <button type="button" class="zv-tile" :class="{ 'zv-tile-active': form.opening_balance_source === 'none' }" @click="form.opening_balance_source = 'none'">
                        <div class="zv-tile-desc">{{ t('planning.annualPlanCreate.openingBalanceNone') }}</div>
                    </button>
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
                                :search-url="route('planning.annual-plan.products.search', { plan: plan.id })"
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
                <Link :href="route('planning.annual-plan.index')" class="zv-btn zv-btn-secondary">{{ t('planning.annualPlanCreate.cancel') }}</Link>
                <button type="submit" class="zv-btn zv-btn-primary" :disabled="form.processing">
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    {{ isEdit ? t('planning.wizard.saveAndNext') : t('planning.annualPlanCreate.createPlan') }}
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
// Setup itself is always "done" once we're on the edit page — the
// rest are only reachable once at least one product exists.
const completedKeys = computed(() => (props.plan?.products?.length ? ['setup'] : []))

const businessTypeOptions = computed(() => [
    { value: 'trading', label: t('planning.annualPlanCreate.trading') },
    { value: 'manufacturing', label: t('planning.annualPlanCreate.manufacturing') },
    { value: 'service', label: t('planning.annualPlanCreate.service') },
    { value: 'mixed', label: t('planning.annualPlanCreate.mixed') },
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
    business_type: props.plan?.business_type ?? 'trading',
    corporate_tax_rate: props.plan?.corporate_tax_rate ?? 22.5,
    manpower_annual_increase_pct: props.plan?.manpower_annual_increase_pct ?? 0,
    opening_balance_source: props.plan?.opening_balance_source ?? 'auto',
})

function submit() {
    if (isEdit.value) {
        form.put(route('planning.annual-plan.update', props.plan.id))
    } else {
        form.post(route('planning.annual-plan.store'))
    }
}

const productForm = { name: '', nature: 'trading', vat_rate: 0, wht_rate: 0 }
function addProduct() {
    router.post(route('planning.annual-plan.products.store', { plan: props.plan.id }), { ...productForm }, {
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
        const { data } = await window.axios.get(route('planning.annual-plan.products.preview', { plan: props.plan.id, product: productId }))
        productPreview.value = data
    } finally {
        previewLoading.value = false
    }
}

function addExistingProduct() {
    if (! pickedProductId.value) return
    router.post(route('planning.annual-plan.products.store', { plan: props.plan.id }), { product_id: pickedProductId.value }, {
        preserveScroll: true,
        onSuccess: () => { pickedProductId.value = null; pickedProductLabel.value = ''; productPreview.value = null },
    })
}

function removeProduct(p) {
    if (! confirm(t('planning.wizard.removeProductConfirm', { name: p.name }))) return
    router.delete(route('planning.annual-plan.products.destroy', { planProduct: p.id }), { preserveScroll: true })
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
